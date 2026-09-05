<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Product;

final class GetProductListQuery
{
    /**
     * Largest page a single request may ask for. `?pageSize=1000000` used to
     * pull the whole table into memory and serialise it in one response.
     */
    public const MAX_PAGE_SIZE = 100;

    /**
     * @var positive-int
     */
    public readonly int $pageNumber;

    /**
     * @var positive-int
     */
    public readonly int $pageSize;

    /**
     * The bounds are enforced once, here, where the query is built. The
     * controller clamps what the client sent to these bounds before building
     * the query, and the repository trusts them, so there is exactly one place
     * that says what a valid page is.
     */
    public function __construct(int $pageNumber, int $pageSize)
    {
        if ($pageNumber < 1) {
            throw new \InvalidArgumentException(\sprintf('Page numbers start at 1, got %d.', $pageNumber));
        }

        if ($pageSize < 1 || $pageSize > self::MAX_PAGE_SIZE) {
            throw new \InvalidArgumentException(\sprintf('Page size must be between 1 and %d, got %d.', self::MAX_PAGE_SIZE, $pageSize));
        }

        $this->pageNumber = $pageNumber;
        $this->pageSize = $pageSize;
    }
}
