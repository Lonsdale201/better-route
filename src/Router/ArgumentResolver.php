<?php

declare(strict_types=1);

namespace BetterRoute\Router;

use BetterRoute\Http\RequestContext;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;

final class ArgumentResolver
{
    public function invoke(mixed $handler, RequestContext $context, mixed $request): mixed
    {
        $callable = $this->resolveCallable($handler);
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

    public function resolveCallable(mixed $handler): callable
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
}
