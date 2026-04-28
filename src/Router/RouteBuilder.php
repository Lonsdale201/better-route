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

    /**
     * Mark the route as intentionally public at the WordPress permission layer.
     */
    public function publicRoute(): self
    {
        $this->permission(static fn (): bool => true);
        return $this->meta(['security' => []]);
    }

    /**
     * Let WordPress dispatch the route so better-route middleware can
     * authenticate, authorize, or short-circuit the request.
     *
     * @param list<array<string, list<string>>>|string|null $security
     */
    public function protectedByMiddleware(array|string|null $security = null): self
    {
        $this->permission(static fn (): bool => true);
        $this->meta(['protectedByMiddleware' => true]);

        if ($security !== null) {
            $this->meta(['security' => $security]);
        }

        return $this;
    }
}
