<?php

declare(strict_types=1);

namespace BetterRoute\Router;

final class RouteDefinition
{
    /**
     * @param list<mixed> $middlewares
     * @param array<string, mixed> $args
     * @param array<string, mixed> $meta
     */
    public function __construct(
        public readonly string $method,
        public readonly string $uri,
        public readonly mixed $handler,
        public readonly array $middlewares = [],
        public readonly array $args = [],
        public readonly mixed $permissionCallback = null,
        public readonly array $meta = []
    ) {
    }

    /**
     * @param list<mixed> $middlewares
     */
    public function withMiddlewares(array $middlewares): self
    {
        return new self(
            method: $this->method,
            uri: $this->uri,
            handler: $this->handler,
            middlewares: $middlewares,
            args: $this->args,
            permissionCallback: $this->permissionCallback,
            meta: $this->meta
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function withMeta(array $meta): self
    {
        return new self(
            method: $this->method,
            uri: $this->uri,
            handler: $this->handler,
            middlewares: $this->middlewares,
            args: $this->args,
            permissionCallback: $this->permissionCallback,
            meta: $meta
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    public function withArgs(array $args): self
    {
        return new self(
            method: $this->method,
            uri: $this->uri,
            handler: $this->handler,
            middlewares: $this->middlewares,
            args: $args,
            permissionCallback: $this->permissionCallback,
            meta: $this->meta
        );
    }

    public function withPermissionCallback(callable $permissionCallback): self
    {
        return new self(
            method: $this->method,
            uri: $this->uri,
            handler: $this->handler,
            middlewares: $this->middlewares,
            args: $this->args,
            permissionCallback: $permissionCallback,
            meta: $this->meta
        );
    }
}
