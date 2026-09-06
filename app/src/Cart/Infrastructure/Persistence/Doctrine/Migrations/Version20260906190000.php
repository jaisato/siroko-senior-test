<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Siroko\Cart\Domain\ValueObject\Price;

/**
 * A bound the database never enforced, and a fact it had nowhere to record.
 *
 * `Price::MAX_AMOUNT` bounds a unit price so that a full cart of it still fits
 * the money columns. Nothing enforced it on rows written before that bound
 * existed, and hydration does not re-apply it, so a legacy product priced above
 * it stayed sellable and its order total overflowed `orders.total_amount` at
 * checkout - a 500 for a cart that is perfectly valid.
 *
 * Such a row stops this migration rather than being rewritten by it: the price
 * a customer is charged is not something a migration may decide. Whoever runs
 * it reprices those products, or withdraws them, and runs it again.
 */
final class Version20260906190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'orders can be cancelled, and legacy prices above the domain maximum stop the upgrade';
    }

    /**
     * Before any DDL, so a database that cannot satisfy the new rule is left
     * exactly as it was.
     */
    public function preUp(Schema $schema): void
    {
        // Only what is still on sale. A withdrawn product cannot be added to a
        // cart, so its price can no longer overflow anything, and stopping the
        // deploy over one would be asking for a fix (withdraw it) that has
        // already been applied.
        $overpriced = $this->connection->fetchFirstColumn(
            'SELECT code FROM product WHERE price_amount > :maximum AND deleted_at IS NULL ORDER BY code LIMIT 10',
            ['maximum' => Price::MAX_AMOUNT],
        );

        $this->abortIf(
            [] !== $overpriced,
            \sprintf(
                'These products are priced above the maximum a unit price may hold (%s), so a full cart of one '
                . 'would overflow the order total: %s. Reprice or withdraw them, then run this migration again.',
                Price::MAX_AMOUNT,
                implode(', ', array_map(strval(...), $overpriced)),
            ),
        );
    }

    public function up(Schema $schema): void
    {
        // A paid cart can be cancelled, and the order is the record of what was
        // bought. Left untouched it was still confirmed by the queued
        // CartCheckedOut consumer, which only asked whether the confirmation
        // had gone out, and every read of it showed a purchase the customer had
        // called off. Existing rows are NULL: nothing cancelled before now.
        $this->addSql('ALTER TABLE orders ADD canceled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP canceled_at');
    }
}
