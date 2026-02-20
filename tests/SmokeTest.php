<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\BetterRoute;
use BetterRoute\Resource\Resource;
use BetterRoute\Router\Router;
use PHPUnit\Framework\TestCase;

final class SmokeTest extends TestCase
{
    public function testRouterFactoryReturnsRouter(): void
    {
        $router = BetterRoute::router('better-route', 'v1');
        self::assertInstanceOf(Router::class, $router);
        self::assertSame('better-route/v1', $router->baseNamespace());
    }

    public function testResourceDslBuilderIsChainable(): void
    {
        $resource = Resource::make('articles')
            ->restNamespace('better-route/v1')
            ->sourceCpt('post')
            ->allow(['list', 'get'])
            ->fields(['id', 'title'])
            ->filters(['status'])
            ->sort(['id'])
            ->policy(['scopes' => ['content:read']])
            ->defaultPerPage(15)
            ->maxPerPage(60)
            ->uniformEnvelope();

        self::assertSame('articles', $resource->name());
        self::assertSame('post', $resource->descriptor()['sourceCpt']);
        self::assertSame(15, $resource->descriptor()['defaultPerPage']);
        self::assertSame(60, $resource->descriptor()['maxPerPage']);
        self::assertTrue($resource->descriptor()['uniformEnvelope']);
    }
}
