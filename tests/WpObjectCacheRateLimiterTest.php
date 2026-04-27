<?php

declare(strict_types=1);

namespace {
    if (!function_exists('wp_cache_add')) {
        function wp_cache_add(string $key, mixed $value, string $group = '', int $expire = 0): bool
        {
            $GLOBALS['better_route_test_object_cache'] ??= [];
            $cacheKey = $group . ':' . $key;
            if (array_key_exists($cacheKey, $GLOBALS['better_route_test_object_cache'])) {
                return false;
            }

            $GLOBALS['better_route_test_object_cache'][$cacheKey] = $value;
            return true;
        }
    }

    if (!function_exists('wp_cache_get')) {
        function wp_cache_get(string $key, string $group = ''): mixed
        {
            $GLOBALS['better_route_test_object_cache'] ??= [];
            return $GLOBALS['better_route_test_object_cache'][$group . ':' . $key] ?? false;
        }
    }

    if (!function_exists('wp_cache_set')) {
        function wp_cache_set(string $key, mixed $value, string $group = '', int $expire = 0): bool
        {
            $GLOBALS['better_route_test_object_cache'] ??= [];
            $GLOBALS['better_route_test_object_cache'][$group . ':' . $key] = $value;
            return true;
        }
    }

    if (!function_exists('wp_cache_incr')) {
        function wp_cache_incr(string $key, int $offset = 1, string $group = ''): int|false
        {
            $GLOBALS['better_route_test_object_cache'] ??= [];
            $cacheKey = $group . ':' . $key;
            if (!isset($GLOBALS['better_route_test_object_cache'][$cacheKey]) || !is_int($GLOBALS['better_route_test_object_cache'][$cacheKey])) {
                return false;
            }

            $GLOBALS['better_route_test_object_cache'][$cacheKey] += $offset;
            return $GLOBALS['better_route_test_object_cache'][$cacheKey];
        }
    }
}

namespace BetterRoute\Tests {
    use BetterRoute\Middleware\RateLimit\WpObjectCacheRateLimiter;
    use PHPUnit\Framework\TestCase;

    final class WpObjectCacheRateLimiterTest extends TestCase
    {
        protected function setUp(): void
        {
            $GLOBALS['better_route_test_object_cache'] = [];
        }

        protected function tearDown(): void
        {
            unset($GLOBALS['better_route_test_object_cache']);
        }

        public function testTracksHitsInObjectCache(): void
        {
            $limiter = new WpObjectCacheRateLimiter(now: static fn (): int => 100);

            $first = $limiter->hit('route|user:1', 2, 60);
            $second = $limiter->hit('route|user:1', 2, 60);
            $third = $limiter->hit('route|user:1', 2, 60);

            self::assertTrue($first->allowed);
            self::assertTrue($second->allowed);
            self::assertFalse($third->allowed);
            self::assertSame(160, $third->resetAt);
        }
    }
}
