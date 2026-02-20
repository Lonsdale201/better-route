<?php

declare(strict_types=1);

namespace BetterRoute\Router;

final class Router
{
    /** @var list<string> */
    private array $globalMiddlewares = [];

    /** @var list<RouteDefinition> */
    private array $routes = [];

    private string $groupPrefix = '';

    private function __construct(
        private readonly string $vendor,
        private readonly string $version
    ) {
    }

    public static function make(string $vendor, string $version): self
    {
        return new self($vendor, $version);
    }

    /**
     * @param list<string> $middlewares
     */
    public function middleware(array $middlewares): self
    {
        $this->globalMiddlewares = array_values($middlewares);
        return $this;
    }

    public function group(string $prefix, callable $callback): self
    {
        $previousPrefix = $this->groupPrefix;
        $this->groupPrefix = $this->normalizeUri($previousPrefix . '/' . trim($prefix, '/'));
        $callback($this);
        $this->groupPrefix = $previousPrefix;
        return $this;
    }

    public function get(string $uri, mixed $handler): self
    {
        $this->routes[] = new RouteDefinition(
            method: 'GET',
            uri: $this->normalizeUri($this->groupPrefix . '/' . trim($uri, '/')),
            handler: $handler,
            middlewares: $this->globalMiddlewares
        );

        return $this;
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

    public function register(): void
    {
        // Implemented in M1 with the WordPress dispatcher integration.
    }

    private function normalizeUri(string $uri): string
    {
        $normalized = '/' . trim($uri, '/');
        return $normalized === '/' ? '/' : rtrim($normalized, '/');
    }
}
