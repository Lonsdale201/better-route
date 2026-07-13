<?php

declare(strict_types=1);

namespace BetterRoute\Router;

use BetterRoute\Http\RequestContext;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;

final class ArgumentResolver
{
    public function invoke(mixed $handler, RequestContext $context, mixed $request): mixed
    {
        $callable = $this->resolveCallable($handler);
        $reflection = $this->reflectCallable($callable);
        $parameters = $reflection->getParameters();
        $count = count($parameters);

        if ($reflection->getNumberOfRequiredParameters() > 2) {
            throw new InvalidArgumentException(
                'Route handlers may require at most two parameters: RequestContext and the WordPress request.'
            );
        }

        if ($count === 0) {
            return $callable();
        }

        if ($count === 1) {
            $type = $parameters[0]->getType();
            if ($this->typeAcceptsContext($type)) {
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

            if (!class_exists($className) || !method_exists($className, $method)) {
                throw new InvalidArgumentException('Route handler class or method does not exist.');
            }

            $reflection = new ReflectionMethod($className, $method);
            if ($reflection->isStatic() && is_callable([$className, $method])) {
                return [$className, $method];
            }

            $instance = $this->instantiateHandler($className);
            if (is_callable([$instance, $method])) {
                return [$instance, $method];
            }
        }

        if (is_string($handler) && class_exists($handler)) {
            $instance = $this->instantiateHandler($handler);
            if (is_callable($instance)) {
                return $instance;
            }
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new InvalidArgumentException('Route handler must be callable.');
    }

    private function instantiateHandler(string $className): object
    {
        $reflection = new ReflectionClass($className);
        if (!$reflection->isInstantiable()) {
            throw new InvalidArgumentException(sprintf('Route handler class %s is not instantiable.', $className));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidArgumentException(sprintf(
                'Route handler class %s requires constructor arguments; pass an object or callable instance.',
                $className
            ));
        }

        return $reflection->newInstance();
    }

    private function typeAcceptsContext(?ReflectionType $type): bool
    {
        if ($type instanceof ReflectionUnionType) {
            foreach ($type->getTypes() as $unionType) {
                if ($this->typeAcceptsContext($unionType)) {
                    return true;
                }
            }

            return false;
        }

        return $type instanceof ReflectionNamedType
            && !$type->isBuiltin()
            && is_a(RequestContext::class, $type->getName(), true);
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
