<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure;

use PHPUnit\Framework\TestCase;

/**
 * The source has to parse on the oldest PHP composer.json accepts.
 *
 * `new Foo()->bar()` - a constructor call whose result is used without
 * parentheses - is PHP 8.4 syntax and a *parse* error on 8.3, which this
 * application still supports and CI still builds. Written on an 8.4 machine it
 * survives every local gate: the file parses, PHPStan reads it happily even
 * with `phpVersion: 80300`, php-cs-fixer has nothing to say, and the suite runs
 * green. The 8.3 job is the first thing that sees it, one push later.
 *
 * So the check is here, where it costs a second: the tokens say whether a
 * `new` is followed by `->` outside its own arguments, and composer.json says
 * whether a version that cannot read that is still supported. Should the floor
 * ever move to 8.4, this test retires itself.
 */
final class OldestSupportedPhpTest extends TestCase
{
    private const PARENTHESISED_NEW_ARRIVED_IN = 80400;

    public function test_no_source_file_uses_syntax_the_oldest_supported_php_cannot_parse(): void
    {
        if (self::oldestSupported() >= self::PARENTHESISED_NEW_ARRIVED_IN) {
            self::markTestSkipped('every supported PHP reads `new Foo()->bar()`');
        }

        $offenders = [];

        foreach (self::sourceFiles() as $file) {
            $line = self::firstUnparenthesisedNew($file);

            if (null !== $line) {
                $offenders[] = \sprintf('%s:%d', $file, $line);
            }
        }

        self::assertSame([], $offenders, 'PHP 8.3 cannot parse `new Foo()->bar()`; write `(new Foo())->bar()`');
    }

    /** The lowest PHP version `composer.json` will install on, as PHP_VERSION_ID. */
    private static function oldestSupported(): int
    {
        $manifest = file_get_contents(\dirname(__DIR__, 3) . '/composer.json');
        self::assertIsString($manifest);

        /** @var array{require: array{php: string}} $decoded */
        $decoded = json_decode($manifest, true, 512, \JSON_THROW_ON_ERROR);

        self::assertSame(
            1,
            preg_match('/(\d+)\.(\d+)/', $decoded['require']['php'], $floor),
            'the php constraint has no version in it',
        );

        return ((int) $floor[1]) * 10000 + ((int) $floor[2]) * 100;
    }

    /**
     * The line of the first `new Foo(...)->`, or null when the file has none.
     *
     * Read from the tokens rather than matched with a pattern, because the
     * `->` that matters is the one *after* the constructor's own arguments and
     * `new Foo($a->b())` is full of the other kind.
     */
    private static function firstUnparenthesisedNew(string $file): ?int
    {
        $code = file_get_contents($file);
        self::assertIsString($code);

        $tokens = token_get_all($code);
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if (!\is_array($token) || \T_NEW !== $token[0]) {
                continue;
            }

            $depth = 0;
            $opened = false;

            for ($j = $i + 1; $j < $count; ++$j) {
                $inner = $tokens[$j];

                if ('(' === $inner) {
                    ++$depth;
                    $opened = true;

                    continue;
                }

                if (')' === $inner) {
                    --$depth;

                    if ($depth > 0 || !$opened) {
                        continue;
                    }

                    $next = $tokens[$j + 1] ?? null;

                    if (\is_array($next) && \T_OBJECT_OPERATOR === $next[0]) {
                        return $token[2];
                    }

                    break;
                }

                // `new Foo;` and `new Foo, ` never reach a constructor call.
                if (0 === $depth && \in_array($inner, [';', ',', '{'], true)) {
                    break;
                }
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 3);
        $files = [];

        foreach (['src', 'tests'] as $directory) {
            /** @var iterable<\SplFileInfo> $found */
            $found = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($found as $file) {
                if ('php' === $file->getExtension()) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
