<?php

namespace Siroko\Cart\Application\Command\Cart;

use Brick\Money\Exception\UnknownCurrencyException;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Infrastructure\Api\Dto\Cart\CartRead;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CheckoutCartCommandHandler
{
    /**
     * @param CartRepository $cartRepository
     */
    public function __construct(
        private readonly CartRepository $cartRepository,
    ) {
    }

    /**
     * @param CheckoutCartCommand $command
     * @return CartRead
     * @throws UnknownCurrencyException
     */
    public function __invoke(CheckoutCartCommand $command): CartRead
    {
        $cart = $this->cartRepository->ofId($command->cartId());

        if ($cart === null) {
            throw new NotFoundHttpException("Cart not found");
        }

        if ($cart->status()->toInt() !== CartStatus::PENDING) {
            throw new \LogicException("Cart is not pending");
        }

        $cart->setStatus(new CartStatus(CartStatus::PAID));

        $this->cartRepository->save($cart);

        return CartRead::fromModel($cart);
    }
}
