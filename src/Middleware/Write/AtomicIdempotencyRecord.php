<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

final class AtomicIdempotencyRecord
{
    public const RESERVED = 'reserved';
    public const REPLAY = 'replay';
    public const CONFLICT = 'conflict';
    public const IN_PROGRESS = 'in_progress';

    public function __construct(
        public readonly string $status,
        public readonly ?string $fingerprint = null,
        public readonly mixed $response = null
    ) {
    }

    public function isReserved(): bool
    {
        return $this->status === self::RESERVED;
    }

    public function isReplay(): bool
    {
        return $this->status === self::REPLAY;
    }

    public function isConflict(): bool
    {
        return $this->status === self::CONFLICT;
    }

    public function isInProgress(): bool
    {
        return $this->status === self::IN_PROGRESS;
    }
}
