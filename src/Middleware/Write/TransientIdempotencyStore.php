<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use RuntimeException;

final class TransientIdempotencyStore implements IdempotencyStoreInterface
{
    /** @var callable(string): mixed */
    private $getTransient;

    /** @var callable(string, mixed, int): bool */
    private $setTransient;

    /**
     * @param null|callable(string): mixed $getTransient
     * @param null|callable(string, mixed, int): bool $setTransient
     */
    public function __construct(
        ?callable $getTransient = null,
        ?callable $setTransient = null
    ) {
        $this->getTransient = $getTransient ?? $this->defaultGetTransient();
        $this->setTransient = $setTransient ?? $this->defaultSetTransient();
    }

    public function get(string $key): mixed
    {
        return ($this->getTransient)($this->storageKey($key));
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $ttlSeconds = max(1, $ttlSeconds);
        ($this->setTransient)($this->storageKey($key), $value, $ttlSeconds);
    }

    private function storageKey(string $key): string
    {
        return 'better_route_idem_' . sha1($key);
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
