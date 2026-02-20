<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Middleware\Audit\ErrorLogAuditLogger;
use BetterRoute\Middleware\Cache\TransientCacheStore;
use BetterRoute\Middleware\Jwt\Hs256JwtVerifier;
use BetterRoute\Middleware\RateLimit\TransientRateLimiter;
use PHPUnit\Framework\TestCase;

final class WpMiddlewareAdaptersTest extends TestCase
{
    public function testTransientRateLimiterTracksHitsWithinWindow(): void
    {
        /** @var array<string, mixed> $storage */
        $storage = [];
        $now = 1000;

        $limiter = new TransientRateLimiter(
            getTransient: static function (string $key) use (&$storage): mixed {
                return $storage[$key] ?? false;
            },
            setTransient: static function (string $key, mixed $value, int $ttl) use (&$storage): bool {
                $storage[$key] = $value;
                return true;
            },
            now: static fn (): int => $now
        );

        $first = $limiter->hit('route-a', 2, 60);
        $second = $limiter->hit('route-a', 2, 60);
        $third = $limiter->hit('route-a', 2, 60);

        self::assertTrue($first->allowed);
        self::assertSame(1, $first->remaining);
        self::assertTrue($second->allowed);
        self::assertFalse($third->allowed);
        self::assertSame(0, $third->remaining);
    }

    public function testTransientCacheStoreGetSet(): void
    {
        /** @var array<string, mixed> $storage */
        $storage = [];

        $cache = new TransientCacheStore(
            getTransient: static function (string $key) use (&$storage): mixed {
                return $storage[$key] ?? null;
            },
            setTransient: static function (string $key, mixed $value, int $ttl) use (&$storage): bool {
                $storage[$key] = $value;
                return true;
            }
        );

        $cache->set('item-a', ['ok' => true], 10);

        self::assertSame(['ok' => true], $cache->get('item-a'));
    }

    public function testHs256JwtVerifierValidatesToken(): void
    {
        $now = 1700000000;
        $claims = [
            'sub' => 'user-1',
            'scope' => 'content:read',
            'exp' => $now + 120,
        ];

        $token = $this->signHs256Token(['alg' => 'HS256', 'typ' => 'JWT'], $claims, 'secret-123');
        $verifier = new Hs256JwtVerifier('secret-123', now: static fn (): int => $now);

        $decoded = $verifier->verify($token);
        self::assertSame('user-1', $decoded['sub']);
    }

    public function testHs256JwtVerifierRejectsExpiredToken(): void
    {
        $now = 1700000000;
        $token = $this->signHs256Token(
            ['alg' => 'HS256', 'typ' => 'JWT'],
            ['sub' => 'user-1', 'exp' => $now - 1],
            'secret-123'
        );

        $verifier = new Hs256JwtVerifier('secret-123', now: static fn (): int => $now);

        $this->expectException(\RuntimeException::class);
        $verifier->verify($token);
    }

    public function testErrorLogAuditLoggerWritesJsonEvent(): void
    {
        $lines = [];

        $logger = new ErrorLogAuditLogger(
            writer: static function (string $line) use (&$lines): void {
                $lines[] = $line;
            }
        );

        $logger->log(['requestId' => 'req_1', 'status' => 'ok']);

        self::assertCount(1, $lines);
        self::assertStringContainsString('[better-route]', $lines[0]);
        self::assertStringContainsString('"requestId":"req_1"', $lines[0]);
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     */
    private function signHs256Token(array $header, array $payload, string $secret): string
    {
        $encodedHeader = $this->base64UrlEncode((string) json_encode($header));
        $encodedPayload = $this->base64UrlEncode((string) json_encode($payload));
        $signature = hash_hmac('sha256', $encodedHeader . '.' . $encodedPayload, $secret, true);

        return $encodedHeader . '.' . $encodedPayload . '.' . $this->base64UrlEncode($signature);
    }

    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
