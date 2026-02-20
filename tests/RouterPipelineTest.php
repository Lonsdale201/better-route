<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use BetterRoute\Router\Router;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class RouterPipelineTest extends TestCase
{
    public function testMiddlewareOrderIsGlobalGroupRoute(): void
    {
        $trace = [];
        $router = Router::make('better-route', 'v1')
            ->middleware([
                static function (RequestContext $context, callable $next) use (&$trace): mixed {
                    $trace[] = 'global';
                    return $next($context);
                },
            ]);

        $router->group('/admin', function (Router $r) use (&$trace): void {
            $r->middleware([
                static function (RequestContext $context, callable $next) use (&$trace): mixed {
                    $trace[] = 'group';
                    return $next($context);
                },
            ]);

            $r->get('/ping', static function () use (&$trace): array {
                $trace[] = 'handler';
                return ['pong' => true];
            })->middleware([
                static function (RequestContext $context, callable $next) use (&$trace): mixed {
                    $trace[] = 'route';
                    return $next($context);
                },
            ])->meta(['operationId' => 'adminPing']);
        });

        $dispatcher = new InMemoryDispatcher();
        $router->register($dispatcher);

        self::assertCount(1, $dispatcher->registrations);
        $registration = $dispatcher->registrations[0];

        self::assertSame('better-route/v1', $registration['namespace']);
        self::assertSame('/admin/ping', $registration['route']->uri);
        self::assertTrue(($registration['permissionCallback'])());

        $response = ($registration['callback'])(new FakeRequest('req_test_1'));

        self::assertSame(['global', 'group', 'route', 'handler'], $trace);
        self::assertSame(200, $response['status']);
        self::assertSame(['pong' => true], $response['body']);
        self::assertSame('adminPing', $registration['route']->meta['operationId']);
    }

    public function testMiddlewareShortCircuitSkipsHandler(): void
    {
        $trace = [];
        $router = Router::make('better-route', 'v1')
            ->middleware([
                static function (RequestContext $context, callable $next): mixed {
                    return $next($context);
                },
            ]);

        $router->get('/blocked', static function () use (&$trace): array {
            $trace[] = 'handler';
            return ['ok' => true];
        })->middleware([
            static function () use (&$trace): Response {
                $trace[] = 'short-circuit';
                return new Response(['blocked' => true], 403);
            },
        ]);

        $dispatcher = new InMemoryDispatcher();
        $router->register($dispatcher);
        $registration = $dispatcher->registrations[0];
        $response = ($registration['callback'])(new FakeRequest('req_test_2'));

        self::assertSame(['short-circuit'], $trace);
        self::assertSame(403, $response['status']);
        self::assertSame(['blocked' => true], $response['body']);
    }

    public function testThrowableIsNormalizedWithRequestId(): void
    {
        $router = Router::make('better-route', 'v1');
        $router->get('/boom', static function (): void {
            throw new RuntimeException('Boom');
        });

        $dispatcher = new InMemoryDispatcher();
        $router->register($dispatcher);
        $registration = $dispatcher->registrations[0];
        $response = ($registration['callback'])(new FakeRequest('req_test_3'));

        self::assertSame(500, $response['status']);
        self::assertSame('internal_error', $response['body']['error']['code']);
        self::assertSame('Boom', $response['body']['error']['message']);
        self::assertSame('req_test_3', $response['body']['error']['requestId']);
    }

    public function testRouteBuilderSetsArgsMetaAndPermission(): void
    {
        $router = Router::make('better-route', 'v1');
        $router->get('/items', static fn (): array => ['ok' => true])
            ->args(['limit' => ['type' => 'integer']])
            ->meta(['operationId' => 'listItems'])
            ->permission(static fn (): bool => false);

        $dispatcher = new InMemoryDispatcher();
        $router->register($dispatcher);
        $registration = $dispatcher->registrations[0];

        self::assertSame(['limit' => ['type' => 'integer']], $registration['route']->args);
        self::assertSame('listItems', $registration['route']->meta['operationId']);
        self::assertFalse(($registration['permissionCallback'])());
    }
}

final class InMemoryDispatcher implements DispatcherInterface
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

final class FakeRequest
{
    public function __construct(private readonly string $requestId)
    {
    }

    public function get_header(string $name): string
    {
        return strtolower($name) === 'x-request-id' ? $this->requestId : '';
    }
}
