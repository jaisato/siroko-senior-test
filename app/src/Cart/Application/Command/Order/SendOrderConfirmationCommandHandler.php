<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Order;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Siroko\Cart\Domain\Entity\Order;
use Siroko\Cart\Domain\Exception\OrderNotFoundException;
use Siroko\Cart\Domain\Repository\OrderRepository;
use Siroko\Cart\Domain\Transaction\TransactionalSession;

/**
 * Runs on the worker, off the `async` queue, for every `CartCheckedOut`.
 *
 * "Sending" is a log line for now - there is no mail channel in this API -
 * but the order records when it went out, which is what makes the handler safe
 * under redelivery: a queue retries, and a second run for the same order finds
 * the confirmation already sent and does nothing.
 *
 * Three steps, and the order of them is the point. The decision commits first
 * (`confirmed_at`), the delivery happens outside any transaction because it
 * cannot be rolled back, and the delivery is recorded afterwards
 * (`confirmation_sent_at`). Sent from inside the commit, as this used to be, a
 * commit that failed after it had already told the customer while the row said
 * it never had - and the retry told them again.
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
        // Step one: decide, under the lock, and commit. Nothing leaves the
        // process here.
        $order = $this->session->executeAtomically(function () use ($command): ?Order {
            $order = $this->orderRepository->ofIdForUpdate($command->orderId());

            if (null === $order) {
                throw OrderNotFoundException::withId($command->orderId());
            }

            // Already sent, or the purchase was called off. Either way there is
            // nothing owed; a redelivery lands here.
            if ($order->isConfirmationSent() || $order->isCanceled()) {
                $this->logger->info('Order confirmation not sent, nothing to do', [
                    'orderId' => $order->id()->toString(),
                    'sent' => $order->isConfirmationSent(),
                    'canceled' => $order->isCanceled(),
                ]);

                return null;
            }

            // A previous attempt may have committed this already and died
            // before sending; confirm() then answers false and the send below
            // still owes the customer their notification.
            if ($order->confirm($this->clock->now())) {
                $this->orderRepository->save($order);
            }

            return $order;
        });

        if (null === $order) {
            return;
        }

        // Step two: the delivery, outside the transaction, because it cannot be
        // part of one - "sending" is a log line here and would be a call to a
        // mail service in a deployment, and neither rolls back. Inside the
        // commit, as this used to be, a commit that failed afterwards had
        // already told the customer while the row said it never had, and the
        // retry told them again.
        $this->logger->info('Order confirmation sent', [
            'orderId' => $order->id()->toString(),
            'cartId' => $order->cartId()->toString(),
            'total' => (string) $order->total(),
            'itemCount' => $order->itemCount(),
        ]);

        // Step three: record that it went out, so the redelivery above knows.
        // A crash between the send and this write - or between this write and
        // the queue's ack - sends the notification twice; that window is the
        // queue's and no application closes it. What it cannot do any more is
        // send one the record denies.
        $this->session->executeAtomically(function () use ($order): void {
            $stored = $this->orderRepository->ofIdForUpdate($order->id());

            if (null !== $stored && $stored->markConfirmationSent($this->clock->now())) {
                $this->orderRepository->save($stored);
            }
        });
    }
}
