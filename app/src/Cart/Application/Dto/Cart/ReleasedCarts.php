<?php

declare(strict_types=1);

namespace Siroko\Cart\Application\Dto\Cart;

/**
 * What one batch of the expiry sweep did.
 */
final class ReleasedCarts
{
    /**
     * @param int<0, max> $candidates carts the batch looked at
     * @param int<0, max> $released   carts actually canceled; the rest were paid or released by somebody else in between
     */
    public function __construct(
        public readonly int $candidates,
        public readonly int $released,
    ) {}

    /**
     * A batch that found fewer candidates than it asked for has drained the
     * backlog; the caller can stop.
     */
    public function isShortOf(int $batchSize): bool
    {
        return $this->candidates < $batchSize;
    }
}
