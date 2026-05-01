<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

final class ArrayAtomicIdempotencyStore implements AtomicIdempotencyStoreInterface
{
    /** @var array<string, array{fingerprint: string, status: string, response: mixed, expires_at: int}> */
    private array $items = [];

    public function reserve(string $key, string $fingerprint, int $ttlSeconds): AtomicIdempotencyRecord
    {
        $now = time();
        $existing = $this->items[$key] ?? null;
        if (is_array($existing) && $existing['expires_at'] <= $now) {
            unset($this->items[$key]);
            $existing = null;
        }

        if (!is_array($existing)) {
            $this->items[$key] = [
                'fingerprint' => $fingerprint,
                'status' => AtomicIdempotencyRecord::IN_PROGRESS,
                'response' => null,
                'expires_at' => $now + max(1, $ttlSeconds),
            ];

            return new AtomicIdempotencyRecord(AtomicIdempotencyRecord::RESERVED, $fingerprint);
        }

        if ($existing['fingerprint'] !== $fingerprint) {
            return new AtomicIdempotencyRecord(AtomicIdempotencyRecord::CONFLICT, $existing['fingerprint']);
        }

        if ($existing['status'] === 'complete') {
            return new AtomicIdempotencyRecord(AtomicIdempotencyRecord::REPLAY, $fingerprint, $existing['response']);
        }

        return new AtomicIdempotencyRecord(AtomicIdempotencyRecord::IN_PROGRESS, $fingerprint);
    }

    public function complete(string $key, string $fingerprint, mixed $response, int $ttlSeconds): void
    {
        $existing = $this->items[$key] ?? null;
        if (!is_array($existing) || $existing['fingerprint'] !== $fingerprint) {
            return;
        }

        $this->items[$key] = [
            'fingerprint' => $fingerprint,
            'status' => 'complete',
            'response' => $response,
            'expires_at' => time() + max(1, $ttlSeconds),
        ];
    }

    public function release(string $key, string $fingerprint): void
    {
        $existing = $this->items[$key] ?? null;
        if (is_array($existing) && $existing['fingerprint'] === $fingerprint && $existing['status'] !== 'complete') {
            unset($this->items[$key]);
        }
    }
}
