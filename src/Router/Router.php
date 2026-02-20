<?php

declare(strict_types=1);

namespace BetterRoute\Router;

use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Http\ResponseNormalizer;
use BetterRoute\Middleware\Pipeline;
use InvalidArgumentException;
use Throwable;

final class Router
{
    /** @var list<mixed> */
    private array $globalMiddlewares = [];

    /** @var list<string> */
    private array $groupPrefixes = [];

    /** @var list<list<mixed>> */
    private array $groupMiddlewares = [];

    /** @var list<RouteDefinition> */
    private array $routes = [];

    private function __construct(
        private readonly string $vendor,
        private readonly string $version,
        private readonly Pipeline $pipeline = new Pipeline(),
        private readonly ResponseNormalizer $responseNormalizer = new ResponseNormalizer(),
        private readonly ArgumentResolver $argumentResolver = new ArgumentResolver()
    ) {
    }

    public static function make(string $vendor, string $version): self
    {
        return new self($vendor, $version);
    }

    /**
     * @param list<mixed> $middlewares
     */
    public function middleware(array $middlewares): self
    {
        if ($this->groupMiddlewares === []) {
            $this->globalMiddlewares = array_merge($this->globalMiddlewares, array_values($middlewares));
            return $this;
        }

        $groupIndex = array_key_last($this->groupMiddlewares);
        if ($groupIndex !== null) {
            $this->groupMiddlewares[$groupIndex] = array_merge(
                $this->groupMiddlewares[$groupIndex],
                array_values($middlewares)
            );
        }

        return $this;
    }

    /**
     * @param callable(string): mixed $factory
     */
    public function middlewareFactory(callable $factory): self
    {
        $this->pipeline->withMiddlewareFactory($factory);
        return $this;
    }

    public function group(string $prefix, callable $callback): self
    {
        $this->groupPrefixes[] = trim($prefix, '/');
        $this->groupMiddlewares[] = [];
        $callback($this);
        array_pop($this->groupPrefixes);
        array_pop($this->groupMiddlewares);
        return $this;
    }

    public function get(string $uri, mixed $handler): RouteBuilder
    {
        return $this->map('GET', $uri, $handler);
    }

    public function post(string $uri, mixed $handler): RouteBuilder
    {
        return $this->map('POST', $uri, $handler);
    }

    public function put(string $uri, mixed $handler): RouteBuilder
    {
        return $this->map('PUT', $uri, $handler);
    }

    public function patch(string $uri, mixed $handler): RouteBuilder
    {
        return $this->map('PATCH', $uri, $handler);
    }

    public function delete(string $uri, mixed $handler): RouteBuilder
    {
        return $this->map('DELETE', $uri, $handler);
    }

    /**
     * @return list<RouteDefinition>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    public function baseNamespace(): string
    {
        return sprintf('%s/%s', trim($this->vendor, '/'), trim($this->version, '/'));
    }

    /**
     * @return list<array{
     *   namespace: string,
     *   method: string,
     *   path: string,
     *   args: array<string, mixed>,
     *   meta: array<string, mixed>
     * }>
     */
    public function contracts(bool $openApiOnly = false): array
    {
        $contracts = [];

        foreach ($this->routes as $route) {
            $includeInOpenApi = (bool) ($route->meta['openapi']['include'] ?? true);
            if ($openApiOnly && !$includeInOpenApi) {
                continue;
            }

            $contracts[] = [
                'namespace' => $this->baseNamespace(),
                'method' => $route->method,
                'path' => $route->uri,
                'args' => $route->args,
                'meta' => $route->meta,
            ];
        }

        return $contracts;
    }

    public function register(?DispatcherInterface $dispatcher = null): void
    {
        $dispatcher ??= new WordPressRestDispatcher();

        foreach ($this->routes as $route) {
            $permission = is_callable($route->permissionCallback)
                ? $route->permissionCallback
                : static fn (): bool => true;

            $dispatcher->register(
                namespace: $this->baseNamespace(),
                route: $route,
                callback: fn (mixed $request): mixed => $this->dispatch($route, $request),
                permissionCallback: $permission
            );
        }
    }

    /**
     * @internal used by RouteBuilder
     * @param list<mixed> $middlewares
     */
    public function appendRouteMiddlewares(int $routeIndex, array $middlewares): void
    {
        $route = $this->routes[$routeIndex] ?? null;
        if ($route === null) {
            throw new InvalidArgumentException('Invalid route index.');
        }

        $this->routes[$routeIndex] = $route->withMiddlewares(array_merge($route->middlewares, $middlewares));
    }

