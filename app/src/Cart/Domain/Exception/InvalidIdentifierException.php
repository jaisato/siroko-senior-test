<?php

declare(strict_types=1);

namespace Siroko\Cart\Domain\Exception;

/**
 * An identifier is not a UUID.
 *
 * The id value objects accepted any string, so a malformed id travelled all the
 * way to the Doctrine type, where `Uuid::fromString()` blew up while binding the
 * query parameter - a 500 for what is a malformed request. The value is not
 * echoed back: the caller sent it and already knows what it looks like.
 */
final class InvalidIdentifierException extends \DomainException
{
    /**
     * @param class-string $identifierClass
     */
    public static function forType(string $identifierClass): self
    {
        $position = strrpos($identifierClass, '\\');
        $name = false === $position ? $identifierClass : substr($identifierClass, $position + 1);

        return new self(\sprintf('%s is not a valid UUID.', $name));
    }
}
