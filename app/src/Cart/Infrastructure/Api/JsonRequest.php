<?php

declare(strict_types=1);

namespace Siroko\Cart\Infrastructure\Api;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Reads a JSON request body.
 *
 * The POST controllers went straight from `json_decode($request->getContent(),
 * true)` to `$json['code']`. A body that is absent, is not JSON, or is missing a
 * key therefore produced "Undefined array key" or "Trying to access array offset
 * on null" - which PHP 8 raises as an error, so a malformed request came back as
 * HTTP 500. A request the API will not accept is the caller's problem, and 400
 * is how it says so.
 *
 * The accessors are typed. `requireField()` returned whatever the client sent,
 * so `{"code": ["a"]}` reached `new ProductCode(array)` and died with a
 * TypeError - a 500 again - where the request was simply wrong.
 */
final class JsonRequest
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(Request $request): array
    {
        $content = trim($request->getContent());

        if ('' === $content) {
            throw new BadRequestHttpException('A JSON body is required.');
        }

        $decoded = json_decode($content, true);

        // The decode succeeded, so the content is valid JSON and a leading `{`
        // is what makes it an object. array_is_list() cannot answer that on its
        // own: decoding into associative arrays renders `{}` and `[]` as the
        // same empty PHP array, and array_is_list([]) is true - so an empty
        // object, which every one of these endpoints has a documented answer
        // for ("at least one of", "exactly one of"), was turned away as if it
        // had not been an object at all.
        if (!\is_array($decoded) || !str_starts_with($content, '{')) {
            throw new BadRequestHttpException('The request body is not a valid JSON object.');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Refuses a body carrying a field the endpoint does not have.
     *
     * Every request schema in the OpenAPI document says
     * `additionalProperties: false`, and nothing enforced it: a client that
     * mistyped a field, or reached for one that belongs to a different
     * endpoint - `{"name": "...", "stock": 10}` on the edit endpoint, when
     * stock moves through its own - got a 200 that had quietly done less than
     * it asked for. The names that were sent are not echoed back; what the
     * caller needs is the list of names that exist.
     *
     * @param array<string, mixed> $data
     * @param list<string>         $allowed the only fields this endpoint reads
     */
    public static function rejectUnknownFields(array $data, array $allowed): void
    {
        if ([] === array_diff_key($data, array_fill_keys($allowed, true))) {
            return;
        }

        throw new BadRequestHttpException(\sprintf('The body carries a field this endpoint does not accept; it reads only: %s.', implode(', ', $allowed)));
    }

    /**
     * A non-empty string. Numbers are accepted and stringified, since a client
     * may legitimately send `"code": 1234`; arrays, objects and booleans are not.
     *
     * @param array<string, mixed> $data
     */
    public static function requireString(array $data, string $field): string
    {
        $value = self::requireField($data, $field);

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        if (!\is_string($value)) {
            throw new BadRequestHttpException(\sprintf('The field "%s" must be a string.', $field));
        }

        return $value;
    }

    /**
     * An integer, or a string holding one ("12"). Anything else - a decimal, a
     * boolean, an array - is rejected rather than coerced.
     *
     * @param array<string, mixed> $data
     */
    public static function requireInt(array $data, string $field): int
    {
        $value = self::requireField($data, $field);

        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && 1 === preg_match('/^-?\d{1,18}$/', $value)) {
            return (int) $value;
        }

        throw new BadRequestHttpException(\sprintf('The field "%s" must be an integer.', $field));
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string>         $itemKeys the keys every entry must carry - and,
     *                                       as the document says of them, the only
     *                                       ones it may - for a list whose entries
     *                                       are themselves objects
     *
     * @return list<mixed>
     */
    public static function requireList(array $data, string $field, array $itemKeys = []): array
    {
        $value = self::requireField($data, $field);

        // json_decode(..., true) represents a JSON object as a PHP array just as
        // it does a JSON array, so is_array() alone accepts {"productId":"x",
        // "quantity":1} where a list belongs. The command then iterates that
        // object's values and indexes each one as an entry, which is a TypeError
        // - HTTP 500 for what is a malformed request.
        if (!\is_array($value) || !array_is_list($value)) {
            throw new BadRequestHttpException(\sprintf('The field "%s" must be a list.', $field));
        }

        foreach ($value as $index => $item) {
            if ([] === $itemKeys) {
                continue;
            }

            if (!\is_array($item)) {
                throw new BadRequestHttpException(\sprintf('Entry %d of "%s" must be an object.', $index, $field));
            }

            foreach ($itemKeys as $key) {
                if (!\array_key_exists($key, $item) || null === $item[$key] || '' === $item[$key]) {
                    throw new BadRequestHttpException(\sprintf('Entry %d of "%s" is missing the field "%s".', $index, $field, $key));
                }
            }

            if ([] !== array_diff_key($item, array_fill_keys($itemKeys, true))) {
                throw new BadRequestHttpException(\sprintf('Entry %d of "%s" carries a field this endpoint does not accept; it reads only: %s.', $index, $field, implode(', ', $itemKeys)));
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function requireField(array $data, string $field): mixed
    {
        if (!\array_key_exists($field, $data) || null === $data[$field] || '' === $data[$field]) {
            throw new BadRequestHttpException(\sprintf('The field "%s" is required.', $field));
        }

        return $data[$field];
    }
}
