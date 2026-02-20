<?php

declare(strict_types=1);

namespace BetterRoute\Router;

final class RouteBuilder
{
    public function __construct(
        private readonly Router $router,
        private readonly int $routeIndex
    ) {
    }

    /**
     * @param list<mixed> $middlewares
     */
    public function middleware(array $middlewares): self
    {
        $this->router->appendRouteMiddlewares($this->routeIndex, $middlewares);
        return $this;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function meta(array $meta): self
    {
        $this->router->mergeRouteMeta($this->routeIndex, $meta);
        return $this;
    }

    /**
     * @param array<string, mixed> $args
     */
    public function args(array $args): self
    {
        $this->router->setRouteArgs($this->routeIndex, $args);
        return $this;
    }

    public function permission(callable $permissionCallback): self
    {
        $this->router->setRoutePermission($this->routeIndex, $permissionCallback);
        return $this;
    }
}
