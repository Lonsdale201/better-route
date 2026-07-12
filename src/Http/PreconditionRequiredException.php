<?php

declare(strict_types=1);

namespace BetterRoute\Http;

final class PreconditionRequiredException extends ApiException
{
    /**
     * @param array<string, mixed> $details
     */
    public function __construct(
        string $message = 'Precondition required.',
        string $errorCode = 'precondition_required',
        array $details = []
    ) {
        parent::__construct($message, 428, $errorCode, $details);
    }
}
