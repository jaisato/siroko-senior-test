<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Persistence\Doctrine\Repository;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\LockWaitTimeoutException;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use PHPUnit\Framework\Attributes\Group;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Repository\CartRepository;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The handlers rely on `ofIdForUpdate()` really holding a row lock until the
 * transaction ends: checkout, add and remove serialise on the cart row so that
 * two of them cannot both read "pending" and both act on it.
 *
 * Row locks are an InnoDB behaviour. SQLite has no `FOR UPDATE` (the platform
 * emits nothing for it) and serialises whole-database writers instead, so this
 * test is meaningful on MySQL only; it is excluded by default (`mysql` group)
 * and runs in CI against the mysql:8 service.
 */
#[Group('mysql')]
final class PessimisticLockingTest extends KernelTestCase
{
    private static ?Connection $other = null;

    /** @var list<string> raw ids inserted outside the test transaction */
    private static array $committedCarts = [];

    public static function tearDownAfterClass(): void
    {
        // Runs after DAMA has rolled the test transaction back and released the
        // lock, so the row committed through the other connection can go.
        if (null !== self::$other) {
            foreach (self::$committedCarts as $id) {
                self::$other->executeStatement('DELETE FROM cart WHERE id = ?', [$id], [ParameterType::BINARY]);
            }
            self::$other->close();
            self::$other = null;
        }

        self::$committedCarts = [];
    }

    public function test_a_cart_loaded_for_update_keeps_a_second_transaction_waiting(): void
    {
        self::bootKernel();

        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        if (!$em->getConnection()->getDatabasePlatform() instanceof AbstractMySQLPlatform) {
            self::fail('This test is only meaningful on MySQL; run it with --group mysql against a MySQL DATABASE_URL.');
        }

        // A second, independent connection with autocommit: the row it inserts
        // is committed and therefore visible to the locking read below.
        self::$other = DriverManager::getConnection($em->getConnection()->getParams());
        $cartId = Uuid::uuid4();
        self::$other->executeStatement(
            'INSERT INTO cart (id, status) VALUES (?, ?)',
            [$cartId->getBytes(), CartStatus::PENDING],
            [ParameterType::BINARY, ParameterType::INTEGER],
        );
        self::$committedCarts[] = $cartId->getBytes();

        // The test connection is inside the transaction DAMA opened: this lock
        // is held until the end of the test.
        $repository = static::getContainer()->get(CartRepository::class);
        self::assertNotNull($repository->ofIdForUpdate(CartId::fromString($cartId->toString())));

        self::$other->executeStatement('SET SESSION innodb_lock_wait_timeout = 1');
        self::$other->beginTransaction();

        try {
            $this->expectException(LockWaitTimeoutException::class);

            self::$other->executeQuery(
                'SELECT id FROM cart WHERE id = ? FOR UPDATE',
                [$cartId->getBytes()],
                [ParameterType::BINARY],
            );
        } finally {
            self::$other->rollBack();
        }
    }
}
