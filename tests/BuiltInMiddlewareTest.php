<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\ClientIpResolver;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\Audit\AuditEnricherMiddleware;
use BetterRoute\Middleware\Audit\AuditLoggerInterface;
use BetterRoute\Middleware\Audit\AuditMiddleware;
use BetterRoute\Middleware\Cache\CacheStoreInterface;
use BetterRoute\Middleware\Cache\CachingMiddleware;
use BetterRoute\Middleware\Cache\ETagMiddleware;
use BetterRoute\Middleware\Cors\CorsMiddleware;
use BetterRoute\Middleware\Cors\CorsPolicy;
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

    public function testRateLimitMiddlewareWrapsArrayResponsesWithHeaders(): void
    {
        $middleware = new RateLimitMiddleware(
            limiter: new FakeRateLimiter(new RateLimitResult(true, 4, 1730000000)),
            limit: 10,
            windowSeconds: 60
        );

        $context = new RequestContext('req_rate_array', '/rate', new MiddlewareRequest([]));
        $response = $middleware->handle($context, static fn (): array => ['ok' => true]);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(['ok' => true], $response->body);
        self::assertSame('4', $response->headers['X-RateLimit-Remaining']);
    }

    public function testCorsMiddlewareHandlesPreflightAndAddsHeaders(): void
    {
        $middleware = new CorsMiddleware(new CorsPolicy(
            allowedOrigins: ['https://app.example.com'],
            allowCredentials: true
        ));

        $preflight = new RequestContext('req_cors_preflight', '/items', new MiddlewareRequest([
            'origin' => 'https://app.example.com',
            'access-control-request-method' => 'POST',
        ], 'OPTIONS'));

        $response = $middleware->handle($preflight, static fn (): array => ['shouldNotRun' => true]);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(204, $response->status);
        self::assertSame('https://app.example.com', $response->headers['Access-Control-Allow-Origin']);
        self::assertSame('true', $response->headers['Access-Control-Allow-Credentials']);

        $normal = new RequestContext('req_cors_get', '/items', new MiddlewareRequest([
            'origin' => 'https://app.example.com',
        ], 'GET'));
        $normalResponse = $middleware->handle($normal, static fn (): array => ['ok' => true]);

        self::assertInstanceOf(Response::class, $normalResponse);
        self::assertSame(['ok' => true], $normalResponse->body);
        self::assertSame('https://app.example.com', $normalResponse->headers['Access-Control-Allow-Origin']);
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

    public function testCachingMiddlewareSeparatesAuthIdentities(): void
    {
        $store = new FakeCacheStore();
        $middleware = new CachingMiddleware($store, 60);

        $contextA = new RequestContext('req_cache_a', '/cache', new MiddlewareRequest([], 'GET', ['page' => 1]), [
            'auth' => ['provider' => 'jwt', 'userId' => 1],
        ]);
        $contextB = new RequestContext('req_cache_b', '/cache', new MiddlewareRequest([], 'GET', ['page' => 1]), [
            'auth' => ['provider' => 'jwt', 'userId' => 2],
        ]);

        $calls = 0;
        $next = static function () use (&$calls): array {
            $calls++;
            return ['call' => $calls];
        };

        self::assertSame(['call' => 1], $middleware->handle($contextA, $next));
        self::assertSame(['call' => 2], $middleware->handle($contextB, $next));
        self::assertSame(['call' => 1], $middleware->handle($contextA, $next));
        self::assertSame(2, $calls);
    }

    public function testRateLimitDefaultKeyIncludesAuthIdentity(): void
    {
        $limiter = new FakeRateLimiter(new RateLimitResult(true, 5, 1730000000));
        $middleware = new RateLimitMiddleware($limiter, limit: 10, windowSeconds: 60);

        $context = new RequestContext('req_rate_auth', '/rate', new MiddlewareRequest([]), [
            'auth' => ['provider' => 'jwt', 'subject' => 'subject-1'],
        ]);

        $middleware->handle($context, static fn (): Response => new Response(['ok' => true], 200));

        self::assertSame('/rate|jwt:sub:subject-1', $limiter->lastKey);
    }

    public function testETagMiddlewareAddsHeaderAndReturnsNotModified(): void
    {
        $middleware = new ETagMiddleware();
        $context = new RequestContext('req_etag', '/etag', new MiddlewareRequest([], 'GET'));

        $first = $middleware->handle($context, static fn (): array => ['ok' => true]);
        self::assertInstanceOf(Response::class, $first);
        self::assertSame(200, $first->status);
        self::assertArrayHasKey('ETag', $first->headers);

        $secondContext = new RequestContext('req_etag_2', '/etag', new MiddlewareRequest([
            'if-none-match' => $first->headers['ETag'],
        ], 'GET'));
        $second = $middleware->handle($secondContext, static fn (): array => ['ok' => true]);

        self::assertInstanceOf(Response::class, $second);
        self::assertSame(304, $second->status);
    }

    public function testClientIpResolverOnlyTrustsForwardedHeaderFromTrustedProxy(): void
    {
        $resolver = new ClientIpResolver(trustedProxies: ['10.0.0.1']);

        self::assertSame('203.0.113.10', $resolver->resolve([
            'REMOTE_ADDR' => '10.0.0.1',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10, 10.0.0.1',
        ]));
        self::assertSame('198.51.100.2', $resolver->resolve([
            'REMOTE_ADDR' => '198.51.100.2',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.10',
        ]));
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

    public function testAuditEnricherAddsSafeContextFields(): void
    {
        $logger = new FakeAuditLogger();
        $enricher = new AuditEnricherMiddleware(['resource' => 'orders']);
        $audit = new AuditMiddleware($logger);
        $context = new RequestContext(
            'req_audit_extra',
            '/orders',
            new MiddlewareRequest(['idempotency-key' => 'raw-key'], 'POST'),
            ['auth' => ['provider' => 'jwt', 'userId' => 42]]
        );

        $enricher->handle($context, static fn (RequestContext $ctx): mixed => $audit->handle(
            $ctx,
            static fn (): Response => new Response(['ok' => true], 200)
        ));

        self::assertSame('jwt', $logger->events[0]['authProvider']);
        self::assertSame(42, $logger->events[0]['authUserId']);
        self::assertSame('orders', $logger->events[0]['resource']);
        self::assertSame(sha1('raw-key'), $logger->events[0]['idempotencyKey']);
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
    public ?string $lastKey = null;

    public function __construct(private readonly RateLimitResult $result)
    {
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $this->lastKey = $key;
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
