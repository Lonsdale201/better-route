<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

interface IdempotencyStoreInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value, int $ttlSeconds): void;
}
