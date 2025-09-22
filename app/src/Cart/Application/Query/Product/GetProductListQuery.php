<?php

namespace Siroko\Cart\Application\Query\Product;

class GetProductListQuery
{
    /**
     * @param int $pageNumber
     * @param int $pageSize
     */
    public function __construct(
        public readonly int $pageNumber,
        public readonly int $pageSize,
    ) {
    }
}
