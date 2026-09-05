<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Exception\OutOfStockException;
use Siroko\Cart\Domain\Exception\ProductNotFoundException;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

final class CreateCartCommandHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
        private readonly CartItemRepository $cartItemRepository,
        private readonly ProductRepository $productRepository,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * @throws ProductNotFoundException
     * @throws OutOfStockException
     */
    public function __invoke(CreateCartCommand $command): CartRead
    {
        $cart = new Cart(
            $this->cartRepository->nextIdentity(),
            CartStatus::pending(),
        );

        // Igual que en AddCartProductCommandHandler: la reserva es un ajuste
        // relativo y condicional, no un `setQuantity()` con un valor absoluto
        // sacado de la lectura de arriba. Un valor absoluto borra cualquier
        // devolución de stock que confirme otra petición entre la lectura y la
        // escritura, y comprobar el stock aparte de restarlo deja que dos
        // peticiones simultáneas pasen las dos la comprobación.
        //
        // Todo va en una transacción: reservar varios productos y guardar el
        // carrito son una sola operación, y si falla a medias no puede quedar
        // stock reservado sin carrito que lo justifique.
        $this->session->executeAtomically(function () use ($cart, $command): void {
            foreach ($this->inLockOrder($command->getItems()) as $item) {
                $product = $this->productRepository->ofId($item['productId']);

                // `ofId()` devuelve null para un id que no existe, y la
                // anotación `@var Product` no lo impedía: la siguiente línea
                // llamaba a `id()` sobre null y el cliente recibía un 500 por
                // haber pedido un producto inexistente.
                if (null === $product) {
                    throw ProductNotFoundException::withId($item['productId']);
                }

                $units = $item['quantity']->asInt();

                // The command refuses lines below MIN_ORDERED_QUANTITY; the
                // stock movement contract (positive-int) relies on it.
                if ($units < CreateCartCommand::MIN_ORDERED_QUANTITY) {
                    throw new \LogicException('A cart line always asks for at least one unit; CreateCartCommand guarantees it.');
                }

                if (!$this->productRepository->reserveStock($product->id(), $units)) {
                    throw new OutOfStockException(\sprintf('Product %s does not have %d units available', $product->id()->toString(), $units));
                }

                for ($i = 0; $i < $units; ++$i) {
                    $cart->addItem(
                        new CartItem(
                            $this->cartItemRepository->nextIdentity(),
                            $product,
                        ),
                    );
                }
            }

            $this->cartRepository->save($cart);
        });

        return CartRead::fromModel($cart);
    }

    /**
     * Ordena las líneas por id de producto, que es el orden en el que se toman
     * los cerrojos de fila.
     *
     * Reservando en el orden en que llegan en la petición, dos altas de carrito
     * con los mismos productos en orden contrario se interbloqueaban: cada
     * transacción bloqueaba su primer producto y esperaba al que tenía la otra.
     * MySQL aborta una de las dos, y el bus de escritura no reintenta, así que
     * una petición perfectamente válida devolvía un 500.
     *
     * Que todas las transacciones recorran los productos en el mismo orden
     * elimina el ciclo de espera: la que llega segunda espera a la primera y
     * sigue. Cuál sea ese orden da igual mientras sea el mismo para todas; el
     * id sirve y no depende de nada externo. Líneas repetidas del mismo
     * producto quedan juntas, y volver a bloquear una fila que ya tiene esta
     * misma transacción no cuesta nada.
     *
     * @param list<array{productId: ProductId, quantity: Quantity}> $items
     *
     * @return list<array{productId: ProductId, quantity: Quantity}>
     */
    private function inLockOrder(array $items): array
    {
        usort(
            $items,
            static fn(array $a, array $b): int => strcmp(
                $a['productId']->toString(),
                $b['productId']->toString(),
            ),
        );

        return $items;
    }
}
