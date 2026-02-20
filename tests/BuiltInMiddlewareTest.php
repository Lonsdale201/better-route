<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\Audit\AuditLoggerInterface;
use BetterRoute\Middleware\Audit\AuditMiddleware;
use BetterRoute\Middleware\Cache\CacheStoreInterface;
use BetterRoute\Middleware\Cache\CachingMiddleware;
use BetterRoute\Middleware\Jwt\JwtAuthMiddleware;
use BetterRoute\Middleware\Jwt\JwtVerifierInterface;
use BetterRoute\Middleware\RateLimit\RateLimiterInterface;
use BetterRoute\Middleware\RateLimit\RateLimitMiddleware;
use BetterRoute\Middleware\RateLimit\RateLimitResult;
use PHPUnit\Framework\TestCase;

final class BuiltInMiddlewareTest extends TestCase
{
    public function testJwtMiddlewareAcceptsValidToken(): void
    {
        $middleware = new JwtAuthMiddleware(
            verifier: new FakeJwtVerifier(['scopes' => ['content:read']]),
            requiredScopes: ['content:*']
        );

        $context = new RequestContext(
            requestId: 'req_jwt',
            routePath: '/secure',
            request: new MiddlewareRequest(['authorization' => 'Bearer token-123'])
        );

        $result = $middleware->handle($context, static fn (RequestContext $ctx): string => (string) $ctx->attributes['claims']['sub']);
        self::assertSame('user-1', $result);
    }

    public function testJwtMiddlewareRejectsMissingToken(): void
    {
        $middleware = new JwtAuthMiddleware(new FakeJwtVerifier(['scopes' => ['content:read']]));
        $context = new RequestContext('req_jwt_2', '/secure', new MiddlewareRequest([]));

        $this->expectException(ApiException::class);
        $middleware->handle($context, static fn () => null);
    }

    public function testRateLimitMiddlewareAddsHeaders(): void
    {
        $middleware = new RateLimitMiddleware(
            limiter: new FakeRateLimiter(new RateLimitResult(true, 5, 1730000000)),
            limit: 10,
            windowSeconds: 60
        );

        $context = new RequestContext('req_rate', '/rate', new MiddlewareRequest([]));
        $response = $middleware->handle($context, static fn (): Response => new Response(['ok' => true], 200));

        self::assertInstanceOf(Response::class, $response);
        self::assertSame('10', $response->headers['X-RateLimit-Limit']);
        self::assertSame('5', $response->headers['X-RateLimit-Remaining']);
    }

    public function testCachingMiddlewareCachesGetResponses(): void
    {
        $store = new FakeCacheStore();
        $middleware = new CachingMiddleware($store, 60);
        $context = new RequestContext('req_cache', '/cache', new MiddlewareRequest([], 'GET', ['page' => 1]));

        $calls = 0;
        $next = static function () use (&$calls): array {
            $calls++;
            return ['ok' => true];
        };

        $first = $middleware->handle($context, $next);
        $second = $middleware->handle($context, $next);

        self::assertSame(['ok' => true], $first);
        self::assertSame(['ok' => true], $second);
        self::assertSame(1, $calls);
    }

    public function testAuditMiddlewareLogsSuccessAndErrors(): void
    {
        $logger = new FakeAuditLogger();
        $middleware = new AuditMiddleware($logger);
        $context = new RequestContext('req_audit', '/audit', new MiddlewareRequest([]));

        $middleware->handle($context, static fn (): array => ['ok' => true]);

        try {
            $middleware->handle($context, static function (): never {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        self::assertCount(2, $logger->events);
        self::assertSame('ok', $logger->events[0]['status']);
        self::assertSame('error', $logger->events[1]['status']);
    }
}

final class FakeJwtVerifier implements JwtVerifierInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(private readonly array $claims)
    {
    }

    public function verify(string $token): array
    {
        return array_merge(['sub' => 'user-1'], $this->claims);
    }
}

final class FakeRateLimiter implements RateLimiterInterface
{
    public function __construct(private readonly RateLimitResult $result)
    {
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        return $this->result;
    }
}

final class FakeCacheStore implements CacheStoreInterface
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

final class FakeAuditLogger implements AuditLoggerInterface
{
    /** @var list<array<string, mixed>> */
    public array $events = [];

    public function log(array $event): void
    {
        $this->events[] = $event;
    }
}

final class MiddlewareRequest
{
    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $params
     */
    public function __construct(
        private readonly array $headers,
        private readonly string $method = 'GET',
        private readonly array $params = []
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
    public function get_params(): array
    {
        return $this->params;
    }
}
