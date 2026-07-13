<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Cors;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;
use BetterRoute\Middleware\WordPressRouteMiddlewareInterface;

final class CorsMiddleware implements MiddlewareInterface, WordPressRouteMiddlewareInterface
{
    public function __construct(
        private readonly CorsPolicy $policy,
        private readonly bool $rejectDisallowedOrigins = true
    ) {
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $origin = $this->header($context->request, 'origin');
        $headers = $this->policy->headersFor($origin);

        if ($origin !== null && $headers === [] && $this->rejectDisallowedOrigins) {
            throw new ApiException('Origin is not allowed.', 403, 'cors_origin_denied');
        }

        if ($this->isPreflight($context->request)) {
            return new Response(null, 204, $headers);
        }

        $response = $next($context);
        return $this->withHeaders($response, $headers);
    }

    public function registerWordPressRoute(string $namespace, string $route): void
    {
        WordPressCorsBridge::register($namespace, $route, $this);
    }

    /** @return array<string, string> */
    public function headersForRequest(mixed $request): array
    {
        return $this->policy->headersFor($this->header($request, 'origin'));
    }

    public function rejectsDisallowedOrigins(): bool
    {
        return $this->rejectDisallowedOrigins;
    }

    public function isPreflightRequest(mixed $request): bool
    {
        return $this->isPreflight($request);
    }

    private function isPreflight(mixed $request): bool
    {
        return $this->requestMethod($request) === 'OPTIONS'
            && $this->header($request, 'access-control-request-method') !== null;
    }

    private function requestMethod(mixed $request): string
    {
        if (is_object($request) && method_exists($request, 'get_method')) {
            $method = $request->get_method();
            if (is_string($method) && $method !== '') {
                return strtoupper($method);
            }
        }

        return 'GET';
    }

    private function header(mixed $request, string $name): ?string
    {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            return null;
        }

        $value = $request->get_header($name);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, string> $headers
     */
    private function withHeaders(mixed $response, array $headers): mixed
    {
        if ($headers === []) {
            return $response;
        }

        if ($response instanceof Response) {
            return new Response($response->body, $response->status, array_merge($response->headers, $headers));
        }

        if (is_object($response) && method_exists($response, 'header')) {
            foreach ($headers as $name => $value) {
                $response->header($name, $value);
            }

            return $response;
        }

        return new Response($response, 200, $headers);
    }
}
