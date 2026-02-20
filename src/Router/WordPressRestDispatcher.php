<?php

declare(strict_types=1);

namespace BetterRoute\Router;

use RuntimeException;

final class WordPressRestDispatcher implements DispatcherInterface
{
    public function register(
        string $namespace,
        RouteDefinition $route,
        callable $callback,
        callable $permissionCallback
    ): void {
        if (!function_exists('register_rest_route')) {
            throw new RuntimeException(
                'register_rest_route is unavailable. Register routes inside rest_api_init or provide a custom dispatcher.'
            );
        }

        $definition = [
            'methods' => $route->method,
            'callback' => $callback,
            // Always explicit by design, even when auth is middleware-driven.
            'permission_callback' => $permissionCallback,
        ];

        if ($route->args !== []) {
            $definition['args'] = $route->args;
        }

        register_rest_route($namespace, $route->uri, $definition);
    }
}
