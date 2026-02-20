<?php

declare(strict_types=1);

namespace BetterRoute\Router;

use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Http\ResponseNormalizer;
use BetterRoute\Middleware\Pipeline;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
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
        private readonly ResponseNormalizer $responseNormalizer = new ResponseNormalizer()
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

        $this->routes[$routeIndex] = $route->withMeta(array_merge($route->meta, $meta));
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
        $this->routes[] = new RouteDefinition(
            method: strtoupper($method),
            uri: $this->normalizeUri($this->currentGroupPrefix() . '/' . trim($uri, '/')),
            handler: $handler,
            middlewares: $this->currentMiddlewares()
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
                destination: fn (RequestContext $ctx): mixed => $this->invokeHandler($route->handler, $ctx, $request)
            );

            $normalized = $this->responseNormalizer->normalize($result, $context);
        } catch (Throwable $throwable) {
            $normalized = $this->responseNormalizer->throwable($throwable, $context);
        }

        return $this->toWpResponse($normalized);
    }

    private function invokeHandler(mixed $handler, RequestContext $context, mixed $request): mixed
    {
        $callable = $this->resolveCallableHandler($handler);
        $reflection = $this->reflectCallable($callable);
        $parameters = $reflection->getParameters();
        $count = count($parameters);

        if ($count === 0) {
            return $callable();
        }

        if ($count === 1) {
            $type = $parameters[0]->getType();
            if ($type instanceof ReflectionNamedType && $type->getName() === RequestContext::class) {
                return $callable($context);
            }

            return $callable($request);
        }

        return $callable($context, $request);
    }

    private function resolveCallableHandler(mixed $handler): callable
    {
        if (is_array($handler) && count($handler) === 2 && is_string($handler[0]) && is_string($handler[1])) {
            $className = $handler[0];
            $method = $handler[1];
            $instance = new $className();
            if (is_callable([$instance, $method])) {
                return [$instance, $method];
            }
        }

        if (is_string($handler) && class_exists($handler)) {
            $instance = new $handler();
            if (is_callable($instance)) {
                return $instance;
            }
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new InvalidArgumentException('Route handler must be callable.');
    }

    private function reflectCallable(callable $callable): ReflectionFunction|ReflectionMethod
    {
        if (is_array($callable)) {
            return new ReflectionMethod($callable[0], (string) $callable[1]);
        }

        if (is_object($callable) && !$callable instanceof \Closure) {
            return new ReflectionMethod($callable, '__invoke');
        }

        return new ReflectionFunction(\Closure::fromCallable($callable));
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
