<?php

declare(strict_types=1);

namespace BetterRoute\Router;

interface DispatcherInterface
{
    public function register(
        string $namespace,
        RouteDefinition $route,
        callable $callback,
        callable $permissionCallback
    ): void;
}
