<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

final class ArrayAtomicIdempotencyStore implements LeaseAwareAtomicIdempotencyStoreInterface
{
    /** @var array<string, array{fingerprint: string, status: string, response: mixed, expires_at: int, token: string}> */
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
            $token = $this->token();
            $this->items[$key] = [
                'fingerprint' => $fingerprint,
                'status' => AtomicIdempotencyRecord::IN_PROGRESS,
                'response' => null,
                'expires_at' => $now + max(1, $ttlSeconds),
                'token' => $token,
            ];

            return new AtomicIdempotencyRecord(
                AtomicIdempotencyRecord::RESERVED,
                $fingerprint,
                null,
                $token
            );
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
        throw new \RuntimeException('Use completeReservation() with the reservation token.');
    }

    public function completeReservation(
        string $key,
        string $fingerprint,
        string $reservationToken,
        mixed $response,
        int $ttlSeconds
    ): void {
        $existing = $this->items[$key] ?? null;
        if (
            !is_array($existing)
            || $existing['fingerprint'] !== $fingerprint
            || $existing['token'] !== $reservationToken
            || $existing['status'] !== AtomicIdempotencyRecord::IN_PROGRESS
        ) {
            throw new \RuntimeException('Idempotency reservation is no longer owned by this request.');
        }

        $this->items[$key] = [
            'fingerprint' => $fingerprint,
            'status' => 'complete',
            'response' => $response,
            'expires_at' => time() + max(1, $ttlSeconds),
            'token' => $reservationToken,
        ];
    }

    public function release(string $key, string $fingerprint): void
    {
        throw new \RuntimeException('Use releaseReservation() with the reservation token.');
    }

    public function releaseReservation(string $key, string $fingerprint, string $reservationToken): void
    {
        $existing = $this->items[$key] ?? null;
        if (
            is_array($existing)
            && $existing['fingerprint'] === $fingerprint
            && $existing['token'] === $reservationToken
            && $existing['status'] !== 'complete'
        ) {
            unset($this->items[$key]);
        }
    }

    private function token(): string
    {
        return bin2hex(random_bytes(16));
    }
}
