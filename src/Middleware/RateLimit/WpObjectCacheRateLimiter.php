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

        if (function_exists('wp_using_ext_object_cache') && !wp_using_ext_object_cache()) {
            throw new RuntimeException('A persistent external object cache is required for rate limiting.');
        }
        if (!function_exists('wp_cache_incr')) {
            throw new RuntimeException('wp_cache_incr is required for atomic rate limiting.');
        }

        $this->now = $now ?? static fn (): int => time();
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        if ($limit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Rate limit and window must be greater than 0.');
        }

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
            // The reset key may have been evicted independently. Never reset an
            // existing counter to 1, because that creates a rate-limit bypass.
            $count = wp_cache_incr($countKey, 1, $this->group);
            if (!is_int($count)) {
                throw new RuntimeException('Persistent object cache does not support atomic increment.');
            }
            wp_cache_set($resetKey, $resetAt, $this->group, $ttl);
            return new RateLimitResult($count <= $limit, max($limit - $count, 0), $resetAt);
        }

        $count = wp_cache_incr($countKey, 1, $this->group);
        if (!is_int($count)) {
            throw new RuntimeException('Persistent object cache does not support atomic increment.');
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
