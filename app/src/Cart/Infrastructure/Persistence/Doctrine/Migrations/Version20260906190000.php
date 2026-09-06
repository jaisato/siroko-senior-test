<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Siroko\Cart\Domain\ValueObject\Price;

/**
 * Two places where the database disagreed with the domain about equality and
 * about bounds, and one it had nowhere to record.
 *
 * `product.code` inherited the table's utf8mb4_unicode_ci, which compares
 * case-insensitively, while ProductCode::equals() compares byte for byte. So
 * `uniq_product_code` refused `abc` next to `ABC` - a valid create answering
 * 409 - and the by-code lookup could hand back `ABC` for a request for `abc`.
 * SQLite compares TEXT byte for byte, so the local suite and MySQL disagreed
 * about the same data. utf8mb4_bin makes the column compare the way the value
 * object does; SqliteBinaryCollationMiddleware teaches SQLite the name.
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
        return 'product.code compares case-sensitively, orders can be cancelled, and legacy prices above the domain maximum stop the upgrade';
    }

    /**
     * Before any DDL, so a database that cannot satisfy the new rule is left
     * exactly as it was.
     */
    public function preUp(Schema $schema): void
    {
        $overpriced = $this->connection->fetchFirstColumn(
            'SELECT code FROM product WHERE price_amount > :maximum ORDER BY code LIMIT 10',
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
        $this->addSql('ALTER TABLE product MODIFY code VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL COMMENT \'(DC2Type:product_code)\'');

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

        // Back to the table's default collation, which is where the column came
        // from and what every other VARCHAR here still uses.
        $this->addSql('ALTER TABLE product MODIFY code VARCHAR(50) NOT NULL COMMENT \'(DC2Type:product_code)\'');
    }
}
