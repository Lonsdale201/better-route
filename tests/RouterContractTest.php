<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Router\Router;
use PHPUnit\Framework\TestCase;

final class RouterContractTest extends TestCase
{
    public function testContractsContainNormalizedMeta(): void
    {
        $router = Router::make('better-route', 'v1');
        $router->get('/ping', static fn (): array => ['ok' => true]);

        $contracts = $router->contracts();

        self::assertCount(1, $contracts);
        self::assertSame('better-route/v1', $contracts[0]['namespace']);
        self::assertSame('GET', $contracts[0]['method']);
        self::assertSame('/ping', $contracts[0]['path']);
        self::assertSame('getPing', $contracts[0]['meta']['operationId']);
        self::assertSame([], $contracts[0]['meta']['tags']);
        self::assertSame([], $contracts[0]['meta']['scopes']);
        self::assertTrue($contracts[0]['meta']['openapi']['include']);
    }

    public function testContractsCanBeFilteredForOpenApi(): void
    {
        $router = Router::make('better-route', 'v1');
        $router->get('/public', static fn (): array => ['ok' => true])
            ->meta(['operationId' => 'publicEndpoint']);

        $router->get('/internal', static fn (): array => ['ok' => true])
            ->meta([
                'operationId' => 'internalEndpoint',
                'openapi' => ['include' => false],
            ]);

        $allContracts = $router->contracts();
        $openApiContracts = $router->contracts(true);

        self::assertCount(2, $allContracts);
        self::assertCount(1, $openApiContracts);
        self::assertSame('/public', $openApiContracts[0]['path']);
        self::assertSame('publicEndpoint', $openApiContracts[0]['meta']['operationId']);
    }

    public function testPolicyScopesAreMappedIntoMetaScopes(): void
    {
        $router = Router::make('better-route', 'v1');
        $router->get('/secure', static fn (): array => ['ok' => true])
            ->meta([
                'policy' => [
                    'scopes' => ['content:read'],
                ],
            ]);

        $contracts = $router->contracts(true);
        self::assertCount(1, $contracts);
        self::assertSame(['content:read'], $contracts[0]['meta']['scopes']);
    }
}
