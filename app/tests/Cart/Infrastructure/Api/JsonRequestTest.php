<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Siroko\Cart\Infrastructure\Api\JsonRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class JsonRequestTest extends TestCase
{
    public function test_a_json_object_body_is_decoded(): void
    {
        self::assertSame(['code' => 'X', 'quantity' => 1], JsonRequest::toArray(self::request('{"code":"X","quantity":1}')));
    }

    #[DataProvider('unusableBodies')]
    public function test_an_unusable_body_is_a_bad_request(string $content, string $message): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage($message);

        JsonRequest::toArray(self::request($content));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unusableBodies(): iterable
    {
        yield 'empty' => ['', 'A JSON body is required.'];
        yield 'blank' => ["  \n ", 'A JSON body is required.'];
        yield 'not json' => ['{code: X}', 'not a valid JSON object'];
        yield 'a scalar' => ['"text"', 'not a valid JSON object'];
        yield 'a list' => ['[1,2]', 'not a valid JSON object'];
    }

    public function test_require_string_accepts_strings_and_numbers(): void
    {
        self::assertSame('ABC', JsonRequest::requireString(['code' => 'ABC'], 'code'));
        self::assertSame('1234', JsonRequest::requireString(['code' => 1234], 'code'));
        self::assertSame('19.99', JsonRequest::requireString(['amount' => 19.99], 'amount'));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('notStrings')]
    public function test_require_string_rejects_anything_else(array $data, string $message): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage($message);

        JsonRequest::requireString($data, 'code');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function notStrings(): iterable
    {
        yield 'missing' => [[], 'The field "code" is required.'];
        yield 'null' => [['code' => null], 'The field "code" is required.'];
        yield 'empty' => [['code' => ''], 'The field "code" is required.'];
        yield 'array' => [['code' => ['a']], 'The field "code" must be a string.'];
        yield 'object' => [['code' => ['k' => 'v']], 'The field "code" must be a string.'];
        yield 'bool' => [['code' => true], 'The field "code" must be a string.'];
    }

    public function test_require_int_accepts_integers_and_integer_strings(): void
    {
        self::assertSame(7, JsonRequest::requireInt(['quantity' => 7], 'quantity'));
        self::assertSame(0, JsonRequest::requireInt(['quantity' => 0], 'quantity'));
        self::assertSame(12, JsonRequest::requireInt(['quantity' => '12'], 'quantity'));
        self::assertSame(-3, JsonRequest::requireInt(['quantity' => '-3'], 'quantity'));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('notIntegers')]
    public function test_require_int_rejects_anything_else(array $data, string $message): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage($message);

        JsonRequest::requireInt($data, 'quantity');
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function notIntegers(): iterable
    {
        yield 'missing' => [[], 'The field "quantity" is required.'];
        yield 'decimal' => [['quantity' => 1.5], 'The field "quantity" must be an integer.'];
        yield 'decimal string' => [['quantity' => '1.5'], 'The field "quantity" must be an integer.'];
        yield 'word' => [['quantity' => 'two'], 'The field "quantity" must be an integer.'];
        yield 'bool' => [['quantity' => true], 'The field "quantity" must be an integer.'];
        yield 'array' => [['quantity' => [1]], 'The field "quantity" must be an integer.'];
        yield 'absurdly long' => [['quantity' => str_repeat('9', 19)], 'The field "quantity" must be an integer.'];
    }

    public function test_require_list_returns_the_list_when_every_entry_has_the_keys(): void
    {
        $list = [['productId' => 'a', 'quantity' => 1], ['productId' => 'b', 'quantity' => 2]];

        self::assertSame($list, JsonRequest::requireList(['products' => $list], 'products', ['productId', 'quantity']));
        self::assertSame([1, 2], JsonRequest::requireList(['numbers' => [1, 2]], 'numbers'));
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('notLists')]
    public function test_require_list_rejects_anything_else(array $data, string $message): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->expectExceptionMessage($message);

        JsonRequest::requireList($data, 'products', ['productId', 'quantity']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function notLists(): iterable
    {
        yield 'missing' => [[], 'The field "products" is required.'];
        yield 'an object where a list belongs' => [['products' => ['productId' => 'a', 'quantity' => 1]], 'The field "products" must be a list.'];
        yield 'a string' => [['products' => 'a'], 'The field "products" must be a list.'];
        yield 'entry not an object' => [['products' => ['a']], 'Entry 0 of "products" must be an object.'];
        yield 'entry missing a key' => [['products' => [['productId' => 'a']]], 'Entry 0 of "products" is missing the field "quantity".'];
        yield 'entry with an empty key' => [['products' => [['productId' => 'a', 'quantity' => 1], ['productId' => '', 'quantity' => 1]]], 'Entry 1 of "products" is missing the field "productId".'];
    }

    private static function request(string $content): Request
    {
        return Request::create('/', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: $content);
    }
}
