<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\Auth\ApplicationPasswordAuthMiddleware;
use BetterRoute\Middleware\Auth\AuthContext;
use BetterRoute\Middleware\Auth\AuthIdentity;
use BetterRoute\Middleware\Auth\BearerTokenAuthMiddleware;
use BetterRoute\Middleware\Auth\BearerTokenVerifierInterface;
use BetterRoute\Middleware\Auth\ClaimsUserMapperInterface;
use BetterRoute\Middleware\Jwt\JwtAuthMiddleware;
use BetterRoute\Middleware\Jwt\JwtVerifierInterface;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class AuthScopeTest extends TestCase
{
    public function testNestedIdentitiesRestoreAfterSuccessAndException(): void
    {
        $user = 80;
        $setter = static function (int $id) use (&$user): void {
            $user = $id;
        };
        $getter = static function () use (&$user): int {
            return $user;
        };
        $verifier = new class () implements JwtVerifierInterface, BearerTokenVerifierInterface {
            public function verify(string $token): array
            {
                return ['sub' => 'external'];
            }
        };
        $mapper = new class () implements ClaimsUserMapperInterface {
            public function mapUserId(array $claims, RequestContext $context): ?int
            {
                return 7;
            }
        };
        $outer = new JwtAuthMiddleware($verifier, userMapper: $mapper, setCurrentUser: $setter, getCurrentUser: $getter);
        $inner = new BearerTokenAuthMiddleware($verifier, setCurrentUser: $setter, getCurrentUser: $getter);
        $request = new class () {
            public function get_header(string $name): string
            {
                return 'Bearer test-token';
            }
        };
        $context = new RequestContext('scope', '/scope', $request);
        $outer->handle($context, static function (RequestContext $context) use (&$user, $inner): void {
            self::assertSame(7, $user);
            try {
                $inner->handle($context, static function (RequestContext $context) use (&$user): void {
                    self::assertSame(0, $user);
                    self::assertNull($context->attributes['userId']);
                    throw new RuntimeException('downstream');
                });
                self::fail('Expected downstream exception.');
            } catch (RuntimeException $error) {
                self::assertSame('downstream', $error->getMessage());
            }
            self::assertSame(7, $user);
        });
        self::assertSame(80, $user);
    }

    public function testApplicationPasswordRestoresAmbientUserWhenHandlerThrows(): void
    {
        $user = 12;
        $middleware = new ApplicationPasswordAuthMiddleware(
            authenticate: static fn (): object => (object) ['ID' => 99],
            setCurrentUser: static function (int $id) use (&$user): void {
                $user = $id;
            },
            getCurrentUser: static function () use (&$user): int {
                return $user;
            }
        );
        $request = new class () {
            public function get_header(string $name): string
            {
                return 'Basic ' . base64_encode('test:credential');
            }
        };
        try {
            $middleware->handle(new RequestContext('app', '/scope', $request), static function () use (&$user): void {
                self::assertSame(99, $user);
                throw new RuntimeException('downstream');
            });
            self::fail('Expected downstream exception.');
        } catch (RuntimeException $error) {
            self::assertSame('downstream', $error->getMessage());
        }
        self::assertSame(12, $user);
    }

    public function testReplacingIdentityClearsEveryDerivedAttribute(): void
    {
        $context = AuthContext::withIdentity(new RequestContext('auth', '/scope'), new AuthIdentity(
            provider: 'outer',
            userId: 123,
            claims: ['role' => 'admin'],
            scopes: ['admin'],
            user: (object) ['ID' => 123]
        ));
        $context = AuthContext::withIdentity($context, new AuthIdentity(provider: 'inner'));
        self::assertNull($context->attributes['userId']);
        self::assertNull($context->attributes['user']);
        self::assertSame([], $context->attributes['claims']);
        self::assertSame([], $context->attributes['scopes']);
    }
}
