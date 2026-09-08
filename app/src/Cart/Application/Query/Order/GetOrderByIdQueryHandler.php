<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Query\Order;

use Siroko\Cart\Application\Dto\Order\OrderRead;
use Siroko\Cart\Domain\Exception\OrderNotFoundException;
use Siroko\Cart\Domain\Repository\OrderRepository;

final class GetOrderByIdQueryHandler
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
    ) {}

    /**
     * @throws OrderNotFoundException
     */
    public function __invoke(GetOrderByIdQuery $query): OrderRead
    {
        $order = $this->orderRepository->ofId($query->orderId());

        if (null === $order) {
            throw OrderNotFoundException::withId($query->orderId());
        }

        $order->ensureAccessibleBy($query->customer());

        return OrderRead::fromModel($order);
    }
}
