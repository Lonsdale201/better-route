<?php

declare(strict_types=1);

namespace BetterRoute\Middleware;

interface WordPressRouteMiddlewareInterface
{
    public function registerWordPressRoute(string $namespace, string $route): void;
}
