<?php

declare(strict_types=1);

namespace BetterRoute\Middleware;

use BetterRoute\Http\RequestContext;
use InvalidArgumentException;
use ReflectionClass;

final class Pipeline
{
    /** @var null|callable(string): mixed */
    private $middlewareFactory = null;

    /**
     * @param callable(string): mixed $factory
     */
    public function withMiddlewareFactory(callable $factory): self
    {
        $this->middlewareFactory = $factory;
        return $this;
    }

    /**
     * @param list<mixed> $middlewares
     */
    public function process(RequestContext $context, array $middlewares, callable $destination): mixed
    {
        $next = $destination;

        foreach (array_reverse($middlewares) as $middleware) {
            $resolved = $this->resolveMiddleware($middleware);

            $next = static fn (RequestContext $ctx): mixed => $resolved($ctx, $next);
        }

        return $next($context);
    }

    private function resolveMiddleware(mixed $middleware): callable
    {
        if ($middleware instanceof MiddlewareInterface) {
            return [$middleware, 'handle'];
        }

        if (is_string($middleware) && class_exists($middleware)) {
            if ($this->middlewareFactory !== null) {
                $resolved = ($this->middlewareFactory)($middleware);

                if ($resolved instanceof MiddlewareInterface) {
                    return [$resolved, 'handle'];
                }

                if (is_callable($resolved)) {
                    return $resolved;
                }
            }

            $reflection = new ReflectionClass($middleware);
            $constructor = $reflection->getConstructor();
            if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
                throw new InvalidArgumentException(
                    sprintf(
                        'Middleware "%s" requires constructor arguments. Configure router middlewareFactory().',
                        $middleware
                    )
                );
            }

            $instance = new $middleware();
            if ($instance instanceof MiddlewareInterface) {
                return [$instance, 'handle'];
            }

            if (is_callable($instance)) {
                return $instance;
            }
        }

        if (is_callable($middleware)) {
            return $middleware;
        }

        throw new InvalidArgumentException('Middleware must be callable or implement MiddlewareInterface.');
    }
}
