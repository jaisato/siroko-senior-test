<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Command\Cart;

/**
 * Cancels one batch of pending carts whose reservation has lapsed, returning
 * their units to stock. Run repeatedly (cart:release-expired) until a batch
 * comes back short.
 */
final class ReleaseExpiredCartsCommand
{
    public const DEFAULT_BATCH_SIZE = 100;

    public const MAX_BATCH_SIZE = 1000;

    /**
     * @var positive-int
     */
    public readonly int $batchSize;

    public function __construct(int $batchSize = self::DEFAULT_BATCH_SIZE)
    {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw new \InvalidArgumentException(\sprintf('The batch size must be between 1 and %d, got %d.', self::MAX_BATCH_SIZE, $batchSize));
        }

        $this->batchSize = $batchSize;
    }
}
