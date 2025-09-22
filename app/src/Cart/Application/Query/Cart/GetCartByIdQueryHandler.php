<?php

namespace Siroko\Cart\Application\Query\Cart;

use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Infrastructure\Api\Dto\Cart\CartRead;

class GetCartByIdQueryHandler
{
    /**
     * @param CartRepository $cartRepository
     */
    public function __construct(
        private readonly CartRepository $cartRepository,
    ) {
    }

    /**
     * @param GetCartByIdQuery $query
     * @return CartRead
     * @throws \Brick\Money\Exception\UnknownCurrencyException
     */
    public function __invoke(GetCartByIdQuery $query): CartRead
    {
        /** @var Cart $cart */
        $cart = $this->cartRepository->ofId($query->cartId());
        return CartRead::fromModel($cart);
    }
}
