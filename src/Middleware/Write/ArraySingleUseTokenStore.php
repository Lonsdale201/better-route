<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

final class ArraySingleUseTokenStore implements SingleUseTokenStoreInterface
{
    /** @var array<string, array{context: array<string, mixed>, expires_at: int}> */
    private array $items = [];

    /** @var array<string, int> */
    private array $consumed = [];

    public function consume(string $tokenHash): ?array
    {
        $this->prune();
        $item = $this->items[$tokenHash] ?? null;
        if (!is_array($item)) {
            return null;
        }

        unset($this->items[$tokenHash]);
        $this->consumed[$tokenHash] = $item['expires_at'];

        return $item['context'];
    }

    public function store(string $tokenHash, array $context, int $ttlSeconds): void
    {
        $this->prune();
        unset($this->consumed[$tokenHash]);
        $this->items[$tokenHash] = [
            'context' => $context,
            'expires_at' => time() + max(1, $ttlSeconds),
        ];
    }

    public function wasConsumed(string $tokenHash): bool
    {
        $this->prune();
        return isset($this->consumed[$tokenHash]);
    }

    private function prune(): void
    {
        $now = time();
        foreach ($this->items as $tokenHash => $item) {
            if ($item['expires_at'] <= $now) {
                unset($this->items[$tokenHash]);
            }
        }

        foreach ($this->consumed as $tokenHash => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->consumed[$tokenHash]);
            }
        }
    }
}
