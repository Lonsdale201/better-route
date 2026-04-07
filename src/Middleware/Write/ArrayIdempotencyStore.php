<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

final class ArrayIdempotencyStore implements IdempotencyStoreInterface
{
    /**
     * @var array<string, array{value: mixed, expiresAt: int}>
     */
    private array $items = [];

    public function get(string $key): mixed
    {
        $this->gc();

        if (!array_key_exists($key, $this->items)) {
            return false;
        }

        return $this->items[$key]['value'];
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $ttl = max(1, $ttlSeconds);
        $this->items[$key] = [
            'value' => $value,
            'expiresAt' => time() + $ttl,
        ];
    }

    private function gc(): void
    {
        $now = time();
        foreach ($this->items as $key => $item) {
            if (($item['expiresAt'] ?? 0) <= $now) {
                unset($this->items[$key]);
            }
        }
    }
}
