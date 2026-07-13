<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

interface LeaseAwareAtomicIdempotencyStoreInterface extends AtomicIdempotencyStoreInterface
{
    public function completeReservation(
        string $key,
        string $fingerprint,
        string $reservationToken,
        mixed $response,
        int $ttlSeconds
    ): void;

    public function releaseReservation(string $key, string $fingerprint, string $reservationToken): void;
}
