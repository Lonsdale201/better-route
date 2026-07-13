<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Cors;

final class WordPressCorsBridge
{
    /** @var list<array{pattern: string, middleware: CorsMiddleware}> */
    private static array $routes = [];
    private static bool $installed = false;

    public static function register(string $namespace, string $route, CorsMiddleware $middleware): void
    {
        $routePattern = str_replace('#', '\\#', $route);
        self::$routes[] = [
            'pattern' => '#^/' . preg_quote(trim($namespace, '/'), '#') . $routePattern . '$#i',
            'middleware' => $middleware,
        ];

        if (self::$installed || !function_exists('add_filter')) {
            return;
        }

        add_filter('rest_pre_dispatch', [self::class, 'preDispatch'], 9, 3);
        add_filter('rest_pre_serve_request', [self::class, 'serve'], 20, 4);
        self::$installed = true;
    }

    public static function preDispatch(mixed $response, mixed $server, mixed $request): mixed
    {
        // WordPress initializes this filter with `null`, but third-party filters
        // commonly use `false` as the equivalent "not handled" sentinel.
        if ($response !== null && $response !== false) {
            return $response;
        }

        $middleware = self::middlewareFor($request);
        if ($middleware === null || !$middleware->isPreflightRequest($request)) {
            return $response;
        }

        $headers = $middleware->headersForRequest($request);
        if ($headers === [] && $middleware->rejectsDisallowedOrigins()) {
            return self::wpResponse([
                'error' => [
                    'code' => 'cors_origin_denied',
                    'message' => 'Origin is not allowed.',
                    'details' => [],
                ],
            ], 403, []);
        }

        return self::wpResponse(null, 204, $headers);
    }

    public static function serve(mixed $served, mixed $result, mixed $request, mixed $server): mixed
    {
        $middleware = self::middlewareFor($request);
        if ($middleware === null || headers_sent()) {
            return $served;
        }

        $headers = $middleware->headersForRequest($request);
        self::replaceCorsHeaders($headers);

        return $served;
    }

    private static function middlewareFor(mixed $request): ?CorsMiddleware
    {
        if (!is_object($request) || !method_exists($request, 'get_route')) {
            return null;
        }

        $route = $request->get_route();
        if (!is_string($route)) {
            return null;
        }

        foreach (array_reverse(self::$routes) as $registered) {
            if (preg_match($registered['pattern'], $route) === 1) {
                return $registered['middleware'];
            }
        }

        return null;
    }

    /**
     * @param array<string, string> $headers
     */
    private static function replaceCorsHeaders(array $headers): void
    {
        $corsHeaders = [
            'Access-Control-Allow-Origin',
            'Access-Control-Allow-Methods',
            'Access-Control-Allow-Headers',
            'Access-Control-Allow-Credentials',
            'Access-Control-Expose-Headers',
            'Access-Control-Max-Age',
        ];
        foreach ($corsHeaders as $name) {
            header_remove($name);
        }

        $vary = self::existingVaryTokens();
        $vary = array_values(array_filter(
            $vary,
            static fn (string $token): bool => strtolower($token) !== 'origin'
        ));
        header_remove('Vary');

        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'vary') {
                foreach (explode(',', $value) as $token) {
                    $token = trim($token);
                    if ($token !== '') {
                        $vary[] = $token;
                    }
                }
                continue;
            }

            header($name . ': ' . $value, true);
        }

        $vary = array_values(array_unique($vary));
        if ($vary !== []) {
            header('Vary: ' . implode(', ', $vary), true);
        }
    }

    /**
     * @return list<string>
     */
    private static function existingVaryTokens(): array
    {
        $tokens = [];
        foreach (headers_list() as $header) {
            if (stripos($header, 'Vary:') !== 0) {
                continue;
            }

            foreach (explode(',', substr($header, 5)) as $token) {
                $token = trim($token);
                if ($token !== '') {
                    $tokens[] = $token;
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    /**
     * @param array<string, string> $headers
     */
    private static function wpResponse(mixed $body, int $status, array $headers): mixed
    {
        if (class_exists('WP_REST_Response')) {
            return new \WP_REST_Response($body, $status, $headers);
        }

        return $body;
    }
}
