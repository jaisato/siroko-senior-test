<?php

declare(strict_types=1);

namespace Siroko\Tests\Cart\Infrastructure\Api;

use Doctrine\ORM\EntityManagerInterface;
use Liip\TestFixturesBundle\Services\DatabaseToolCollection;
use Ramsey\Uuid\Uuid;
use Siroko\Cart\Domain\Entity\Cart;
use Siroko\Cart\Domain\Entity\CartItem;
use Siroko\Cart\Domain\Entity\Product;
use Siroko\Cart\Domain\ValueObject\CartId;
use Siroko\Cart\Domain\ValueObject\CartStatus;
use Siroko\Cart\Domain\ValueObject\ItemId;
use Siroko\Cart\Domain\ValueObject\Name;
use Siroko\Cart\Domain\ValueObject\Price;
use Siroko\Cart\Domain\ValueObject\ProductCode;
use Siroko\Cart\Domain\ValueObject\ProductId;
use Siroko\Cart\Domain\ValueObject\Quantity;
use Siroko\Cart\Infrastructure\Api\ApiExceptionMapper;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Routing\RouterInterface;

/**
 * Base class of the HTTP tests.
 *
 * Every functional test used to copy the same twenty lines: fetch the
 * fixtures tool, issue a raw `USE siroko_cart_test` against a connection that
 * had been opened as root, load the fixtures, resolve the router. The database
 * is now whatever DATABASE_URL says (SQLite locally, MySQL in CI), the schema
 * is prepared by tests/bootstrap.php, and each test runs inside a transaction
 * that DAMA rolls back, so the helpers below can insert exactly the rows a
 * test needs and nothing leaks between tests.
 */
abstract class ApiTestCase extends WebTestCase
{
    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    protected function em(): EntityManagerInterface
    {
        $em = static::getContainer()->get('doctrine.orm.entity_manager');

        return $em;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    protected function url(string $route, array $parameters = []): string
    {
        $router = static::getContainer()->get(RouterInterface::class);

        return $router->generate($route, $parameters);
    }

    /**
     * Sends a JSON request. `$body` is JSON-encoded; a string is sent verbatim
     * so a test can post something that is not JSON at all.
     *
     * @param array<string, mixed>|string|null $body
     * @param array<string, string>            $server extra server parameters (HTTP_* headers)
     */
    protected function request(string $method, string $uri, array|string|null $body = null, array $server = []): Response
    {
        $content = \is_array($body) ? json_encode($body, \JSON_THROW_ON_ERROR) : $body;

        $this->client->request(
            $method,
            $uri,
            // The caller's headers win over the JSON defaults.
            server: $server + ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'],
            content: $content,
        );

        return $this->client->getResponse();
    }

    /**
     * The last response body, decoded.
     *
     * @return array<string, mixed>
     */
    protected function json(): array
    {
        $content = (string) $this->client->getResponse()->getContent();
        $decoded = json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded, 'the response body is a JSON object');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Asserts the RFC 7807 error contract: media type, the four members, and a
     * detail that mentions the given text.
     */
    protected function assertProblem(int $status, ?string $detailContains = null): void
    {
        $response = $this->client->getResponse();

        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringStartsWith(
            ApiExceptionMapper::CONTENT_TYPE,
            (string) $response->headers->get('Content-Type'),
            'errors are application/problem+json',
        );

        $problem = $this->json();

        self::assertSame($status, $problem['status'] ?? null);
        self::assertArrayHasKey('type', $problem);
        self::assertArrayHasKey('title', $problem);
        self::assertArrayHasKey('detail', $problem);

        if (null !== $detailContains) {
            self::assertIsString($problem['detail']);
            self::assertStringContainsStringIgnoringCase($detailContains, $problem['detail']);
        }
    }

    /**
     * @param list<class-string> $fixtureClasses
     */
    protected function loadFixtures(array $fixtureClasses): void
    {
        $tools = static::getContainer()->get(DatabaseToolCollection::class);

        $tools->get()->loadFixtures($fixtureClasses, true);
    }

    protected function persistProduct(
        string $name = 'A product',
        ?string $code = null,
        string $amount = '19.99',
        string $currency = 'EUR',
        int $stock = 5,
    ): Product {
        $product = new Product(
            ProductId::fromString(Uuid::uuid4()->toString()),
            ProductCode::fromString($code ?? 'SKU-' . strtoupper(substr(Uuid::uuid4()->toString(), 0, 8))),
            Name::fromString($name),
            Price::of($amount, $currency),
            new Quantity($stock),
        );

        $this->em()->persist($product);
        $this->em()->flush();

        return $product;
    }

    /**
     * A cart holding one line per product given; the products' stock is not
     * touched, tests that care about stock set it explicitly.
     */
    protected function persistCart(int $status = CartStatus::PENDING, Product ...$products): Cart
    {
        $lines = [];

        foreach ($products as $product) {
            $lines[] = [$product, 1];
        }

        return $this->persistCartWithLines($status, $lines);
    }

    /**
     * A cart whose lines hold several units each: `[[$product, 3], [$other, 1]]`.
     * Like persistCart(), the products' stock is left alone.
     *
     * The status is reached through the domain transitions when the cart has
     * lines. A cart without lines cannot be paid (that is a rule), so a
     * non-pending empty cart is written directly in that status, the way a
     * row hydrated from the database would be.
     *
     * @param list<array{0: Product, 1: int}> $lines
     */
    protected function persistCartWithLines(int $status, array $lines): Cart
    {
        if ([] === $lines && CartStatus::PENDING !== $status) {
            $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), new CartStatus($status));
        } else {
            $cart = new Cart(CartId::fromString(Uuid::uuid4()->toString()), CartStatus::pending());

            foreach ($lines as [$product, $units]) {
                $cart->addItem(new CartItem(ItemId::fromString(Uuid::uuid4()->toString()), $product, new Quantity($units)));
            }

            if (CartStatus::PAID === $status || CartStatus::DELIVERED === $status) {
                $cart->pay();
            }

            if (CartStatus::DELIVERED === $status) {
                $cart->deliver();
            }

            if (CartStatus::CANCELED === $status) {
                $cart->cancel();
            }
        }

        $this->em()->persist($cart);
        $this->em()->flush();

        return $cart;
    }

    /**
     * The in-memory `async` transport of the test environment: what the
     * command bus handed to the queue during the request.
     */
    protected function asyncTransport(): InMemoryTransport
    {
        $transport = static::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * Runs a queued envelope the way the worker would: through the Messenger
     * bus, marked as received so the bus handles it instead of sending it
     * back to the transport.
     */
    protected function consume(Envelope $envelope): void
    {
        $bus = static::getContainer()->get(MessageBusInterface::class);

        $bus->dispatch($envelope->with(new ReceivedStamp('async')));
    }

    /**
     * Reads a product's stock straight from the database, bypassing the
     * identity map, which is the only way to see what a request really wrote.
     */
    protected function stockOf(Product $product): int
    {
        $this->em()->clear();

        $reloaded = $this->em()->find(Product::class, $product->id());
        self::assertInstanceOf(Product::class, $reloaded);

        return $reloaded->quantity()->asInt();
    }

    protected function reloadCart(Cart $cart): Cart
    {
        $this->em()->clear();

        $reloaded = $this->em()->find(Cart::class, $cart->id());
        self::assertInstanceOf(Cart::class, $reloaded);

        return $reloaded;
    }
}
