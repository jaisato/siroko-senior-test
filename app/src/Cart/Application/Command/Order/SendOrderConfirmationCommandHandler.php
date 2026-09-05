<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Order;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Siroko\Cart\Domain\Exception\OrderNotFoundException;
use Siroko\Cart\Domain\Repository\OrderRepository;

/**
 * Runs on the worker, off the `async` queue, for every `CartCheckedOut`.
 *
 * "Sending" is a log line for now - there is no mail channel in this API -
 * but the order records when it went out, which is what makes the handler
 * safe under redelivery: a queue retries, and a second run for the same
 * order finds it confirmed and does nothing.
 */
final class SendOrderConfirmationCommandHandler
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @throws OrderNotFoundException so the queue retries and eventually parks the message, instead of dropping it
     */
    public function __invoke(SendOrderConfirmationCommand $command): void
    {
        $order = $this->orderRepository->ofId($command->orderId());

        if (null === $order) {
            throw OrderNotFoundException::withId($command->orderId());
        }

        if (!$order->confirm($this->clock->now())) {
            $this->logger->info('Order confirmation already sent, nothing to do', ['orderId' => $order->id()->toString()]);

            return;
        }

        $this->orderRepository->save($order);

        $this->logger->info('Order confirmation sent', [
            'orderId' => $order->id()->toString(),
            'cartId' => $order->cartId()->toString(),
            'total' => (string) $order->total(),
            'itemCount' => $order->itemCount(),
        ]);
    }
}
