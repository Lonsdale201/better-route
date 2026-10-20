<?php

declare(strict_types=1);

namespace BetterRouteOpenApiFixtures;

use BetterRoute\Integration\Woo\AnalyticsReports;
use BetterRoute\Integration\Woo\AnalyticsResponseMapper;
use BetterRoute\Integration\Woo\WooAnalyticsOpenApi;
use BetterRoute\Integration\Woo\WooRouteRegistrar;
use BetterRoute\OpenApi\OpenApiExporter;
use BetterRoute\Resource\Resource;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use BetterRoute\Router\Router;

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

/** @return array<string, mixed> */
function bundle(): array
{
    $registrar = new WooRouteRegistrar();
    $router = $registrar->register('example/v1', ['register' => false]);
    foreach (AnalyticsReports::REPORTS as $report) {
        $router->get('/analytics/' . $report, static fn (): array => [])
            ->args(WooAnalyticsOpenApi::args($report))->meta(WooAnalyticsOpenApi::meta($report));
    }
    $options = ['strictSchemas' => true, 'components' => $registrar->openApiComponents()];
    $exporter = new OpenApiExporter();
    $dispatcher = new class () implements DispatcherInterface {
        public function register(string $namespace, RouteDefinition $route, callable $callback, callable $permissionCallback): void
        {
        }
    };
    $resource = Resource::make('articles')->restNamespace('content/v1')->sourceCpt('post')->fields(['id', 'title']);
    $resource->register($dispatcher);
    $table = Resource::make('records')->restNamespace('content/v1')->sourceTable('br_fixture', 'id')->fields(['id', 'title']);
    $table->register($dispatcher);
    // Resource application schemas are intentionally supplied by the application.
    $resourceOptions = ['strictSchemas' => true, 'components' => $registrar->openApiComponents()];
    foreach (['Article', 'Articles', 'Record', 'Records'] as $base) {
        foreach (['', 'Input', 'Response', 'ListResponse'] as $suffix) {
            $resourceOptions['components']['schemas'][$base . $suffix] = ['type' => 'object'];
        }
    }
    $samples = [
        ['schema' => 'WooCustomerResponse', 'value' => ['data' => ['email' => 'fixture@example.invalid']]],
        ['schema' => 'WooCouponInput', 'value' => ['amount' => 2.5, 'free_shipping' => 'yes', 'meta_data' => ['note' => ['arbitrary' => true]]]],
        ['schema' => 'WooCouponInput', 'value' => ['date_expires' => '', 'limit_usage_to_x_items' => null]],
        ['schema' => 'WooCustomerUpdateInput', 'value' => ['username' => 'forbidden'], 'valid' => false],
        ['schema' => 'WooCustomerUpdateInput', 'value' => (object) [], 'valid' => false],
        ['schema' => 'WooCouponInput', 'value' => ['usage_count' => 3], 'valid' => false],
        ['schema' => 'WooCouponInput', 'value' => ['free_shipping' => 2], 'valid' => false],
        ['response' => 'ErrorResponse', 'value' => ['error' => ['code' => 'validation_failed', 'message' => 'Invalid request.', 'requestId' => 'fixture', 'details' => []]]],
        ['response' => 'ErrorResponse', 'value' => ['code' => 'rest_invalid_param', 'message' => 'Invalid parameter(s): per_page', 'data' => ['status' => 400]]],
    ];
    $mapper = new AnalyticsResponseMapper();
    foreach (AnalyticsReports::REPORTS as $report) {
        $definition = AnalyticsReports::definition($report);
        $metrics = [];
        foreach ($definition['fields'] as $field => $type) {
            $metrics[$field] = match ($type) {
                'count' => 2, 'number' => 1.5, 'money' => '123456789012345.67', default => 'fixture',
            };
        }
        $raw = $definition['stats'] ? ['totals' => $metrics, 'intervals' => [['interval' => 'day', 'subtotals' => $metrics]]] : [$metrics];
        $meta = ['readiness' => $report === 'stock' ? 'not_applicable' : 'idle'];
        if ($definition['paginated']) {
            $meta += ['page' => 1, 'perPage' => 20, 'total' => 1, 'totalPages' => 1, 'totalUnit' => $definition['stats'] ? 'intervals' : 'items'];
        }
        $schema = substr(WooAnalyticsOpenApi::meta($report)['responseSchema'], strlen('#/components/schemas/'));
        $samples[] = ['schema' => $schema, 'value' => ['report' => $report, 'data' => $mapper->map($report, $raw), 'meta' => $meta]];
        $empty = $definition['stats'] ? ['totals' => [], 'intervals' => []] : [[]];
        $samples[] = ['schema' => $schema, 'value' => ['report' => $report, 'data' => $mapper->map($report, $empty), 'meta' => $meta]];
    }
    $authRouter = Router::make('auth', 'v1');
    $authRouter->get('/ping', static fn (): array => [])->protectedByMiddleware([['Alias' => ['editor']], (object) []]);
    return [
        'documents' => [
            'woo' => $exporter->export($router->contracts(), $options),
            'resources' => $exporter->export(array_merge($resource->contracts(), $table->contracts()), $resourceOptions),
            'empty' => $exporter->export([], ['components' => ['schemas' => ['Empty' => []]]]),
            'auth' => $exporter->export($authRouter->contracts(), ['strictSchemas' => true, 'securitySchemes' => [
                'Bearer' => ['type' => 'http', 'scheme' => 'bearer'],
                'Alias' => ['$ref' => '#/components/securitySchemes/Bearer'],
                'OAuth' => ['type' => 'oauth2', 'flows' => ['clientCredentials' => ['tokenUrl' => 'https://example.invalid/token', 'scopes' => []]]],
            ]]),
        ],
        'samples' => $samples,
    ];
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    $json = json_encode(bundle(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    if (isset($argv[1])) {
        file_put_contents($argv[1], $json);
    } else {
        echo $json . PHP_EOL;
    }
}
