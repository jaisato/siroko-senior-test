<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Application\Query\Cart;

use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Application\Query\Cart\ListCartsQuery;
use Siroko\Cart\Application\Query\Cart\ListCartsQueryHandler;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\CustomerId;

final class ListCartsQueryHandlerTest extends TestCase
{
    /** @var array{owner: ?string, status: ?int, page: int, size: int}|null */
    private ?array $asked = null;

    public function test_it_lists_a_page_of_the_callers_carts(): void
    {
        $alice = CustomerId::fromString('alice');
        $carts = [new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending(), null, null, $alice)];

        $collection = (new ListCartsQueryHandler($this->carts($carts, total: 5)))(new ListCartsQuery('alice', 2, 2, CartStatus::PENDING));

        self::assertSame(['owner' => 'alice', 'status' => CartStatus::PENDING, 'page' => 2, 'size' => 2], $this->asked);
        self::assertCount(1, $collection->carts);
        self::assertSame('alice', $collection->carts[0]->customerId);
        self::assertSame(2, $collection->page);
        self::assertSame(2, $collection->pageSize);
        self::assertSame(5, $collection->total);
        self::assertSame(3, $collection->pages);
    }

    /** No caller (authentication off): every cart, whatever its owner. */
    public function test_without_a_caller_nothing_is_filtered_by_owner(): void
    {
        $collection = (new ListCartsQueryHandler($this->carts([], total: 0)))(new ListCartsQuery(null, 1, 20));

        self::assertSame(['owner' => null, 'status' => null, 'page' => 1, 'size' => 20], $this->asked);
        self::assertSame([], $collection->carts);
        self::assertSame(0, $collection->pages);
        self::assertSame('{"carts":[],"page":1,"pageSize":20,"total":0,"pages":0}', json_encode($collection, \JSON_THROW_ON_ERROR));
    }

    public function test_the_query_validates_its_bounds_and_values(): void
    {
        self::assertSame(ListCartsQuery::MAX_PAGE_SIZE, (new ListCartsQuery(null, 1, ListCartsQuery::MAX_PAGE_SIZE))->pageSize);

        foreach ([[0, 10], [1, 0], [1, ListCartsQuery::MAX_PAGE_SIZE + 1]] as [$page, $size]) {
            try {
                new ListCartsQuery(null, $page, $size);
                self::fail(\sprintf('page %d / size %d must be refused', $page, $size));
            } catch (\InvalidArgumentException) {
            }
        }

        try {
            new ListCartsQuery(null, 1, 10, 9);
            self::fail('expected an exception');
        } catch (InvalidCartStatusException) {
        }

        $this->expectException(InvalidCustomerIdException::class);

        new ListCartsQuery('not valid', 1, 10);
    }

    /**
     * @param list<Cart> $page
     */
    private function carts(array $page, int $total): CartRepository
    {
        $carts = $this->createStub(CartRepository::class);
        $carts->method('search')->willReturnCallback(function (?CustomerId $owner, ?CartStatus $status, int $pageNumber, int $pageSize) use ($page): array {
            $this->asked = ['owner' => $owner?->toString(), 'status' => $status?->toInt(), 'page' => $pageNumber, 'size' => $pageSize];

            return $page;
        });
        $carts->method('countMatching')->willReturn($total);

        return $carts;
    }
}
