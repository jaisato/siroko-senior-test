<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `orders.confirmation_sent_at`: when the confirmation actually went out.
 *
 * `confirmed_at` is a decision taken inside the transaction that owns the row.
 * The delivery is not: it is a call to somewhere else - a log line here, a mail
 * service in a deployment - and nothing about it can be rolled back, so it
 * cannot be part of that commit. Emitted inside it, a commit that failed
 * afterwards rolled the decision back while the customer had already been told,
 * the retry told them a second time, and the row said the confirmation had
 * never been sent.
 *
 * Two marks separate the two facts. The handler decides and commits, sends, and
 * then records the send; a redelivery reads both and knows which step is still
 * owed. The window that is left - a crash between the send and the queue's ack
 * - belongs to the queue, and no application closes it.
 *
 * Existing rows: an order already confirmed before this ran was also already
 * notified, since the two happened together, so the column is backfilled from
 * `confirmed_at` rather than left NULL. Left NULL, the first sweep of the queue
 * after this migration would have sent every past confirmation again.
 */
final class Version20260906200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'orders.confirmation_sent_at: the delivery, recorded apart from the decision';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE orders ADD confirmation_sent_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
        $this->addSql('UPDATE orders SET confirmation_sent_at = confirmed_at WHERE confirmed_at IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP confirmation_sent_at');
    }
}
