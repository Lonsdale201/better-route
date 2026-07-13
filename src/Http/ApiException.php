<?php

declare(strict_types=1);

namespace BetterRoute\Http;

use InvalidArgumentException;
use RuntimeException;

class ApiException extends RuntimeException
{
    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    public function __construct(
        string $message,
        private readonly int $status = 500,
        private readonly string $errorCode = 'internal_error',
        private readonly array $details = [],
        private readonly array $headers = []
    ) {
        if ($status < 400 || $status > 599) {
            throw new InvalidArgumentException('API exception status must be between 400 and 599.');
        }
        if ($errorCode === '' || preg_match('/^[A-Za-z0-9._:-]+$/D', $errorCode) !== 1) {
            throw new InvalidArgumentException('API exception error code is invalid.');
        }
        foreach ($headers as $name => $value) {
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1
                || preg_match('/[\r\n]/', $value) === 1
            ) {
                throw new InvalidArgumentException('API exception headers contain an invalid name or value.');
            }
        }
        parent::__construct($message);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }
}
