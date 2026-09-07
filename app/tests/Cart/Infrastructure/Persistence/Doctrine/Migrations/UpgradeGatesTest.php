<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\Migrations\Exception\AbortMigration;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations\Version20260905120000;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations\Version20260906100000;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations\Version20260906180000;
use Siroko\Cart\Infrastructure\Persistence\Doctrine\Migrations\Version20260906190000;

/**
 * Four migrations refuse to run against data the new rules cannot hold, and
 * what exactly they refuse is the whole point: a gate that stops a deploy over
 * rows that are in fact fine is as bad as no gate at all.
 *
 * They are MySQL DDL, so the suite cannot execute them (the SQLite profile
 * builds its schema from the mappings). What it can do is drive them with the
 * connection they read through and check the questions they ask and the order
 * they queue their statements in - which is where every defect in them was.
 * UpgradeGatesRunOnMysqlTest runs the queries themselves against the engine.
 */
final class UpgradeGatesTest extends TestCase
{
    /**
     * The unique index is created under whatever collation the column has, and
     * the duplicate check is made the same way. Applied after the index, the
     * binary collation came too late: an upgraded catalogue holding both `ABC`
     * and `abc` - two products the final schema is explicitly meant to allow -
     * was grouped as a duplicate and the deploy stopped over data that is not
     * in conflict.
     */
    public function test_the_code_column_is_made_case_sensitive_before_the_unique_index(): void
    {
        $asked = [];
        $migration = new Version20260905120000($this->connection($asked, [[], []]), new NullLogger());

        $migration->preUp(new Schema());
        $migration->up(new Schema());

        self::assertCount(2, $asked, 'one code per product, and a code the API can be asked for');
        self::assertStringContainsString('COLLATE utf8mb4_bin', $asked[0]['sql'], 'the duplicate check compares the way the domain does');

        $statements = $this->statements($migration);
        self::assertCount(2, $statements);
        self::assertStringContainsString('COLLATE utf8mb4_bin', $statements[0]);
        self::assertStringContainsString('CREATE UNIQUE INDEX uniq_product_code', $statements[1]);
    }

    public function test_two_products_under_one_code_stop_the_upgrade_and_are_named(): void
    {
        $asked = [];
        $migration = new Version20260905120000($this->connection($asked, [['K3']]), new NullLogger());

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('K3');

        $migration->preUp(new Schema());
    }

    /**
     * A code identifies a product only if the API can be asked for it, and
     * `GET /v1/products/by-code/{code}` reads a slash as another path segment.
     * ProductCode refuses one for that reason, but hydration does not re-apply
     * the rule, so a row written before it existed keeps a code the lookup this
     * series introduces cannot reach.
     */
    public function test_a_legacy_code_the_by_code_lookup_cannot_address_stops_the_upgrade(): void
    {
        $asked = [];
        // Nothing duplicated; the second question is the one that answers.
        $migration = new Version20260905120000($this->connection($asked, [[], ['ABC/123']]), new NullLogger());

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('ABC/123');

        $migration->preUp(new Schema());
    }

    public function test_the_addressability_check_looks_for_slashes_and_control_characters(): void
    {
        $asked = [];
        $migration = new Version20260905120000($this->connection($asked, [[], []]), new NullLogger());

        $migration->preUp(new Schema());

        self::assertStringContainsString("REGEXP '[/[:cntrl:]]'", $asked[1]['sql']);
    }

    /**
     * Two different questions, and the difference is what a cart can still
     * become. The unit ceiling is about the future: a product on sale can be
     * added up to MAX_LINES lines of MAX_QUANTITY units, so its price has to be
     * one a full cart of it survives. A withdrawn product has no future -
     * reserveStock refuses one, so no add and no quantity change puts another
     * unit in a cart - and it is judged on the arithmetic instead: what the
     * carts holding it already total.
     */
    public function test_the_price_ceiling_looks_only_at_products_still_on_sale(): void
    {
        $asked = [];
        $migration = new Version20260906190000($this->connection($asked, [[], []]), new NullLogger());

        $migration->preUp(new Schema());

        self::assertCount(2, $asked, 'the unit ceiling, and the totals of the carts that are already out there');
        self::assertStringContainsString('deleted_at IS NULL', $asked[0]['sql']);
        self::assertStringNotContainsString('cart_item', $asked[0]['sql'], 'what a cart holds today is the other question');
    }

    public function test_a_product_on_sale_above_the_price_ceiling_stops_the_upgrade(): void
    {
        $asked = [];
        $migration = new Version20260906190000($this->connection($asked, [['B1'], []]), new NullLogger());

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('B1');

        $migration->preUp(new Schema());
    }

