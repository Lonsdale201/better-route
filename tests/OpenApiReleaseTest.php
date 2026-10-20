<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\AnalyticsResponseMapper;
use BetterRoute\Integration\Woo\MetaDataHelper;
use BetterRoute\Integration\Woo\WooAnalyticsOpenApi;
use BetterRoute\Integration\Woo\WooRouteRegistrar;
use BetterRoute\OpenApi\OpenApiExporter;
use BetterRoute\OpenApi\OpenApiRouteRegistrar;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use BetterRoute\Router\Router;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OpenApiReleaseTest extends TestCase
{
    /** @param array<string, mixed> $meta
     * @return array{namespace: string, path: string, method: string, args: array<string, mixed>, meta: array<string, mixed>}
     */
    private function contract(string $path = '/items', string $method = 'GET', array $meta = []): array
    {
        return ['namespace' => 'test/v1', 'path' => $path, 'method' => $method, 'args' => [], 'meta' => $meta];
    }

    public function testEmptyMapsAndSchemaExamplesSurviveJsonSerialization(): void
    {
        $doc = (new OpenApiExporter())->export([], ['strictSchemas' => true, 'components' => ['schemas' => [
            'Empty' => [],
            'Container' => ['properties' => ['value' => [], 'example' => true], 'examples' => [['$ref' => '#/not-a-schema-ref']]],
        ]]]);
        $json = json_encode($doc, JSON_THROW_ON_ERROR);
        self::assertStringContainsString('"paths":{}', $json);
        self::assertStringContainsString('"Empty":{}', $json);
        self::assertStringContainsString('"value":{}', $json);
        self::assertStringContainsString('#\/not-a-schema-ref', $json);
    }

    public function testNestedPointersAndObjectMapsResolve(): void
    {
        $doc = (new OpenApiExporter())->export([$this->contract(meta: ['responseSchema' => '#/components/schemas/Envelope/properties/data'])], [
            'strictSchemas' => true,
            'components' => ['schemas' => ['Envelope' => ['properties' => (object) ['data' => (object) []]]]],
        ]);
        self::assertArrayHasKey('Envelope', $doc['components']['schemas']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function brokenReferences(): iterable
    {
        yield 'nested property' => [['schemas' => ['Container' => ['properties' => ['value' => ['$ref' => '#/components/schemas/Missing']]]]]];
        yield 'composition' => [['schemas' => ['Container' => ['allOf' => [['$ref' => '#/components/schemas/Missing']]]]]];
        yield 'extension-like component name' => [['responses' => ['x-private' => ['$ref' => '#/components/responses/Missing']]]];
        yield 'example-like component name' => [['parameters' => ['example' => ['$ref' => '#/components/parameters/Missing']]]];
        yield 'object map' => [['schemas' => ['Container' => (object) ['$ref' => '#/components/schemas/Missing']]]];
    }

    /** @param array<string, mixed> $components */
    #[DataProvider('brokenReferences')]
    public function testStrictModeRejectsNestedMissingReferences(array $components): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OpenApiExporter())->export([], ['strictSchemas' => true, 'components' => $components]);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedSecurity(): iterable
    {
        yield 'scalar' => [true];
        yield 'map instead of list' => [['Bearer' => []]];
        yield 'scalar requirement' => [['Bearer']];
        yield 'scalar scopes' => [[['Bearer' => 'read']]];
        yield 'empty scope name' => [[['Bearer' => ['']]]];
    }

    #[DataProvider('malformedSecurity')]
    public function testMalformedSecurityNeverSilentlyBecomesPublic(mixed $security): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OpenApiExporter())->export([$this->contract(meta: ['security' => $security])]);
    }

    public function testUndefinedSecuritySchemeFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing OpenAPI security scheme');
        (new OpenApiExporter())->export([], ['globalSecurity' => [['Missing' => []]]]);
    }

    public function testReferencedSecuritySchemesRolesAndAnonymousAlternatives(): void
    {
        $doc = (new OpenApiExporter())->export([$this->contract(meta: ['security' => [(object) [], ['Alias' => ['editor']]]])], [
            'strictSchemas' => true,
            'securitySchemes' => [
                'Bearer' => ['type' => 'http', 'scheme' => 'bearer'],
                'Alias' => ['$ref' => '#/components/securitySchemes/Bearer'],
                'OAuth' => ['type' => 'oauth2', 'flows' => ['clientCredentials' => ['tokenUrl' => 'https://example.invalid/token', 'scopes' => []]]],
            ],
        ]);
        self::assertSame(['editor'], $doc['paths']['/test/v1/items']['get']['security'][1]['Alias']);
        self::assertStringContainsString('"scopes":{}', json_encode($doc, JSON_THROW_ON_ERROR));
    }

    public function testProtectedIntentClearsStalePublicOverride(): void
    {
        $router = Router::make('test', 'v1');
        $router->get('/items', static fn (): array => [])->publicRoute()->protectedByMiddleware();
        $doc = (new OpenApiExporter())->export($router->contracts(), ['globalSecurity' => [['Bearer' => []]], 'securitySchemes' => ['Bearer' => ['type' => 'http', 'scheme' => 'bearer']]]);
        self::assertArrayNotHasKey('security', $doc['paths']['/test/v1/items']['get']);
    }

    /** @return iterable<string, array{string, string, array<string, mixed>, array<string, mixed>}> */
    public static function collisions(): iterable
    {
        yield 'same method and path' => ['/items', '/items', [], []];
        yield 'equivalent templates' => ['/items/(?P<id>\\d+)', '/items/(?P<slug>[a-z]+)', [], []];
        yield 'operation ids' => ['/items', '/other', ['operationId' => 'same'], ['operationId' => 'same']];
    }

    /** @param array<string, mixed> $first
     * @param array<string, mixed> $second
     */
    #[DataProvider('collisions')]
    public function testCollisionsAreRejected(string $one, string $two, array $first, array $second): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OpenApiExporter())->export([$this->contract($one, meta: $first), $this->contract($two, meta: $second)]);
    }

    public function testBalancedCapturesAndEscapedLiteralPaths(): void
    {
        $doc = (new OpenApiExporter())->export([$this->contract('/items/(?P<slug>(?:foo|bar)-[a-z()]+)'), $this->contract('/file\\.json')]);
        self::assertArrayHasKey('/test/v1/items/{slug}', $doc['paths']);
        self::assertArrayHasKey('/test/v1/file.json', $doc['paths']);
    }

    public function testUnsupportedRegexFailsExplicitly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new OpenApiExporter())->export([$this->contract('/items$')]);
    }

    public function testOpenApiEndpointItselfCanBeIncludedAndOnlyMatchesLiteralDot(): void
    {
        $dispatcher = new class () implements DispatcherInterface {
            public ?RouteDefinition $route = null;
            public function register(string $namespace, RouteDefinition $route, callable $callback, callable $permissionCallback): void
            {
                $this->route = $route;
            }
        };
        OpenApiRouteRegistrar::register('test/v1', static fn (): array => [], dispatcher: $dispatcher);
        $route = $dispatcher->route;
        self::assertNotNull($route);
        self::assertSame(1, preg_match('~^' . $route->uri . '$~', '/openapi.json'));
        self::assertSame(0, preg_match('~^' . $route->uri . '$~', '/openapiXjson'));
        $contract = $this->contract($route->uri, meta: $route->meta);
        $doc = (new OpenApiExporter())->export([$contract], ['includeExcluded' => true]);
        self::assertArrayHasKey('/test/v1/openapi.json', $doc['paths']);
    }

    public function testParameterCaseSerializationAndWordPressExclusiveBounds(): void
    {
        $contract = $this->contract(meta: ['parameters' => [
            ['name' => 'X-Token', 'in' => 'header'], ['name' => 'x-token', 'in' => 'header'],
            ['name' => 'Role', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']], 'style' => 'form', 'explode' => false],
        ]]);
        $contract['args'] = ['role' => ['type' => ['string', 'array'], 'items' => ['type' => 'integer', 'minimum' => 0, 'exclusiveMinimum' => true]]];
        $doc = (new OpenApiExporter())->export([$contract]);
        $params = $doc['paths']['/test/v1/items']['get']['parameters'];
        self::assertCount(3, $params);
        self::assertSame(['string', 'array'], $params[0]['schema']['type']);
        self::assertSame(0, $params[0]['schema']['items']['exclusiveMinimum']);
        self::assertArrayNotHasKey('minimum', $params[0]['schema']['items']);
        self::assertFalse($params[2]['explode']);
        self::assertSame('form', $params[2]['style']);
    }

    public function testHeadAndNoBodyResponsesAndStandardOperationMetadata(): void
    {
        $meta = ['summary' => 'Summary', 'description' => 'Description', 'deprecated' => true, 'responses' => [
            '200' => ['content' => ['application/json' => ['schema' => []]]],
            '204' => ['content' => ['application/json' => ['schema' => []]]],
            '304' => ['content' => ['application/json' => ['schema' => []]]],
            '4XX' => ['description' => 'Client failure'],
        ]];
        $doc = (new OpenApiExporter())->export([$this->contract(method: 'HEAD', meta: $meta)]);
        $op = $doc['paths']['/test/v1/items']['head'];
        self::assertSame('Summary', $op['summary']);
        self::assertTrue($op['deprecated']);
        foreach (['200', '204', '304'] as $status) {
            self::assertArrayNotHasKey('content', $op['responses'][$status]);
        }
        self::assertArrayHasKey('4XX', $op['responses']);
    }

    public function testWooCrudIdentifiersPaginationAndUpdateSchemas(): void
    {
        $registrar = new WooRouteRegistrar();
        $router = $registrar->register('test/v1', ['register' => false, 'defaultPerPage' => 40, 'maxPerPage' => 1500, 'operationIdPrefix' => 'shop']);
        $doc = (new OpenApiExporter())->export($router->contracts(), ['strictSchemas' => true, 'components' => $registrar->openApiComponents()]);
        $ids = [];
        foreach ($doc['paths'] as $ops) {
            foreach ($ops as $op) {
                $ids[] = $op['operationId'];
            }
        }
        self::assertCount(24, array_unique($ids));
        self::assertContains('shopWooCouponsPatch', $ids);
        $params = array_column($doc['paths']['/test/v1/woo/customers']['get']['parameters'], null, 'name');
        self::assertSame(40, $params['per_page']['schema']['default']);
        self::assertSame(1500, $params['per_page']['schema']['maximum']);
        self::assertFalse($params['role']['explode']);
        self::assertArrayNotHasKey('username', $doc['components']['schemas']['WooCustomerUpdateInput']['properties']);
        self::assertSame(1, $doc['components']['schemas']['WooCustomerUpdateInput']['minProperties']);
        self::assertArrayNotHasKey('required', $doc['components']['schemas']['WooCustomer']);
    }

    public function testAnalyticsHelperAndEmptyMetricMaps(): void
    {
        self::assertSame([], array_intersect(['after', 'interval'], array_keys(WooAnalyticsOpenApi::args('stock'))));
        self::assertArrayNotHasKey('page', WooAnalyticsOpenApi::args('customers/stats'));
        self::assertSame(100, WooAnalyticsOpenApi::args('products')['per_page']['maximum']);
        self::assertSame(24, count(WooAnalyticsOpenApi::components()['schemas']));
        $data = (new AnalyticsResponseMapper())->map('orders/stats', ['totals' => [], 'intervals' => [['subtotals' => []]]]);
        self::assertStringContainsString('"totals":{}', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertStringContainsString('"subtotals":{}', json_encode($data, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function internalCouponMeta(): iterable
    {
        foreach (['usage_count', 'coupon_amount', 'discount_type', 'date_expires', 'usage_limit', 'customer_email', '_used_by'] as $key) {
            yield $key . ' map' => [[$key => 99]];
            yield $key . ' list' => [[['key' => $key, 'value' => 99]]];
        }
    }

    /** @param array<mixed> $meta */
    #[DataProvider('internalCouponMeta')]
    public function testCouponInternalMetadataCannotInvokeSetters(array $meta): void
    {
        $this->expectException(ApiException::class);
        MetaDataHelper::normalizeCouponIncoming($meta);
    }
}
