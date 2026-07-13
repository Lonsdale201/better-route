<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\OpenApi\OpenApiExporter;
use PHPUnit\Framework\TestCase;

final class OpenApiExporterTest extends TestCase
{
    public function testExportsOpenApiDocumentFromContracts(): void
    {
        $exporter = new OpenApiExporter();

        $document = $exporter->export([
            [
                'namespace' => 'better-route/v1',
                'method' => 'GET',
                'path' => '/articles/(?P<id>\d+)',
                'args' => [],
                'meta' => [
                    'operationId' => 'articlesGet',
                    'tags' => ['Articles'],
                    'parameters' => [
                        ['in' => 'path', 'name' => 'id', 'required' => false, 'schema' => ['type' => 'integer']],
                        ['in' => 'query', 'name' => 'fields', 'required' => false, 'schema' => ['type' => 'string']],
                    ],
                    'responseSchema' => '#/components/schemas/Article',
                    'scopes' => ['content:read'],
                    'policy' => ['scopes' => ['content:read']],
                    'openapi' => ['include' => true],
                ],
            ],
        ], [
            'title' => 'Test API',
            'version' => 'v0.1.0',
            'serverUrl' => '/wp-json',
        ]);

        self::assertSame('3.1.0', $document['openapi']);
        self::assertSame('Test API', $document['info']['title']);
        self::assertSame('v0.1.0', $document['info']['version']);
        self::assertSame('/wp-json', $document['servers'][0]['url']);

        $operation = $document['paths']['/better-route/v1/articles/{id}']['get'];
        self::assertSame('articlesGet', $operation['operationId']);
        self::assertSame(['Articles'], $operation['tags']);
        self::assertSame(['content:read'], $operation['x-scopes']);
        self::assertSame(['policy' => ['scopes' => ['content:read']]], $operation['x-better-route']);
        self::assertSame('#/components/schemas/Article', $operation['responses']['200']['content']['application/json']['schema']['$ref']);
        self::assertSame('#/components/responses/ErrorResponse', $operation['responses']['default']['$ref']);

        $pathParameter = $operation['parameters'][0];
        self::assertSame('path', $pathParameter['in']);
        self::assertSame('id', $pathParameter['name']);
        self::assertTrue($pathParameter['required']);
    }

    public function testSkipsRoutesExcludedFromOpenApiByDefault(): void
    {
        $exporter = new OpenApiExporter();
        $contracts = [
            [
                'namespace' => 'better-route/v1',
                'method' => 'GET',
                'path' => '/public',
                'args' => [],
                'meta' => ['operationId' => 'publicList', 'openapi' => ['include' => true]],
            ],
            [
                'namespace' => 'better-route/v1',
                'method' => 'GET',
                'path' => '/internal',
                'args' => [],
                'meta' => ['operationId' => 'internalList', 'openapi' => ['include' => false]],
            ],
        ];

        $filtered = $exporter->export($contracts);
        self::assertArrayHasKey('/better-route/v1/public', $filtered['paths']);
        self::assertArrayNotHasKey('/better-route/v1/internal', $filtered['paths']);

        $all = $exporter->export($contracts, ['includeExcluded' => true]);
        self::assertArrayHasKey('/better-route/v1/internal', $all['paths']);
    }

    public function testAddsRequestBodyAndCustomComponents(): void
    {
        $exporter = new OpenApiExporter();

        $document = $exporter->export([
            [
                'namespace' => 'better-route/v1',
                'method' => 'POST',
                'path' => '/articles',
                'args' => [],
                'meta' => [
                    'operationId' => 'articlesCreate',
                    'requestSchema' => '#/components/schemas/ArticleInput',
                    'responseSchema' => '#/components/schemas/Article',
                    'openapi' => ['include' => true],
                ],
            ],
        ], [
            'components' => [
                'schemas' => [
                    'Article' => [
                        'type' => 'object',
                    ],
                ],
            ],
        ]);

        $operation = $document['paths']['/better-route/v1/articles']['post'];
        self::assertSame('#/components/schemas/ArticleInput', $operation['requestBody']['content']['application/json']['schema']['$ref']);
        self::assertTrue($operation['requestBody']['required']);
        self::assertSame('#/components/schemas/Article', $operation['responses']['201']['content']['application/json']['schema']['$ref']);
        self::assertArrayHasKey('Error', $document['components']['schemas']);
        self::assertArrayHasKey('Article', $document['components']['schemas']);
    }

    public function testStrictSchemasRejectsMissingReferencedSchema(): void
    {
        $exporter = new OpenApiExporter();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing OpenAPI schema "Article"');

        $exporter->export([
            [
                'namespace' => 'better-route/v1',
                'method' => 'GET',
                'path' => '/articles',
                'args' => [],
                'meta' => [
                    'operationId' => 'articlesList',
                    'responseSchema' => '#/components/schemas/Article',
                ],
            ],
        ], ['strictSchemas' => true]);
    }

    public function testEmptyOperationSecurityOverridesGlobalSecurity(): void
    {
        $exporter = new OpenApiExporter();

        $document = $exporter->export([
            [
                'namespace' => 'better-route/v1',
                'method' => 'GET',
                'path' => '/public/ping',
                'args' => [],
                'meta' => [
                    'operationId' => 'publicPing',
                    'security' => [],
                ],
            ],
        ], [
            'globalSecurity' => [
                ['bearerAuth' => []],
            ],
        ]);

        self::assertSame([['bearerAuth' => []]], $document['security']);
        self::assertSame([], $document['paths']['/better-route/v1/public/ping']['get']['security']);
    }

    public function testExportsRouteArgsAndLetsExplicitParametersOverrideThem(): void
    {
        $document = (new OpenApiExporter())->export([[
            'namespace' => 'better-route/v1',
            'method' => 'GET',
            'path' => '/articles/(?P<id>\d+)',
            'args' => [
                'id' => ['required' => true, 'type' => 'integer'],
                'page' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
            'meta' => [
                'parameters' => [
                    ['in' => 'query', 'name' => 'page', 'schema' => ['type' => 'integer', 'maximum' => 50]],
                ],
            ],
        ]]);

        $parameters = $document['paths']['/better-route/v1/articles/{id}']['get']['parameters'];
        self::assertCount(2, $parameters);
        self::assertSame('integer', $parameters[0]['schema']['type']);
        self::assertSame(50, $parameters[1]['schema']['maximum']);
        self::assertArrayNotHasKey('minimum', $parameters[1]['schema']);
    }

    public function testCustomResponseOverridesDefaultAndOptionsUses204(): void
    {
        $document = (new OpenApiExporter())->export([
            [
                'namespace' => 'better-route/v1',
                'method' => 'POST',
                'path' => '/articles',
                'args' => [],
                'meta' => ['responses' => ['201' => ['description' => 'Created explicitly']]],
            ],
            [
                'namespace' => 'better-route/v1',
                'method' => 'OPTIONS',
                'path' => '/articles',
                'args' => [],
                'meta' => ['responseSchema' => '#/components/schemas/Article'],
            ],
        ]);

        self::assertSame('Created explicitly', $document['paths']['/better-route/v1/articles']['post']['responses']['201']['description']);
        self::assertArrayNotHasKey('content', $document['paths']['/better-route/v1/articles']['options']['responses']['204']);
    }
}
