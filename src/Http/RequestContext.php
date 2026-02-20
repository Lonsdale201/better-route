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
        public readonly string $routePath,
        public readonly mixed $request = null,
        public readonly array $attributes = []
    ) {
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $attributes = $this->attributes;
        $attributes[$key] = $value;

        return new self(
            requestId: $this->requestId,
            routePath: $this->routePath,
            request: $this->request,
            attributes: $attributes
        );
    }
}
