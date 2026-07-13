<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\RequestContext;
use BetterRoute\Router\ArgumentResolver;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ArgumentResolverTest extends TestCase
{
    public function testInvokesHandlerWithContextWhenTyped(): void
    {
        $resolver = new ArgumentResolver();
        $context = new RequestContext('req_1', '/path', null);

        $result = $resolver->invoke(
            static fn (RequestContext $ctx): string => $ctx->requestId,
            $context,
            null
        );

        self::assertSame('req_1', $result);
    }

    public function testInvokesArrayCallableHandler(): void
    {
        $resolver = new ArgumentResolver();
        $context = new RequestContext('req_2', '/path', null);

        $result = $resolver->invoke([ResolverController::class, 'hello'], $context, ['name' => 'john']);
        self::assertSame('hello', $result);
    }

    public function testThrowsOnInvalidHandler(): void
    {
        $resolver = new ArgumentResolver();
        $this->expectException(InvalidArgumentException::class);

        $resolver->invoke('non_existing_handler', new RequestContext('req_3', '/path', null), null);
    }

    public function testInvokesStaticHandlerWithoutConstructingClass(): void
    {
        $result = (new ArgumentResolver())->invoke(
            [StaticResolverController::class, 'hello'],
            new RequestContext('req_4', '/path', null),
            null
        );

        self::assertSame('static', $result);
    }

    public function testUnionTypeCanSelectRequestContext(): void
    {
        $context = new RequestContext('req_union', '/path', null);
        $result = (new ArgumentResolver())->invoke(
            static fn (RequestContext|array $value): string => $value instanceof RequestContext ? $value->requestId : 'request',
            $context,
            []
        );

        self::assertSame('req_union', $result);
    }

    public function testClassHandlerWithRequiredConstructorMustBePassedAsInstance(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requires constructor arguments');

        (new ArgumentResolver())->resolveCallable([ConstructorResolverController::class, 'hello']);
    }
}

final class ResolverController
{
    public function hello(): string
    {
        return 'hello';
    }
}

final class StaticResolverController
{
    private function __construct(string $required)
    {
        throw new \RuntimeException($required);
    }

    public static function hello(): string
    {
        return 'static';
    }
}

final class ConstructorResolverController
{
    public function __construct(private readonly string $required)
    {
    }

    public function hello(): string
    {
        return $this->required;
    }
}
