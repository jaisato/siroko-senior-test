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
     * @return array<mixed>
     */
    public static function requireList(array $data, string $field): array
    {
        $value = self::requireField($data, $field);

        if (!is_array($value)) {
            throw new BadRequestHttpException(sprintf('The field "%s" must be a list.', $field));
        }

        return $value;
    }
}
