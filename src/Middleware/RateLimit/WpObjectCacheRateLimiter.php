<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\RateLimit;

use RuntimeException;

final class WpObjectCacheRateLimiter implements RateLimiterInterface
{
    /** @var callable(): int */
    private $now;

    /**
     * @param null|callable(): int $now
     */
    public function __construct(
        private readonly string $group = 'better_route_rate_limit',
        ?callable $now = null
    ) {
        foreach (['wp_cache_add', 'wp_cache_get', 'wp_cache_set'] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException($function . ' is unavailable.');
            }
        }

        $this->now = $now ?? static fn (): int => time();
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $now = ($this->now)();
        $ttl = max(1, $windowSeconds);
        $countKey = $this->storageKey($key, 'count');
        $resetKey = $this->storageKey($key, 'reset');
        $resetAt = $now + $ttl;

        if (wp_cache_add($countKey, 1, $this->group, $ttl)) {
            wp_cache_set($resetKey, $resetAt, $this->group, $ttl);
            return new RateLimitResult(true, max($limit - 1, 0), $resetAt);
        }

        $storedResetAt = wp_cache_get($resetKey, $this->group);
        if (is_int($storedResetAt) && $storedResetAt > $now) {
            $resetAt = $storedResetAt;
        } else {
            wp_cache_set($countKey, 1, $this->group, $ttl);
            wp_cache_set($resetKey, $resetAt, $this->group, $ttl);
            return new RateLimitResult(true, max($limit - 1, 0), $resetAt);
        }

        $count = false;
        if (function_exists('wp_cache_incr')) {
            $count = wp_cache_incr($countKey, 1, $this->group);
        }

        if (!is_int($count)) {
            $storedCount = wp_cache_get($countKey, $this->group);
            $count = is_int($storedCount) ? $storedCount + 1 : 1;
            wp_cache_set($countKey, $count, $this->group, max($resetAt - $now, 1));
        }

        return new RateLimitResult(
            allowed: $count <= $limit,
            remaining: max($limit - $count, 0),
            resetAt: $resetAt
        );
    }

    private function storageKey(string $key, string $suffix): string
    {
        return sha1($key) . ':' . $suffix;
    }
}
