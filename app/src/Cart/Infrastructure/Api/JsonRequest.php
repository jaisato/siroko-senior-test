<?php

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
 */
final class JsonRequest
{
    /**
     * @return array<string,mixed>
     */
    public static function toArray(Request $request): array
    {
        $content = trim($request->getContent());

        if ($content === '') {
            throw new BadRequestHttpException('A JSON body is required.');
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new BadRequestHttpException('The request body is not a valid JSON object.');
        }

        return $decoded;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function requireField(array $data, string $field): mixed
    {
        if (!array_key_exists($field, $data) || $data[$field] === null || $data[$field] === '') {
            throw new BadRequestHttpException(sprintf('The field "%s" is required.', $field));
        }

        return $data[$field];
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string>        $itemKeys keys every entry must carry, for a list
     *                                      whose entries are themselves objects
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
        if (!is_array($value) || !array_is_list($value)) {
            throw new BadRequestHttpException(sprintf('The field "%s" must be a list.', $field));
        }

        foreach ($value as $index => $item) {
            if ($itemKeys !== [] && !is_array($item)) {
                throw new BadRequestHttpException(
                    sprintf('Entry %d of "%s" must be an object.', $index, $field)
                );
            }

            foreach ($itemKeys as $key) {
                if (!array_key_exists($key, $item) || $item[$key] === null || $item[$key] === '') {
                    throw new BadRequestHttpException(
                        sprintf('Entry %d of "%s" is missing the field "%s".', $index, $field, $key)
                    );
                }
            }
        }

        return $value;
    }
}
