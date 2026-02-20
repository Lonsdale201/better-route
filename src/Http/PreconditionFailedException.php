<?php

declare(strict_types=1);

namespace BetterRoute\Http;

final class PreconditionFailedException extends ApiException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Precondition failed.',
        string $errorCode = 'precondition_failed',
        array $details = []
    ) {
        parent::__construct($message, 412, $errorCode, $details);
    }
}
