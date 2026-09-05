<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Product;

use Siroko\Cart\Domain\Entity\Product;

/**
 * One page of the product catalogue.
 *
 * `$products` is initialised: it used to be declared without a default, so a
 * page with no rows serialised as `{}` instead of `{"products": []}`, and a
 * client iterating the list broke on the one response it should have handled
 * trivially. The pagination fields let a client know how far the list goes
 * without asking for pages until one comes back empty.
 */
final class ProductReadCollection
{
    /**
     * @param list<ProductRead> $products
     * @param positive-int      $page
     * @param positive-int      $pageSize
     * @param int<0, max>       $total    products in the whole catalogue
     * @param int<0, max>       $pages    pages needed to list them all at this page size
     */
    public function __construct(
        public readonly array $products = [],
        public readonly int $page = 1,
        public readonly int $pageSize = 1,
        public readonly int $total = 0,
        public readonly int $pages = 0,
    ) {}

    /**
     * @param iterable<Product> $products the rows of the requested page
     * @param positive-int      $page
     * @param positive-int      $pageSize
     * @param int<0, max>       $total
     */
    public static function fromArray(iterable $products, int $page = 1, int $pageSize = 1, int $total = 0): self
    {
        $read = [];

        foreach ($products as $product) {
            $read[] = ProductRead::fromModel($product);
        }

        return new self($read, $page, $pageSize, $total, max(0, (int) ceil($total / $pageSize)));
    }
}
