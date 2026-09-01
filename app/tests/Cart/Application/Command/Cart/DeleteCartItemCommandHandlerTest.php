<?php

namespace Siroko\Tests\Cart\Application\Command\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Command\Cart\DeleteCartItemCommand;
use Siroko\Cart\Application\Command\Cart\DeleteCartItemCommandHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\Repository\CartItemRepository;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\Repository\ProductRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;
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

    private RecordingSession $session;

    protected function setUp(): void
    {
        $this->returned = [];
        $this->session = new RecordingSession();
    }

    public function test_removing_an_item_returns_its_reserved_unit_to_the_product(): void
    {
        $product = $this->product(quantity: 4);
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus(CartStatus::PENDING));
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        $cart->addItem($item);

        $handler = new DeleteCartItemCommandHandler(
            $this->expectsRemoval(),
            $this->itemRepositoryReturning($item),
            $this->recordingProducts(),
            $this->session,
        );

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame([[$product->id()->toString(), 1]], $this->returned);
    }

    /**
     * A delete aimed at a cart that does not own the item must not credit any
     * stock - otherwise a repeated or mistargeted request mints inventory,
     * which is the same bug pointing the other way.
     */
    public function test_an_item_belonging_to_another_cart_does_not_credit_stock(): void
    {
        $product = $this->product(quantity: 4);
        $otherCart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus(CartStatus::PENDING));
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        $otherCart->addItem($item);

        $handler = new DeleteCartItemCommandHandler(
            $this->expectsRemoval(),
            $this->itemRepositoryReturning($item),
            $this->recordingProducts(),
            $this->session,
        );

        $handler(new DeleteCartItemCommand(Uuid::uuid4()->toString(), $item->id()->toString()));

        self::assertSame([], $this->returned);
    }

    /** Once a cart is paid the unit is sold, not reserved, so it is not ours to return. */
    public function test_a_paid_cart_does_not_credit_stock(): void
    {
        $product = $this->product(quantity: 4);
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus(CartStatus::PAID));
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        $cart->addItem($item);

        $handler = new DeleteCartItemCommandHandler(
            $this->expectsRemoval(),
            $this->itemRepositoryReturning($item),
            $this->recordingProducts(),
            $this->session,
        );

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame([], $this->returned);
    }

    public function test_an_unknown_item_is_a_no_op_for_stock(): void
    {
        $items = $this->createStub(CartItemRepository::class);
        $items->method('ofIdForUpdate')->willReturn(null);

        $handler = new DeleteCartItemCommandHandler(
            $this->expectsRemoval(),
            $items,
            $this->recordingProducts(),
            $this->session,
        );

        $handler(new DeleteCartItemCommand(Uuid::uuid4()->toString(), Uuid::uuid4()->toString()));

        self::assertSame([], $this->returned);
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
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus(CartStatus::PENDING));
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        $cart->addItem($item);

        $locked = [];
        $items = $this->createStub(CartItemRepository::class);
        $items->method('ofIdForUpdate')->willReturnCallback(
            function ($id) use ($item, &$locked) {
                $locked[] = $id->toString();

                return $item;
            }
        );
        // El camino sin bloqueo no debe usarse.
        $items->method('ofId')->willReturnCallback(
            static fn () => self::fail('the item must be loaded with its row locked')
        );

        $handler = new DeleteCartItemCommandHandler(
            $this->expectsRemoval(),
            $items,
            $this->recordingProducts(),
            $this->session,
        );

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame([$item->id()->toString()], $locked);
    }

    /**
     * Las dos escrituras tienen que ir juntas: cada repositorio hace su propio
     * flush y el bus de escritura no abre transacción, así que sin envolverlas
     * un fallo al borrar la línea dejaba la unidad ya devuelta.
     */
    public function test_both_writes_happen_inside_one_transaction(): void
    {
        $product = $this->product(quantity: 4);
        $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus(CartStatus::PENDING));
        $item = new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product);
        $cart->addItem($item);

        $handler = new DeleteCartItemCommandHandler(
            $this->expectsRemoval(),
            $this->itemRepositoryReturning($item),
            $this->recordingProducts(),
            $this->session,
        );

        $handler(new DeleteCartItemCommand($cart->id()->toString(), $item->id()->toString()));

        self::assertSame(1, $this->session->transactions, 'exactly one transaction was opened');
        self::assertSame(
            ['begin', 'returnStock', 'removeItem', 'commit'],
            $this->session->log,
            'both writes happened between begin and commit',
        );
    }

    private function product(int $quantity): Product
    {
        return new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            new ProductCode('ABC123'),
            new Name('A product'),
            Price::of('10.00', 'EUR'),
            new Quantity($quantity),
        );
    }

    private function itemRepositoryReturning(CartItem $item): CartItemRepository
    {
        $items = $this->createStub(CartItemRepository::class);
        $items->method('ofIdForUpdate')->willReturn($item);

        return $items;
    }

    private function expectsRemoval(): CartRepository
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('removeItem')->willReturnCallback(
            function (): void {
                $this->session->log[] = 'removeItem';
            }
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
            function ($id, int $units): void {
                $this->returned[] = [$id->toString(), $units];
                $this->session->log[] = 'returnStock';
            }
        );

        return $products;
    }
}

/**
 * Stands in for the Doctrine session, recording that the handler asked for one
 * transaction and that both writes happened inside it.
 */
final class RecordingSession implements TransactionalSession
{
    public int $transactions = 0;

    /** @var list<string> */
    public array $log = [];

    public function executeAtomically(callable $operation): mixed
    {
        $this->transactions++;
        $this->log[] = 'begin';

        $result = $operation();

        $this->log[] = 'commit';

        return $result;
    }
}
