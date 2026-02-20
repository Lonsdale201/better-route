<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ConflictException;
use BetterRoute\Http\PreconditionFailedException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\Write\IdempotencyMiddleware;
use BetterRoute\Middleware\Write\IdempotencyStoreInterface;
use BetterRoute\Middleware\Write\OptimisticLockMiddleware;
use BetterRoute\Middleware\Write\OptimisticLockVersionResolverInterface;
use PHPUnit\Framework\TestCase;

final class WriteSafetyMiddlewareTest extends TestCase
{
    public function testIdempotencyMiddlewareReplaysSameRequest(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyMiddleware($store, ttlSeconds: 60, requireKey: true);

        $context = new RequestContext(
            'req_idem_1',
            '/items',
            new WriteSafetyRequest(
                headers: ['idempotency-key' => 'abc-1'],
                method: 'POST',
                json: ['title' => 'A']
            )
        );

        $calls = 0;
        $next = static function () use (&$calls): Response {
            $calls++;
            return new Response(['created' => true], 201);
        };

        $first = $middleware->handle($context, $next);
        $second = $middleware->handle($context, $next);

        self::assertSame(1, $calls);
        self::assertInstanceOf(Response::class, $first);
        self::assertInstanceOf(Response::class, $second);
        self::assertSame('true', $second->headers['Idempotency-Replayed']);
    }

    public function testIdempotencyMiddlewareRejectsConflictingPayloadForSameKey(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyMiddleware($store, ttlSeconds: 60, requireKey: true);

        $ctxA = new RequestContext(
            'req_idem_2a',
            '/items',
            new WriteSafetyRequest(
                headers: ['idempotency-key' => 'same-key'],
                method: 'POST',
                json: ['title' => 'A']
            )
        );

        $ctxB = new RequestContext(
            'req_idem_2b',
            '/items',
            new WriteSafetyRequest(
                headers: ['idempotency-key' => 'same-key'],
                method: 'POST',
                json: ['title' => 'B']
            )
        );

        $middleware->handle($ctxA, static fn (): Response => new Response(['ok' => true], 201));

        $this->expectException(ConflictException::class);
        $middleware->handle($ctxB, static fn (): Response => new Response(['ok' => true], 201));
    }

    public function testOptimisticLockMiddlewareAllowsMatchingVersion(): void
    {
        $middleware = new OptimisticLockMiddleware(
            versionResolver: new StaticVersionResolver('v2')
        );

        $context = new RequestContext(
            'req_lock_ok',
            '/items/1',
            new WriteSafetyRequest(headers: ['if-match' => '"v2"'])
        );

        $result = $middleware->handle($context, static fn (RequestContext $ctx): array => (array) $ctx->attributes['optimisticLock']);

        self::assertSame('v2', $result['expected']);
        self::assertSame('v2', $result['current']);
    }

    public function testOptimisticLockMiddlewareRejectsMismatch(): void
    {
        $middleware = new OptimisticLockMiddleware(
            versionResolver: new StaticVersionResolver('v3')
        );

        $context = new RequestContext(
            'req_lock_fail',
            '/items/1',
            new WriteSafetyRequest(headers: ['if-match' => '"v2"'])
        );

        $this->expectException(PreconditionFailedException::class);
        $middleware->handle($context, static fn (): null => null);
    }
}

final class WriteSafetyRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     * @param array<string, mixed> $json
     * @param array<string, mixed> $body
     */
    public function __construct(
        private readonly array $headers = [],
        private readonly array $params = [],
        private readonly array $json = [],
        private readonly array $body = [],
        private readonly string $method = 'GET'
    ) {
    }

    public function get_header(string $name): string
    {
        return (string) ($this->headers[strtolower($name)] ?? '');
    }

    public function get_method(): string
    {
        return $this->method;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_json_params(): array
    {
        return $this->json;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_body_params(): array
    {
        return $this->body;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_params(): array
    {
        return $this->params;
    }

    public function get_param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }
}

final class InMemoryIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var array<string, mixed> */
    private array $items = [];

    public function get(string $key): mixed
    {
        return $this->items[$key] ?? null;
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $this->items[$key] = $value;
    }
}

final class StaticVersionResolver implements OptimisticLockVersionResolverInterface
{
    public function __construct(
        private readonly string|int|null $version
    ) {
    }

    public function resolve(RequestContext $context): string|int|null
    {
        return $this->version;
    }
}
