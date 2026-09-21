<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ConflictException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\Cache\CachingMiddleware;
use BetterRoute\Middleware\Cache\TransientCacheStore;
use BetterRoute\Middleware\Write\ArrayAtomicIdempotencyStore;
use BetterRoute\Middleware\Write\AtomicIdempotencyMiddleware;
use BetterRoute\Middleware\Write\IdempotencyMiddleware;
use BetterRoute\Middleware\Write\IdempotencyStoreInterface;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use BetterRoute\Router\Router;
use PHPUnit\Framework\TestCase;

final class RequestIsolationTest extends TestCase
{
    public function testCachesSeparateNamespacesAndUrlIdsDespiteMaskedMergedParameters(): void
    {
        /** @var array<string, mixed> $data */
        $data = [];
        $store = new TransientCacheStore(
            getTransient: static function (string $key) use (&$data): mixed {
                return $data[$key] ?? false;
            },
            setTransient: static function (string $key, mixed $value) use (&$data): bool {
                $data[$key] = $value;
                return true;
            }
        );
        $this->assertRouteIsolation(new CachingMiddleware($store), 'GET');
    }

    public function testAtomicReplaySeparatesNamespacesAndUrlIds(): void
    {
        $this->assertRouteIsolation(new AtomicIdempotencyMiddleware(new ArrayAtomicIdempotencyStore()), 'POST');
    }

    public function testClassicReplaySeparatesNamespacesAndUrlIds(): void
    {
        $store = new class () implements IdempotencyStoreInterface {
            /** @var array<string, mixed> */
            private array $items = [];
            public function get(string $key): mixed
            {
                return $this->items[$key] ?? null;
            }
            public function set(string $key, mixed $value, int $ttlSeconds): void
            {
                $this->items[$key] = $value;
            }
        };
        $this->assertRouteIsolation(new IdempotencyMiddleware($store), 'POST');
    }

    public function testCustomKeyStillConflictsWhenConcreteTargetChanges(): void
    {
        $middleware = new AtomicIdempotencyMiddleware(
            new ArrayAtomicIdempotencyStore(),
            keyResolver: static fn (): string => 'business-operation'
        );
        $first = new RequestContext('a', '/items/(?P<id>\d+)', new IsolationRequest('POST', '/api/v1/items/1', 1));
        $second = new RequestContext('b', '/items/(?P<id>\d+)', new IsolationRequest('POST', '/api/v1/items/2', 2));
        $middleware->handle($first, static fn (): array => ['written' => 1]);
        $this->expectException(ConflictException::class);
        $middleware->handle($second, static fn (): array => ['written' => 2]);
    }

    private function assertRouteIsolation(object $middleware, string $method): void
    {
        $dispatcher = new IsolationDispatcher();
        $calls = 0;
        foreach (['v1', 'v2'] as $version) {
            $router = Router::make('app', $version)->middleware([$middleware]);
            $handler = static function (IsolationRequest $request) use ($version, &$calls): array {
                $calls++;
                return ['version' => $version, 'id' => $request->get_url_params()['id']];
            };
            if ($method === 'GET') {
                $router->get('/items/(?P<id>\d+)', $handler)->publicRoute();
            } else {
                $router->post('/items/(?P<id>\d+)', $handler)->publicRoute();
            }
            $router->register($dispatcher);
        }
        foreach ([['v1', 1], ['v2', 1], ['v1', 2], ['v1', 1]] as [$version, $id]) {
            $result = ($dispatcher->callbacks['app/' . $version])(
                new IsolationRequest($method, '/app/' . $version . '/items/' . $id, $id)
            );
            self::assertSame(['version' => $version, 'id' => $id], $result['body']);
        }
        self::assertSame(3, $calls, 'Only an identical request may reuse a prior response.');
    }
}

final class IsolationDispatcher implements DispatcherInterface
{
    /** @var array<string, callable> */
    public array $callbacks = [];
    public function register(string $namespace, RouteDefinition $route, callable $callback, callable $permissionCallback): void
    {
        $this->callbacks[$namespace] = $callback;
    }
}

final class IsolationRequest
{
    public function __construct(private string $method, private string $path, private int $id)
    {
    }
    public function get_method(): string
    {
        return $this->method;
    }
    public function get_route(): string
    {
        return $this->path;
    }
    public function get_header(string $name): string
    {
        return $name === 'idempotency-key' ? 'same-key' : '';
    }
    /** @return array<string, int> */
    public function get_params(): array
    {
        return ['id' => 2];
    }
    /** @return array<string, int> */
    public function get_url_params(): array
    {
        return ['id' => $this->id];
    }
}
