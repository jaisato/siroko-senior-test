<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Cart;

use Siroko\Cart\Domain\Entity\Cart;

/**
 * One page of carts, with the same pagination fields as the product list.
 */
final class CartReadCollection
{
    /**
     * @param list<CartRead> $carts
     * @param positive-int   $page
     * @param positive-int   $pageSize
     * @param int<0, max>    $total    carts matching, across every page
     * @param int<0, max>    $pages
     */
    public function __construct(
        public readonly array $carts = [],
        public readonly int $page = 1,
        public readonly int $pageSize = 1,
        public readonly int $total = 0,
        public readonly int $pages = 0,
    ) {}

    /**
     * @param iterable<Cart> $carts
     * @param positive-int   $page
     * @param positive-int   $pageSize
     * @param int<0, max>    $total
     */
    public static function fromModels(iterable $carts, int $page, int $pageSize, int $total): self
    {
        $read = [];

        foreach ($carts as $cart) {
            $read[] = CartRead::fromModel($cart);
        }

        return new self($read, $page, $pageSize, $total, max(0, (int) ceil($total / $pageSize)));
    }
}
