<?php

declare(strict_types=1);

namespace BetterRoute\Middleware;

use BetterRoute\Http\RequestContext;
use InvalidArgumentException;

final class Pipeline
{
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
            $instance = new $middleware();
            if ($instance instanceof MiddlewareInterface) {
                return [$instance, 'handle'];
            }
        }

        if (is_callable($middleware)) {
            return $middleware;
        }

        throw new InvalidArgumentException('Middleware must be callable or implement MiddlewareInterface.');
    }
}
