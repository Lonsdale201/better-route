<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ConflictException;
use BetterRoute\Http\PreconditionFailedException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\Write\ArrayAtomicIdempotencyStore;
use BetterRoute\Middleware\Write\AtomicIdempotencyMiddleware;
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

    public function testIdempotencyKeyIsScopedToAuthIdentity(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyMiddleware($store, ttlSeconds: 60, requireKey: true);

        $request = new WriteSafetyRequest(
            headers: ['idempotency-key' => 'same-key'],
            method: 'POST',
            json: ['title' => 'A']
        );

        $ctxA = new RequestContext('req_idem_user_a', '/items', $request, [
            'auth' => ['provider' => 'jwt', 'userId' => 10],
        ]);
        $ctxB = new RequestContext('req_idem_user_b', '/items', $request, [
            'auth' => ['provider' => 'jwt', 'userId' => 20],
        ]);

        $calls = 0;
        $next = static function () use (&$calls): Response {
            $calls++;
            return new Response(['call' => $calls], 201);
        };

        $first = $middleware->handle($ctxA, $next);
        $second = $middleware->handle($ctxB, $next);
        $third = $middleware->handle($ctxA, $next);

        self::assertSame(2, $calls);
        self::assertSame(['call' => 1], $first->body);
        self::assertSame(['call' => 2], $second->body);
        self::assertSame(['call' => 1], $third->body);
    }

    public function testIdempotencyAppliesToPatchByDefault(): void
    {
        $store = new InMemoryIdempotencyStore();
        $middleware = new IdempotencyMiddleware($store, ttlSeconds: 60, requireKey: true);

        $context = new RequestContext(
            'req_idem_patch',
            '/items/1',
            new WriteSafetyRequest(
                headers: ['idempotency-key' => 'patch-1'],
                method: 'PATCH',
                json: ['title' => 'A']
            )
        );

        $calls = 0;
        $next = static function () use (&$calls): Response {
            $calls++;
            return new Response(['updated' => true], 200);
        };

        $middleware->handle($context, $next);
        $middleware->handle($context, $next);

        self::assertSame(1, $calls);
    }

    public function testAtomicIdempotencyRejectsConcurrentDuplicateBeforeFirstCompletes(): void
    {
        $store = new ArrayAtomicIdempotencyStore();
        $middleware = new AtomicIdempotencyMiddleware($store, ttlSeconds: 60);

        $context = new RequestContext(
            'req_atomic_1',
            '/checkout',
            new WriteSafetyRequest(
                headers: ['idempotency-key' => 'payment-1'],
                method: 'POST',
                json: ['amount' => 1200]
            )
        );

        $innerException = null;
        $first = $middleware->handle($context, function () use ($middleware, $context, &$innerException): Response {
            try {
                $middleware->handle($context, static fn (): Response => new Response(['duplicate' => true], 201));
            } catch (ConflictException $exception) {
                $innerException = $exception;
            }

            return new Response(['created' => true], 201);
        });

        self::assertInstanceOf(Response::class, $first);
        self::assertInstanceOf(ConflictException::class, $innerException);
        self::assertSame('idempotency_in_progress', $innerException->errorCode());
    }

    public function testAtomicIdempotencyReplaysCompletedResponse(): void
    {
        $store = new ArrayAtomicIdempotencyStore();
        $middleware = new AtomicIdempotencyMiddleware($store, ttlSeconds: 60);
        $context = new RequestContext(
            'req_atomic_2',
            '/checkout',
            new WriteSafetyRequest(
                headers: ['idempotency-key' => 'payment-2'],
                method: 'POST',
                json: ['amount' => 1200]
            )
        );

        $calls = 0;
        $next = static function () use (&$calls): Response {
            $calls++;
            return new Response(['call' => $calls], 201);
        };

        $first = $middleware->handle($context, $next);
        $second = $middleware->handle($context, $next);

        self::assertSame(1, $calls);
        self::assertInstanceOf(Response::class, $first);
        self::assertInstanceOf(Response::class, $second);
        self::assertSame(['call' => 1], $second->body);
        self::assertSame('true', $second->headers['Idempotency-Replayed']);
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
        $middleware->handle($context, static fn () => null);
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
