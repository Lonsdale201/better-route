<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

interface AtomicIdempotencyStoreInterface
{
    public function reserve(string $key, string $fingerprint, int $ttlSeconds): AtomicIdempotencyRecord;

    public function complete(string $key, string $fingerprint, mixed $response, int $ttlSeconds): void;

    public function release(string $key, string $fingerprint): void;
}
