<?php

declare(strict_types=1);

namespace BetterRoute\Http;

use InvalidArgumentException;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly mixed $body,
        public readonly int $status = 200,
        public readonly array $headers = []
    ) {
        if ($status < 100 || $status > 599) {
            throw new InvalidArgumentException('HTTP response status must be between 100 and 599.');
        }

        foreach ($headers as $name => $value) {
            if (preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $name) !== 1
                || preg_match('/[\r\n]/', $value) === 1
            ) {
                throw new InvalidArgumentException('HTTP response headers contain an invalid name or value.');
            }
        }
    }
}
