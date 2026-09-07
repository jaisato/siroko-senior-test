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

        // Between that commit and this send, a cancellation can take the row
        // and call the purchase off: the first transaction released the lock,
        // and the customer's DELETE only has to win the microseconds after it.
        // Asked once at the top and not again, the handler told a customer
        // about a purchase the API had already accepted calling off.
        //
        // The read is its own short transaction and takes the lock, so it
        // cannot answer with a cancellation half-written. What it cannot do is
        // close the window: a cancellation committing after this read and
        // before the log line below is not there to be seen, and the only way
        // to give it the last word would be to hold the order's row across the
        // delivery - blocking the customer's own request for as long as a mail
        // service takes, and putting an unrollbackable side effect back inside
        // a transaction, which is what the split was made to stop. What is
        // left is a window microseconds wide instead of one as long as the
        // queue took to reach this message.
        if ($this->wasCanceledSinceTheDecision($order)) {
            $this->logger->info('Order confirmation not sent, the purchase was called off first', [
                'orderId' => $order->id()->toString(),
            ]);

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

            if (null === $stored) {
                return;
            }

            if ($stored->markConfirmationSent($this->clock->now())) {
                $this->orderRepository->save($stored);

                return;
            }

            // Refused, and a cancellation that landed during the delivery is
            // the one reason worth a line. The message cannot be taken back -
            // it left the process - but the row is not written as though the
            // purchase had gone through: `confirmation_sent_at` is what the
            // API reports as `confirmedAt`, and stamping it here left an order
            // that read as confirmed and cancelled at once. The operator gets
            // told, because a customer holding a confirmation for a purchase
            // they called off will ask about it.
            if ($stored->isCanceled() && !$stored->isConfirmationSent()) {
                $this->logger->warning('Order confirmation crossed a cancellation in flight', [
                    'orderId' => $stored->id()->toString(),
                    'canceledAt' => $stored->canceledAt()?->format(\DateTimeInterface::RFC3339),
                ]);
            }
        });
    }

    /**
     * Whether the purchase was called off between the decision and now.
     *
     * Under the row lock, so that a cancellation half-written cannot be read
     * as absent; a plain read could see the row as the cancelling transaction
     * left it before its own commit.
     */
    private function wasCanceledSinceTheDecision(Order $order): bool
    {
        return $this->session->executeAtomically(
            fn(): bool => $this->orderRepository->ofIdForUpdate($order->id())?->isCanceled() ?? false,
        );
    }
}
