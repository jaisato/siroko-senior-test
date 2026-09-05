<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Cart;

use Siroko\Cart\Application\Dto\Cart\CartReadCollection;
use Siroko\Cart\Domain\Repository\CartRepository;

final class ListCartsQueryHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
    ) {}

    /**
     * With a caller, only that caller's carts are listed - not the ownerless
     * ones, which are readable by id but are nobody's. Without one (the API
     * running unauthenticated) every cart is.
     */
    public function __invoke(ListCartsQuery $query): CartReadCollection
    {
        return CartReadCollection::fromModels(
            $this->cartRepository->search($query->customer(), $query->status(), $query->pageNumber, $query->pageSize),
            $query->pageNumber,
            $query->pageSize,
            $this->cartRepository->countMatching($query->customer(), $query->status()),
        );
    }
}
