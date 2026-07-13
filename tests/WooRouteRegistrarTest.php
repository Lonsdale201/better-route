<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Integration\Woo\WooRouteRegistrar;
use BetterRoute\Middleware\Write\ArrayAtomicIdempotencyStore;
use BetterRoute\Middleware\Write\AtomicIdempotencyMiddleware;
use BetterRoute\OpenApi\OpenApiExporter;
use PHPUnit\Framework\TestCase;

final class WooRouteRegistrarTest extends TestCase
{
    public function testExplicitEmptyActionsExposeNoRoutes(): void
    {
        $router = (new WooRouteRegistrar())->register('better-route/v1', [
            'register' => false,
            'actions' => [
                'orders' => [],
                'products' => [],
                'customers' => [],
                'coupons' => [],
            ],
        ]);

        self::assertSame([], $router->routes());
    }

    public function testInvalidActionIsRejectedInsteadOfSilentlyIgnored(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported actions.orders');

        (new WooRouteRegistrar())->register('better-route/v1', [
            'register' => false,
            'actions' => ['orders' => ['list', 'publsih']],
        ]);
    }

    public function testCustomerWritesUseAtomicIdempotencyAndAccurateCreateSchema(): void
    {
        $router = (new WooRouteRegistrar())->register('better-route/v1', [
            'register' => false,
            'actions' => [
                'orders' => [],
                'products' => [],
                'customers' => ['create', 'update'],
                'coupons' => [],
            ],
            'idempotency' => [
                'enabled' => true,
                'requireKey' => true,
                'store' => new ArrayAtomicIdempotencyStore(),
            ],
        ]);

        self::assertCount(3, $router->routes());
        foreach ($router->routes() as $route) {
            self::assertCount(1, $route->middlewares);
            self::assertInstanceOf(AtomicIdempotencyMiddleware::class, $route->middlewares[0]);
        }

        self::assertSame(
            '#/components/schemas/WooCustomerCreateInput',
            $router->routes()[0]->meta['requestSchema']
        );
    }

    public function testWooListArgsArePresentInOpenApiWithoutManualParameters(): void
    {
        $registrar = new WooRouteRegistrar();
        $router = $registrar->register('better-route/v1', [
            'register' => false,
            'actions' => [
                'orders' => ['list'],
                'products' => [],
                'customers' => [],
                'coupons' => [],
            ],
        ]);

        $document = (new OpenApiExporter())->export($router->contracts(), [
            'components' => $registrar->openApiComponents(),
        ]);
        $parameters = $document['paths']['/better-route/v1/woo/orders']['get']['parameters'];
        $names = array_column($parameters, 'name');

        self::assertContains('page', $names);
        self::assertContains('per_page', $names);
        self::assertContains('customer_id', $names);
    }
}