    /**
     * @internal used by RouteBuilder
     * @param array<string, mixed> $meta
     */
    public function mergeRouteMeta(int $routeIndex, array $meta): void
    {
        $route = $this->routes[$routeIndex] ?? null;
        if ($route === null) {
            throw new InvalidArgumentException('Invalid route index.');
        }

        $merged = array_merge($route->meta, $meta);
        $this->routes[$routeIndex] = $route->withMeta(
            RouteMeta::normalize($merged, $route->method, $route->uri)
        );
    }

    /**
     * @internal used by RouteBuilder
     * @param array<string, mixed> $args
     */
    public function setRouteArgs(int $routeIndex, array $args): void
    {
        $route = $this->routes[$routeIndex] ?? null;
        if ($route === null) {
            throw new InvalidArgumentException('Invalid route index.');
        }

        $this->routes[$routeIndex] = $route->withArgs($args);
    }

    /**
     * @internal used by RouteBuilder
     */
    public function setRoutePermission(int $routeIndex, callable $permissionCallback): void
    {
        $route = $this->routes[$routeIndex] ?? null;
        if ($route === null) {
            throw new InvalidArgumentException('Invalid route index.');
        }

        $this->routes[$routeIndex] = $route->withPermissionCallback($permissionCallback);
    }

    private function map(string $method, string $uri, mixed $handler): RouteBuilder
    {
        $normalizedMethod = strtoupper($method);
        $normalizedUri = $this->normalizeUri($this->currentGroupPrefix() . '/' . trim($uri, '/'));

        $this->routes[] = new RouteDefinition(
            method: $normalizedMethod,
            uri: $normalizedUri,
            handler: $handler,
            middlewares: $this->currentMiddlewares(),
            meta: RouteMeta::normalize([], $normalizedMethod, $normalizedUri)
        );

        $index = array_key_last($this->routes);
        if (!is_int($index)) {
            throw new InvalidArgumentException('Invalid route index.');
        }

        return new RouteBuilder($this, $index);
    }

    /**
     * @return list<mixed>
     */
    private function currentMiddlewares(): array
    {
        $middlewares = $this->globalMiddlewares;

        foreach ($this->groupMiddlewares as $groupMiddleware) {
            $middlewares = array_merge($middlewares, $groupMiddleware);
        }

        return $middlewares;
    }

    private function currentGroupPrefix(): string
    {
        if ($this->groupPrefixes === []) {
            return '';
        }

        return implode('/', array_map(static fn (string $prefix): string => trim($prefix, '/'), $this->groupPrefixes));
    }

    private function dispatch(RouteDefinition $route, mixed $request): mixed
    {
        $context = new RequestContext(
            requestId: $this->resolveRequestId($request),
            routePath: $route->uri,
            request: $request
        );

        try {
            $result = $this->pipeline->process(
                context: $context,
                middlewares: $route->middlewares,
                destination: fn (RequestContext $ctx): mixed => $this->argumentResolver->invoke($route->handler, $ctx, $request)
            );

            $normalized = $this->responseNormalizer->normalize($result, $context);
        } catch (Throwable $throwable) {
            $normalized = $this->responseNormalizer->throwable($throwable, $context);
        }

        return $this->toWpResponse($normalized);
    }

    private function toWpResponse(mixed $normalized): mixed
    {
        if (!$normalized instanceof Response) {
            return $normalized;
        }

        if (class_exists('WP_REST_Response')) {
            return new \WP_REST_Response($normalized->body, $normalized->status, $normalized->headers);
        }

        return [
            'status' => $normalized->status,
            'headers' => $normalized->headers,
            'body' => $normalized->body,
        ];
    }

    private function resolveRequestId(mixed $request): string
    {
        if (is_object($request) && method_exists($request, 'get_header')) {
            $header = $request->get_header('x-request-id');
            if (is_string($header) && $header !== '') {
                return $header;
            }
        }

        try {
            return 'req_' . bin2hex(random_bytes(8));
        } catch (Throwable) {
            return 'req_' . uniqid('', true);
        }
    }

    private function normalizeUri(string $uri): string
    {
        $normalized = '/' . trim($uri, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
