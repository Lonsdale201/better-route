<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\OpenApi\OpenApiRouteRegistrar;
use BetterRoute\Resource\Resource;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use BetterRoute\Router\Router;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpenApiRouteRegistrarTest extends TestCase
{
    public function testRegistersOpenApiEndpointAndReturnsDocument(): void
    {
        $router = Router::make('better-route', 'v1');
        $router->get('/ping', static fn (): array => ['pong' => true])
            ->meta(['operationId' => 'ping']);

        $dispatcher = new OpenApiRegistrarDispatcher();
        OpenApiRouteRegistrar::register(
            restNamespace: 'better-route/v1',
            contractsProvider: static fn (): array => OpenApiRouteRegistrar::contractsFromSources([$router]),
            options: [
                'title' => 'Test API',
                'version' => 'v0.1.0',
            ],
            dispatcher: $dispatcher
        );

        self::assertCount(1, $dispatcher->registrations);
        self::assertSame('/openapi.json', $dispatcher->registrations[0]['route']->uri);
        self::assertSame('GET', $dispatcher->registrations[0]['route']->method);
        self::assertFalse((bool) $dispatcher->registrations[0]['route']->meta['openapi']['include']);

        $response = ($dispatcher->registrations[0]['callback'])(new OpenApiRegistrarFakeRequest([]));
        self::assertSame(200, $response['status']);
        self::assertSame('Test API', $response['body']['info']['title']);
        self::assertArrayHasKey('/better-route/v1/ping', $response['body']['paths']);
    }

    public function testContractsFromSourcesAcceptsRoutersResourcesAndRawContracts(): void
    {
        $router = Router::make('better-route', 'v1');
        $router->get('/public', static fn (): array => ['ok' => true])
            ->meta(['operationId' => 'publicList']);

        $resource = Resource::make('articles')
            ->restNamespace('better-route/v1')
            ->sourceCpt('post')
            ->allow(['list'])
            ->fields(['id', 'title'])
            ->usingCptRepository(new ArrayCptRepository());
        $resource->register(new ResourceDispatcher());

        $contracts = OpenApiRouteRegistrar::contractsFromSources([
            $router,
            $resource,
            [[
                'namespace' => 'better-route/v1',
                'method' => 'GET',
                'path' => '/external',
                'args' => [],
                'meta' => ['operationId' => 'externalGet', 'openapi' => ['include' => true]],
            ]],
        ]);

        self::assertCount(3, $contracts);
    }

    public function testThrowsForInvalidNamespaceFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        OpenApiRouteRegistrar::register(
            restNamespace: 'better-route',
            contractsProvider: static fn (): array => []
        );
    }
}

final class OpenApiRegistrarDispatcher implements DispatcherInterface
{
    /** @var list<array{namespace: string, route: RouteDefinition, callback: callable, permissionCallback: callable}> */
    public array $registrations = [];

    public function register(
        string $namespace,
        RouteDefinition $route,
        callable $callback,
        callable $permissionCallback
    ): void {
        $this->registrations[] = [
            'namespace' => $namespace,
            'route' => $route,
            'callback' => $callback,
            'permissionCallback' => $permissionCallback,
        ];
    }
}

final class OpenApiRegistrarFakeRequest
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(private readonly array $params)
    {
    }

    public function get_header(string $name): string
    {
        return '';
    }

    public function get_param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_params(): array
    {
        return $this->params;
    }
}
