<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

interface SingleUseTokenStoreInterface
{
    /**
     * @return array<string, mixed>|null
     */
    public function consume(string $tokenHash): ?array;

    /**
     * @param array<string, mixed> $context
     */
    public function store(string $tokenHash, array $context, int $ttlSeconds): void;

    public function wasConsumed(string $tokenHash): bool;
}
