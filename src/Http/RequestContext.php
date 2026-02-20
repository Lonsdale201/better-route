<?php

declare(strict_types=1);

namespace BetterRoute\Http;

final class RequestContext
{
    /**
     * @param array<string, mixed> $attributes
     */
    public function __construct(
        public readonly string $requestId,
        public readonly array $attributes = []
    ) {
    }
}