    /**
     * The second gate is the arithmetic, not the presence of an overpriced
     * product: a withdrawn one held as a single unit at a price the column fits
     * is not an overflow, and no unit can be added to it, so a deploy stopped
     * over that cart is stopped over nothing.
     */
    public function test_the_second_gate_asks_what_the_pending_carts_already_total(): void
    {
        $asked = [];
        $migration = new Version20260906190000($this->connection($asked, [[], []]), new NullLogger());

        $migration->preUp(new Schema());

        $totals = $asked[1];
        self::assertStringContainsString('SUM(p.price_amount * i.quantity)', $totals['sql']);
        self::assertStringContainsString('GROUP BY i.cart_id, p.price_currency', $totals['sql'], 'an order carries one total in one currency');
        self::assertSame(CartStatus::PENDING, $totals['params']['pending']);
        // Fifteen integral digits: NUMERIC(19, 4), the column itself rather
        // than the unit ceiling derived from it.
        self::assertSame('999999999999999.9999', $totals['params']['maximum']);
    }

    public function test_a_pending_cart_whose_total_the_column_cannot_hold_stops_the_upgrade(): void
    {
        $asked = [];
        $migration = new Version20260906190000(
            $this->connection($asked, [[], ['0b6d… (1000000000000000.0000 EUR)']]),
            new NullLogger(),
        );

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('1000000000000000.0000 EUR');

        $migration->preUp(new Schema());
    }

    /**
     * Collapsing N rows into one line of N units writes a quantity the domain
     * refuses above CartItem::MAX_QUANTITY - and Price::MAX_AMOUNT is derived
     * from that limit, so such a cart checks out into a total the money columns
     * do not fit.
     */
    public function test_a_pending_cart_over_the_unit_limit_stops_the_collapse(): void
    {
        $asked = [];
        $migration = new Version20260906100000(
            $this->connection($asked, [['0b6d… (150 units of K3)']]),
            new NullLogger(),
        );

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('150 units of K3');

        $migration->preUp(new Schema());
    }

    /**
     * Price::MAX_AMOUNT is computed from both bounds together, so gating only
     * the units left the other way in: 101 products of 100 rows each passed,
     * and at the highest unit price that cart overflows the order total
     * exactly like a single line of 150 would.
     */
    public function test_a_pending_cart_over_the_distinct_line_limit_stops_the_collapse(): void
    {
        $asked = [];
        // Nothing over the unit limit; the second question is the one that answers.
        $migration = new Version20260906100000(
            $this->connection($asked, [[], ['0b6d… (101 products)']]),
            new NullLogger(),
        );

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('101 products');

        $migration->preUp(new Schema());
    }

    public function test_both_line_gates_look_only_at_carts_that_can_still_be_checked_out(): void
    {
        $asked = [];
        $migration = new Version20260906100000($this->connection($asked, [[], []]), new NullLogger());

        $migration->preUp(new Schema());

        self::assertCount(2, $asked, 'units per line, and lines per cart');
        foreach ($asked as $question) {
            self::assertStringContainsString('c.status = :pending', $question['sql']);
        }
        self::assertSame(CartItem::MAX_QUANTITY, $asked[0]['params']['limit'] ?? null);
        self::assertSame(Cart::MAX_LINES, $asked[1]['params']['limit'] ?? null);

        // cart.id is BINARY(16); concatenated raw it is sixteen bytes of noise
        // rather than the id the operator is asked to act on.
        foreach ($asked as $question) {
            self::assertStringContainsString('BIN_TO_UUID(i.cart_id)', $question['sql']);
        }
    }

    /**
     * And both name a way out that can actually be taken here.
     *
     * They used to say "cancel them through the API (DELETE /v1/carts/{id})",
     * an endpoint that ships with this same version: the running application
     * does not have it, and the new one cannot serve it before this migration
     * either, because this is what adds the `cart_item.quantity` its mapping
     * reads. So the instructions named a door locked from both sides, and any
     * cart either gate reported blocked the deployment with nothing to do
     * about it.
     */
    public function test_both_line_gates_name_a_way_out_that_does_not_need_the_api(): void
    {
        foreach ([[['0b6d… (150 units of K3)']], [[], ['0b6d… (101 products)']]] as $answers) {
            $asked = [];
            $migration = new Version20260906100000($this->connection($asked, $answers), new NullLogger());

            try {
                $migration->preUp(new Schema());
                self::fail('expected the gate to stop the migration');
            } catch (AbortMigration $stopped) {
                $recipe = $stopped->getMessage();

                // The endpoint is named only to say it cannot be used here.
                self::assertStringContainsString('the API cannot', $recipe);
                // Cancelling is what the recipe does: the units go back and the
                // cart stops being pending, which is all either gate reads.
                self::assertStringContainsString('SET p.quantity = p.quantity + held.units;', $recipe);
                self::assertStringContainsString(
                    'UPDATE cart SET status = ' . CartStatus::CANCELED,
                    $recipe,
                    'the states come from the enum, so renumbering cannot leave the recipe behind',
                );
                self::assertStringContainsString('AND status = ' . CartStatus::PENDING . ';', $recipe);
                // A line is still one row here - `quantity` is the column this
                // very migration adds - so the units are counted, not summed.
                self::assertStringContainsString('COUNT(*) AS units', $recipe);
                // And the two statements are one unit of work: the increment
                // gives the units back, the status change is what stops a retry
                // giving them back again.
                self::assertStringContainsString('START TRANSACTION;', $recipe);
                self::assertStringContainsString('COMMIT;', $recipe);
            }
        }
    }

