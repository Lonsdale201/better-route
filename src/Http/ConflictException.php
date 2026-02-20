<?php

declare(strict_types=1);

namespace BetterRoute\Http;

final class ConflictException extends ApiException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Conflict.',
        string $errorCode = 'conflict',
        array $details = []
    ) {
        parent::__construct($message, 409, $errorCode, $details);
    }
}
