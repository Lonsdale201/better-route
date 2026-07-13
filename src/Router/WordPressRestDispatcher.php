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

        if (function_exists('did_action') && did_action('rest_api_init') < 1) {
            throw new RuntimeException(
                'Routes must be registered during rest_api_init. Wrap Router::register() in an add_action callback.'
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

        $registered = register_rest_route($namespace, $route->uri, $definition);
        if ($registered === false) {
            throw new RuntimeException(sprintf(
                'WordPress rejected REST route registration for %s%s.',
                trim($namespace, '/'),
                $route->uri
            ));
        }
    }
}
