<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\DeleteCartItemCommand;
use Siroko\Cart\Application\Command\Cart\DeleteCartItemCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Exception\CartItemNotFoundException;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidIdentifierException;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;

/**
 * Adding a product reserves a unit off its stock. Removing the item has to give
 * that unit back, or every add/remove cycle destroys one unit of inventory and
 * the product eventually reports itself out of stock with nothing sold.
 */
final class DeleteCartItemCommandHandlerTest extends TestCase
{
    /** @var list<array{0:string,1:int}> stock credited, as (productId, units) */
    private array $returned = [];

    /** @var list<string> rows locked, in the order the handler asked for them */
    private array $locked = [];

    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->returned = [];
        $this->locked = [];
        $this->session = new RecordingSession();
    }

    public function test_removing_an_item_returns_its_reserved_unit_to_the_product(): void
    {
        $product = $this->product(quantity: 4);
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product);

        $handler = $this->handler($cart, $item);

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame([[$product->id()->toString(), 1]], $this->returned);
    }

    /**
     * A delete aimed at a cart that does not own the item must not credit any
     * stock - otherwise a repeated or mistargeted request mints inventory,
     * which is the same bug pointing the other way. From the point of view of
     * the cart named in the request, that line does not exist.
     */
    public function test_an_item_belonging_to_another_cart_is_not_found_and_credits_nothing(): void
    {
        $product = $this->product(quantity: 4);
        $otherCart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($otherCart, $product);

        $targetCart = $this->cart(CartStatus::PENDING);

        $handler = $this->handler($targetCart, $item);

        try {
            $handler(new DeleteCartItemCommand($targetCart->id()->toString(), $item->id()->toString()));
            self::fail('expected an exception');
        } catch (CartItemNotFoundException) {
        }

        self::assertSame([], $this->returned);
        self::assertNotContains('removeItem', $this->session->log);
    }

    /**
     * Once a cart is paid the unit is sold, not reserved, so it is not ours to
     * return - and the line is not ours to remove either.
     */
    public function test_a_paid_cart_is_a_conflict_and_nothing_moves(): void
    {
        $product = $this->product(quantity: 4);
        $cart = $this->cart(CartStatus::PAID);
        $item = $this->itemIn($cart, $product);

        $handler = $this->handler($cart, $item);

        try {
            $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));
            self::fail('expected an exception');
        } catch (InvalidCartStatusException) {
        }

        self::assertSame([], $this->returned);
        self::assertNotContains('removeItem', $this->session->log);
        self::assertSame(['cart:' . $cart->id()->toString()], $this->locked, 'the status is decided before the line is even loaded');
    }

    /** 204 for an unknown item made a typo indistinguishable from a removal. */
    public function test_an_unknown_item_is_not_found(): void
    {
        $cart = $this->cart(CartStatus::PENDING);

        $handler = $this->handler($cart, null);

        try {
            $handler(new DeleteCartItemCommand($cart->id()->toString(), Uuid::uuid4()->toString()));
            self::fail('expected an exception');
        } catch (CartItemNotFoundException) {
        }

        self::assertSame([], $this->returned);
        self::assertNotContains('removeItem', $this->session->log);
    }

    public function test_an_unknown_cart_is_not_found(): void
    {
        $product = $this->product(quantity: 4);
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product);

        $handler = $this->handler(null, $item);

        try {
            $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));
            self::fail('expected an exception');
        } catch (CartNotFoundException) {
        }

        self::assertSame([], $this->returned);
        self::assertSame(['cart:' . $cart->id()->toString()], $this->locked, 'no line is looked up without a cart');
    }

    /**
     * Dos DELETE simultáneos de la misma línea leían los dos que estaba ahí y
     * pendiente, así que los dos devolvían stock: el segundo borrado ya no
     * afectaba a ninguna fila y su incremento se confirmaba igual. La línea se
     * carga bloqueando su fila para que la segunda transacción la vea ya
     * borrada.
     */
    public function test_the_item_row_is_locked_before_its_stock_is_returned(): void
    {
        $product = $this->product(quantity: 4);
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product);

        $handler = $this->handler($cart, $item);

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertContains('item:' . $item->id()->toString(), $this->locked);
    }

    /**
     * El estado que decide si se devuelve stock se lee del carrito bloqueado, y
     * el carrito se bloquea antes que la línea.
     *
     * Bloquear sólo la línea no serializaba nada frente al checkout, que ni
     * siquiera la mira: los dos podían leer el carrito pendiente a la vez, el
     * checkout confirmar un carrito pagado que todavía contenía la línea, y
     * este handler devolver después al stock una unidad ya vendida.
     *
     * El orden -carrito y luego línea- es lo que evita el interbloqueo, porque
     * es el mismo que toma cualquier otra operación sobre los dos.
     */
    public function test_the_cart_row_is_locked_before_the_item_row(): void
    {
        $product = $this->product(quantity: 4);
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product);

        $handler = $this->handler($cart, $item);

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame(
            ['cart:' . $cart->id()->toString(), 'item:' . $item->id()->toString()],
            $this->locked,
            'the cart lock is taken first, then the item lock',
        );
    }

    /**
     * Si el carrito bloqueado ya está pagado, no se devuelve stock aunque la
     * línea siga apuntando en memoria a un carrito pendiente: quien manda es la
     * lectura hecha bajo el bloqueo, que es la que ve el checkout confirmado.
     */
    public function test_the_status_comes_from_the_locked_cart_not_from_the_item(): void
    {
        $product = $this->product(quantity: 4);
        $staleCart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($staleCart, $product);

        // Misma identidad, estado ya pagado: es lo que devuelve la lectura
        // bloqueada cuando el checkout ha ganado la carrera.
        $lockedCart = new Cart($staleCart->id(), CartStatus::paid());

        $handler = $this->handler($lockedCart, $item);

        $this->expectException(InvalidCartStatusException::class);

        try {
            $handler(new DeleteCartItemCommand($staleCart->id()->toString(), $item->id()->toString()));
        } finally {
            self::assertSame([], $this->returned);
        }
    }

    /**
     * Las dos escrituras tienen que ir juntas: cada repositorio hace su propio
     * flush y el bus de escritura no abre transacción, así que sin envolverlas
     * un fallo al borrar la línea dejaba la unidad ya devuelta.
     */
    public function test_both_writes_happen_inside_one_transaction(): void
    {
        $product = $this->product(quantity: 4);
        $cart = $this->cart(CartStatus::PENDING);
        $item = $this->itemIn($cart, $product);

        $handler = $this->handler($cart, $item);

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame(1, $this->session->transactions, 'exactly one transaction was opened');
        self::assertSame(
            ['begin', 'returnStock', 'removeItem', 'commit'],
            $this->session->log,
            'both writes happened between begin and commit',
        );
    }

    public function test_the_command_validates_its_identifiers(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new DeleteCartItemCommand(Uuid::uuid4()->toString(), 'item-1');
    }

    private function cart(int $status): Cart
    {
        return new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus($status));
    }

    private function itemIn(Cart $cart, Product $product): CartItem
    {
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        // A paid cart refuses new lines, so the line is attached while pending
        // and the status is set afterwards, as it would have happened in life.
        if ($cart->isPending()) {
            $cart->addItem($item);
        } else {
            $item->setCart($cart);
        }

        return $item;
    }

    private function product(int $quantity): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString('ABC123'),
            Name::fromString('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity($quantity),
        );
    }

    private function handler(?Cart $lockedCart, ?CartItem $lockedItem): DeleteCartItemCommandHandler
    {
        return new DeleteCartItemCommandHandler(
            $this->carts($lockedCart),
            $this->items($lockedItem),
            $this->recordingProducts(),
            $this->session,
        );
    }

    private function items(?CartItem $item): CartItemRepository
    {
        $items = $this->createStub(CartItemRepository::class);
        $items->method('ofIdForUpdate')->willReturnCallback(
            function (ItemId $id) use ($item): ?CartItem {
                $this->locked[] = 'item:' . $id->toString();

                return $item;
            },
        );
        // El camino sin bloqueo no debe usarse.
        $items->method('ofId')->willReturnCallback(
            static fn() => self::fail('the item must be loaded with its row locked'),
        );

        return $items;
    }

    private function carts(?Cart $cart): CartRepository
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('ofIdForUpdate')->willReturnCallback(
            function (CartId $id) use ($cart): ?Cart {
                $this->locked[] = 'cart:' . $id->toString();

                return $cart;
            },
        );
        $carts->method('ofId')->willReturnCallback(
            static fn() => self::fail('the cart must be loaded with its row locked'),
        );
        $carts->method('removeItem')->willReturnCallback(
            function (): void {
                $this->session->log[] = 'removeItem';
            },
        );

        return $carts;
    }

    /**
     * Records every returnStock() call. Stock now comes back through an atomic
     * repository call rather than a read-modify-write on the entity, so the
     * assertions are on the call, not on the in-memory Product.
     */
    private function recordingProducts(): ProductRepository
    {
        $products = $this->createStub(ProductRepository::class);
        $products->method('returnStock')->willReturnCallback(
            function (ProductId $id, int $units): void {
                $this->returned[] = [$id->toString(), $units];
                $this->session->log[] = 'returnStock';
            },
        );

        return $products;
    }
}
