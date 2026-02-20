<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\RateLimit;

use RuntimeException;

final class TransientRateLimiter implements RateLimiterInterface
{
    /** @var callable(string): mixed */
    private $getTransient;

    /** @var callable(string, mixed, int): bool */
    private $setTransient;

    /** @var callable(): int */
    private $now;

    /**
     * @param null|callable(string): mixed $getTransient
     * @param null|callable(string, mixed, int): bool $setTransient
     * @param null|callable(): int $now
     */
    public function __construct(
        ?callable $getTransient = null,
        ?callable $setTransient = null,
        ?callable $now = null
    ) {
        $this->getTransient = $getTransient ?? $this->defaultGetTransient();
        $this->setTransient = $setTransient ?? $this->defaultSetTransient();
        $this->now = $now ?? static fn (): int => time();
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        $now = ($this->now)();
        $storageKey = $this->storageKey($key);

        $state = ($this->getTransient)($storageKey);
        $count = 0;
        $resetAt = $now + $windowSeconds;

        if (is_array($state) && isset($state['count'], $state['resetAt'])) {
            $storedCount = is_int($state['count']) ? $state['count'] : null;
            $storedResetAt = is_int($state['resetAt']) ? $state['resetAt'] : null;

            if ($storedCount !== null && $storedResetAt !== null && $storedResetAt > $now) {
                $count = $storedCount;
                $resetAt = $storedResetAt;
            }
        }

        $count++;
        $allowed = $count <= $limit;
        $remaining = max($limit - $count, 0);

        $ttl = max($resetAt - $now, 1);
        ($this->setTransient)($storageKey, ['count' => $count, 'resetAt' => $resetAt], $ttl);

        return new RateLimitResult($allowed, $remaining, $resetAt);
    }

    private function storageKey(string $key): string
    {
        return 'better_route_rl_' . sha1($key);
    }

    /**
     * @return callable(string): mixed
     */
    private function defaultGetTransient(): callable
    {
        if (!function_exists('get_transient')) {
            throw new RuntimeException('get_transient is unavailable.');
        }

        return static fn (string $key): mixed => get_transient($key);
    }

    /**
     * @return callable(string, mixed, int): bool
     */
    private function defaultSetTransient(): callable
    {
        if (!function_exists('set_transient')) {
            throw new RuntimeException('set_transient is unavailable.');
        }

        return static fn (string $key, mixed $value, int $ttl): bool => (bool) set_transient($key, $value, $ttl);
    }
}
