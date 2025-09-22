<?php

namespace Siroko\Cart\Application\Command\Cart;

use Siroko\Cart\Domain\Repository\CartRepository;

class DeleteCartItemCommandHandler
{
    /**
     * @param CartRepository $cartRepository
     */
    public function  __construct(
        private readonly CartRepository $cartRepository,
    ) {
    }

    /**
     * @param DeleteCartItemCommand $command
     * @return void
     */
    public function __invoke(DeleteCartItemCommand $command): void
    {
        $this->cartRepository->removeItem($command->cartId(), $command->itemId());
    }
}
