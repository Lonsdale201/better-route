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
}

final class ResolverController
{
    public function hello(): string
    {
        return 'hello';
    }
}
