<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\Auth\ApplicationPasswordAuthMiddleware;
use BetterRoute\Middleware\Auth\BearerTokenAuthMiddleware;
use BetterRoute\Middleware\Auth\BearerTokenVerifierInterface;
use BetterRoute\Middleware\Auth\ClaimsUserMapperInterface;
use BetterRoute\Middleware\Auth\CookieNonceAuthMiddleware;
use BetterRoute\Middleware\Jwt\JwtAuthMiddleware;
use BetterRoute\Middleware\Jwt\JwtVerifierInterface;
use PHPUnit\Framework\TestCase;

final class AuthBridgeMiddlewareTest extends TestCase
{
    public function testJwtMiddlewareMapsUserAndPopulatesAuthContext(): void
    {
        $setUserId = null;

        $middleware = new JwtAuthMiddleware(
            verifier: new AuthBridgeJwtVerifier(['sub' => 'sub-1', 'scope' => 'content:read']),
            requiredScopes: ['content:*'],
            userMapper: new AuthBridgeClaimsMapper(7),
            setCurrentUser: static function (int $userId) use (&$setUserId): void {
                $setUserId = $userId;
            }
        );

        $context = new RequestContext('req_auth_jwt', '/secure', new AuthBridgeRequest([
            'authorization' => 'Bearer token-a',
        ]));

        $result = $middleware->handle($context, static fn (RequestContext $ctx): array => $ctx->attributes['auth']);

        self::assertSame(7, $setUserId);
        self::assertSame('jwt', $result['provider']);
        self::assertSame(7, $result['userId']);
    }

    public function testBearerMiddlewareUsesVerifierAndMapper(): void
    {
        $middleware = new BearerTokenAuthMiddleware(
            verifier: new AuthBridgeBearerVerifier(['sub' => 'u-9', 'scopes' => ['api:read']]),
            requiredScopes: ['api:*'],
            userMapper: new AuthBridgeClaimsMapper(9)
        );

        $context = new RequestContext('req_auth_bearer', '/secure', new AuthBridgeRequest([
            'authorization' => 'Bearer token-b',
        ]));

        $result = $middleware->handle($context, static fn (RequestContext $ctx): array => $ctx->attributes['auth']);

        self::assertSame('bearer', $result['provider']);
        self::assertSame(9, $result['userId']);
        self::assertSame('u-9', $result['subject']);
    }

    public function testCookieNonceMiddlewareValidatesAndSetsUser(): void
    {
        $middleware = new CookieNonceAuthMiddleware(
            nonceAction: 'wp_rest',
            requireNonce: true,
            requireLoggedIn: true,
            isLoggedIn: static fn (): bool => true,
            verifyNonce: static fn (string $nonce, string $action): bool => $nonce === 'nonce-123' && $action === 'wp_rest',
            currentUserId: static fn (): int => 42
        );

        $context = new RequestContext('req_auth_cookie', '/secure', new AuthBridgeRequest([
            'x-wp-nonce' => 'nonce-123',
        ]));

        $result = $middleware->handle($context, static fn (RequestContext $ctx): array => $ctx->attributes['auth']);

        self::assertSame('cookie_nonce', $result['provider']);
        self::assertSame(42, $result['userId']);
    }

    public function testApplicationPasswordMiddlewareAuthenticatesBasicAuth(): void
    {
        $setUserId = null;

        $middleware = new ApplicationPasswordAuthMiddleware(
            authenticate: static function (string $username, string $password): object {
                if ($username !== 'app-user' || $password !== 'secret-1') {
                    return (object) ['ID' => 0];
                }

                return (object) ['ID' => 99, 'user_login' => $username];
            },
            setCurrentUser: static function (int $userId) use (&$setUserId): void {
                $setUserId = $userId;
            }
        );

        $header = 'Basic ' . base64_encode('app-user:secret-1');
        $context = new RequestContext('req_auth_app', '/secure', new AuthBridgeRequest([
            'authorization' => $header,
        ]));

        $result = $middleware->handle($context, static fn (RequestContext $ctx): array => $ctx->attributes['auth']);

        self::assertSame(99, $setUserId);
        self::assertSame('application_password', $result['provider']);
        self::assertSame(99, $result['userId']);
    }

    public function testCookieNonceMiddlewareRejectsInvalidNonce(): void
    {
        $middleware = new CookieNonceAuthMiddleware(
            isLoggedIn: static fn (): bool => true,
            verifyNonce: static fn (): bool => false,
            currentUserId: static fn (): int => 1
        );

        $context = new RequestContext('req_auth_cookie_2', '/secure', new AuthBridgeRequest([
            'x-wp-nonce' => 'bad',
        ]));

        $this->expectException(ApiException::class);
        $middleware->handle($context, static fn (): null => null);
    }
}

final class AuthBridgeRequest
{
    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $params
     * @param array<string, mixed> $json
     * @param array<string, mixed> $body
     */
    public function __construct(
        private readonly array $headers,
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

    public function get_param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_params(): array
    {
        return $this->params;
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

    public function get_method(): string
    {
        return $this->method;
    }
}

final class AuthBridgeJwtVerifier implements JwtVerifierInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(private readonly array $claims)
    {
    }

    public function verify(string $token): array
    {
        return $this->claims;
    }
}

final class AuthBridgeBearerVerifier implements BearerTokenVerifierInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(private readonly array $claims)
    {
    }

    public function verify(string $token): array
    {
        return $this->claims;
    }
}

final class AuthBridgeClaimsMapper implements ClaimsUserMapperInterface
{
    public function __construct(private readonly int $userId)
    {
    }

    public function mapUserId(array $claims, RequestContext $context): ?int
    {
        return $this->userId;
    }
}
