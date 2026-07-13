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

    /** @var callable(string, callable): mixed */
    private $synchronize;

    /**
     * @param null|callable(string): mixed $getTransient
     * @param null|callable(string, mixed, int): bool $setTransient
     * @param null|callable(): int $now
     * @param null|callable(string, callable): mixed $synchronize
     */
    public function __construct(
        ?callable $getTransient = null,
        ?callable $setTransient = null,
        ?callable $now = null,
        ?callable $synchronize = null
    ) {
        $usesWordPressTransients = $getTransient === null && $setTransient === null;
        $this->getTransient = $getTransient ?? $this->defaultGetTransient();
        $this->setTransient = $setTransient ?? $this->defaultSetTransient();
        $this->now = $now ?? static fn (): int => time();
        $this->synchronize = $synchronize
            ?? ($usesWordPressTransients ? $this->defaultSynchronizer() : static fn (string $key, callable $callback): mixed => $callback());
    }

    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult
    {
        if ($limit < 1 || $windowSeconds < 1) {
            throw new \InvalidArgumentException('Rate limit and window must be greater than 0.');
        }

        return ($this->synchronize)($this->storageKey($key), fn (): RateLimitResult => $this->hitLocked(
            $key,
            $limit,
            $windowSeconds
        ));
    }

    private function hitLocked(string $key, int $limit, int $windowSeconds): RateLimitResult
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
        if (!(bool) ($this->setTransient)($storageKey, ['count' => $count, 'resetAt' => $resetAt], $ttl)) {
            throw new RuntimeException('Unable to persist rate-limit state.');
        }

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

    /**
     * @return callable(string, callable): mixed
     */
    private function defaultSynchronizer(): callable
    {
        if (
            !isset($GLOBALS['wpdb'])
            || !is_object($GLOBALS['wpdb'])
            || !method_exists($GLOBALS['wpdb'], 'prepare')
            || !method_exists($GLOBALS['wpdb'], 'get_var')
        ) {
            throw new RuntimeException('Global $wpdb is required for atomic transient rate limiting.');
        }

        return static function (string $key, callable $callback): mixed {
            $wpdb = $GLOBALS['wpdb'];
            $lockName = 'better_route_rl_' . sha1($key);
            $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 2));
            if ((int) $acquired !== 1) {
                throw new RuntimeException('Unable to acquire rate-limit lock.');
            }

            try {
                return $callback();
            } finally {
                $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
            }
        };
    }
}
