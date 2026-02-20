<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Cache;

interface CacheStoreInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;
}
