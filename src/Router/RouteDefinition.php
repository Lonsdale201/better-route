<?php

declare(strict_types=1);

namespace BetterRoute\Router;

final class RouteDefinition
{
    /**
     * @param list<string> $middlewares
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly mixed $handler,
        public readonly array $middlewares = [],
        public readonly array $meta = []
    ) {
    }
}
