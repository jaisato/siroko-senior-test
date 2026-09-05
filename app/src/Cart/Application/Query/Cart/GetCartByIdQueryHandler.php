<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Cart;

use Siroko\Cart\Application\Dto\Cart\CartRead;
use Siroko\Cart\Domain\Exception\CartNotFoundException;
use Siroko\Cart\Domain\Repository\CartRepository;

final class GetCartByIdQueryHandler
{
    public function __construct(
        private readonly CartRepository $cartRepository,
    ) {}

    /**
     * `ofId()` returns null for an unknown id. A `@var Cart` annotation used to
     * paper over that, so the null reached `CartRead::fromModel()` and the
     * client got a TypeError - a 500 - for asking about a cart that does not
     * exist.
     *
     * @throws CartNotFoundException
     */
    public function __invoke(GetCartByIdQuery $query): CartRead
    {
        $cart = $this->cartRepository->ofId($query->cartId());

        if (null === $cart) {
            throw CartNotFoundException::withId($query->cartId());
        }

        return CartRead::fromModel($cart);
    }
}
