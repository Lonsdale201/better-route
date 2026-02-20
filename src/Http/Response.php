<?php

declare(strict_types=1);

namespace BetterRoute\Http;

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
    }
}
