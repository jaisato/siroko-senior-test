<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Order;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Siroko\Cart\Domain\Exception\OrderNotFoundException;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

/**
 * Runs on the worker, off the `async` queue, for every `CartCheckedOut`.
 *
 * "Sending" is a log line for now - there is no mail channel in this API -
 * but the order records when it went out, which is what makes the handler
 * safe under redelivery: a queue retries, and a second run for the same
 * order finds it confirmed and does nothing.
 *
 * The read takes the order's row lock, inside a transaction, because a
 * cancellation is the other writer to that row. Read unlocked, both sides
 * decided on the state they had each read: the cancellation set `canceled_at`
 * while this handler, holding an order it had loaded as neither confirmed nor
 * cancelled, set `confirmed_at` on top - and the customer was told a purchase
 * they had called off was on its way.
 */
final class SendOrderConfirmationCommandHandler
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
        private readonly TransactionalSession $session,
    ) {}

    /**
     * @throws OrderNotFoundException so the queue retries and eventually parks the message, instead of dropping it
     */
    public function __invoke(SendOrderConfirmationCommand $command): void
    {
        $this->session->executeAtomically(function () use ($command): void {
            $order = $this->orderRepository->ofIdForUpdate($command->orderId());

            if (null === $order) {
                throw OrderNotFoundException::withId($command->orderId());
            }

            if (!$order->confirm($this->clock->now())) {
                $this->logger->info('Order confirmation not sent, nothing to do', [
                    'orderId' => $order->id()->toString(),
                    'confirmed' => $order->isConfirmed(),
                    'canceled' => $order->isCanceled(),
                ]);

                return;
            }

            $this->orderRepository->save($order);

            $this->logger->info('Order confirmation sent', [
                'orderId' => $order->id()->toString(),
                'cartId' => $order->cartId()->toString(),
                'total' => (string) $order->total(),
                'itemCount' => $order->itemCount(),
            ]);
        });
    }
}
