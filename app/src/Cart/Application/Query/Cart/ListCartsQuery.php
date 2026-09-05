<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Cart;

use Siroko\Cart\Domain\Exception\InvalidCartStatusException;
use Siroko\Cart\Domain\Exception\InvalidCustomerIdException;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\CustomerId;

/**
 * One page of carts: the caller's own when the API authenticates, every cart
 * otherwise; optionally narrowed to one status.
 */
final class ListCartsQuery
{
    public const MAX_PAGE_SIZE = 100;

    /**
     * @var positive-int
     */
    public readonly int $pageNumber;

    /**
     * @var positive-int
     */
    public readonly int $pageSize;

    private readonly ?CustomerId $customerId;

    private readonly ?CartStatus $status;

    /**
     * @throws InvalidCustomerIdException
     * @throws InvalidCartStatusException when the status is not one a cart has
     */
    public function __construct(?string $customerId, int $pageNumber, int $pageSize, ?int $status = null)
    {
        if ($pageNumber < 1) {
            throw new \InvalidArgumentException(\sprintf('Page numbers start at 1, got %d.', $pageNumber));
        }

        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(\sprintf('Page size must be between 1 and %d, got %d.', self::MAX_PAGE_SIZE, $pageSize));
        }

        $this->customerId = null === $customerId ? null : CustomerId::fromString($customerId);
        $this->pageNumber = $pageNumber;
        $this->pageSize = $pageSize;
        $this->status = null === $status ? null : new CartStatus($status);
    }

    public function customer(): ?CustomerId
    {
        return $this->customerId;
    }

    public function status(): ?CartStatus
    {
        return $this->status;
    }
}