    /**
     * A cart is priced in one currency - addItem() and the checkout refuse a
     * product in another, and subtotal() adds the lines without asking - so a
     * legacy row in two adds up to nothing: an exception on every read of the
     * cart, and no checkout for it while it is pending. The gate runs before
     * the backfill, which would otherwise freeze both currencies into the
     * line's own columns.
     */
    public function test_a_legacy_cart_in_two_currencies_stops_the_backfill(): void
    {
        $asked = [];
        $migration = new Version20260906180000($this->connection($asked, [['0b6d… (EUR/USD)']]), new NullLogger());

        $this->expectException(AbortMigration::class);
        $this->expectExceptionMessage('EUR/USD');

        $migration->preUp(new Schema());
    }

    public function test_the_currency_gate_looks_at_every_cart_whatever_its_status(): void
    {
        $asked = [];
        $migration = new Version20260906180000($this->connection($asked, [[]]), new NullLogger());

        $migration->preUp(new Schema());

        self::assertCount(1, $asked);
        self::assertStringContainsString('COUNT(DISTINCT p.price_currency) > 1', $asked[0]['sql']);
        // A settled cart is as unreadable as a pending one once its lines
        // disagree, and the backfill is what makes that permanent. The status
        // is read, but only to say which of the two it is in the message: what
        // to do about the cart depends on whether its units are still held.
        self::assertStringNotContainsString('WHERE', $asked[0]['sql'], 'nothing narrows the carts it looks at');
        self::assertStringContainsString("IF(c.status = 1, 'pending', 'settled')", $asked[0]['sql']);
    }

    /**
     * And the way out it names has to be one that works.
     *
     * It used to say `DELETE /v1/carts/{id}`, which cannot clear this gate
     * however many times it is run: cancelling changes the status and gives
     * the units back but keeps every line, deliberately, and this reads carts
     * of every status - so the same cart comes back on the next attempt. That
     * endpoint is also part of the version being deployed, while the running
     * one does not have it and the new one cannot read these carts until this
     * migration has added the columns.
     */
    public function test_the_currency_gate_names_a_way_out_that_clears_it(): void
    {
        $asked = [];
        $migration = new Version20260906180000($this->connection($asked, [['0b6d… (EUR/USD, pending)']]), new NullLogger());

        try {
            $migration->preUp(new Schema());
            self::fail('expected the gate to stop the migration');
        } catch (AbortMigration $stopped) {
            $recipe = $stopped->getMessage();

            self::assertStringNotContainsString('/v1/carts/', $recipe, 'no endpoint clears this');
            self::assertStringContainsString('DELETE FROM cart_item', $recipe);
            self::assertStringContainsString(
                'SET p.quantity = p.quantity + i.quantity',
                $recipe,
                'a pending cart is still holding its units',
            );
            // The lines are the only record a legacy cart has: `orders` is
            // created empty by this same series, even for carts already paid,
            // so there is nothing there to reconcile one against.
            self::assertStringContainsString('INSERT INTO cart_item_mixed_currency', $recipe, 'copied out first');
            self::assertStringContainsString('CREATE TABLE IF NOT EXISTS cart_item_mixed_currency', $recipe);
            // And the two statements that move stock are one unit of work: a
            // connection dropped between them turns a retry into inventory out
            // of nowhere.
            self::assertStringContainsString('START TRANSACTION;', $recipe);
            self::assertStringContainsString('COMMIT;', $recipe);
            self::assertStringNotContainsString(
                'CREATE TABLE IF NOT EXISTS cart_item_mixed_currency LIKE cart_item;' . "\n" . '  START TRANSACTION',
                $recipe,
                'the DDL is outside the transaction, which it would commit',
            );
        }
    }

    /**
     * A connection that records every question asked of it and answers them in
     * turn from `$answers` - a migration with two gates asks twice, and each
     * has to be able to answer differently.
     *
     * @param array<int, array{sql: string, params: array<string, mixed>}> $asked   filled as the migration reads
     * @param list<list<string>>                                           $answers one per question, in order
     */
    private function connection(array &$asked, array $answers): Connection
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchFirstColumn')->willReturnCallback(
            /** @param array<string, mixed> $params */
            static function (string $sql, array $params = []) use (&$asked, $answers): array {
                $asked[] = ['sql' => $sql, 'params' => $params];

                return $answers[\count($asked) - 1] ?? [];
            },
        );

        return $connection;
    }

    /** @return list<string> the statements the migration queued, in order */
    private function statements(AbstractMigration $migration): array
    {
        return array_values(array_map(static fn($query): string => $query->getStatement(), $migration->getSql()));
    }
}
