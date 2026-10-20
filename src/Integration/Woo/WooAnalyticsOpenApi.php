<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

/** Reusable contracts for manually registered, report-specific Analytics routes. */
final class WooAnalyticsOpenApi
{
    /** @return array<string, array<string, mixed>> */
    public static function args(string $report): array
    {
        $definition = AnalyticsReports::definition($report);
        $args = [];
        if ($definition['dates']) {
            foreach (['after', 'before', 'compare_after', 'compare_before'] as $key) {
                $args[$key] = [
                    'type' => 'string',
                    'pattern' => '^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?$',
                    'description' => 'ISO8601 datetime; omitted offset uses the shop timezone. Comparison dates must be supplied together. Maximum window 366 days, or 31 for hourly intervals.',
                ];
            }
        }
        if ($definition['paginated']) {
            $args['page'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000, 'default' => 1];
            $args['per_page'] = ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20];
            $args['orderby'] = ['type' => 'string', 'enum' => $definition['sort'], 'default' => $definition['sort'][0]];
            $args['order'] = ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => $report === 'stock' ? 'asc' : 'desc'];
        }
        if ($definition['interval']) {
            $args['interval'] = ['type' => 'string', 'enum' => ['hour', 'day', 'week', 'month', 'quarter', 'year'], 'default' => 'day'];
        }
        if ($definition['segments'] !== []) {
            $args['segmentby'] = ['type' => 'string', 'enum' => $definition['segments']];
        }
        return $args;
    }

    /** @return array<string, mixed> */
    public static function meta(string $report, ?string $operationId = null): array
    {
        AnalyticsReports::definition($report);
        return [
            'operationId' => $operationId ?? lcfirst(self::name($report)),
            'tags' => ['WooAnalytics'],
            'summary' => 'Read WooCommerce ' . $report . ' report',
            'description' => 'Requires native view_woocommerce_reports and upstream report permissions. Uses the current WP user. Unknown, running, incomplete or insufficient historical import coverage returns 503. Authentication scheme metadata must be supplied by the application.',
            'responseSchema' => '#/components/schemas/' . self::name($report) . 'Response',
            'responses' => array_fill_keys(['400', '403', '502', '503'], ['$ref' => '#/components/responses/ErrorResponse']),
        ];
    }

    /** @return array<string, mixed> */
    public static function components(): array
    {
        $schemas = [];
        foreach (AnalyticsReports::REPORTS as $report) {
            $definition = AnalyticsReports::definition($report);
            $metrics = [];
            foreach ($definition['fields'] as $field => $type) {
                $metrics[$field] = match ($type) {
                    'count' => ['type' => 'integer'],
                    'number' => ['type' => 'number'],
                    'money' => ['type' => 'string', 'pattern' => '^-?\d+(?:\.\d+)?$'],
                    default => ['type' => 'string'],
                };
            }
            $baseMetrics = self::object($metrics);
            $metrics['segments'] = ['type' => 'array', 'maxItems' => 100, 'items' => self::object([
                'segment_id' => ['type' => ['integer', 'string']], 'subtotals' => $baseMetrics,
            ], ['segment_id', 'subtotals'])];
            $statsMetrics = self::object($metrics);
            if ($definition['stats']) {
                $data = ['totals' => $statsMetrics];
                if ($definition['interval']) {
                    $interval = ['subtotals' => $statsMetrics];
                    foreach (['interval', 'date_start', 'date_end', 'date_start_gmt', 'date_end_gmt'] as $key) {
                        $interval[$key] = ['type' => 'string'];
                    }
                    $data['intervals'] = ['type' => 'array', 'maxItems' => 100, 'items' => self::object($interval, ['subtotals'])];
                }
            } else {
                $data = ['items' => ['type' => 'array', 'maxItems' => 100, 'items' => $statsMetrics]];
            }
            $meta = ['readiness' => ['type' => 'string', 'enum' => [$report === 'stock' ? 'not_applicable' : 'idle']]];
            if ($definition['paginated']) {
                $meta += [
                    'page' => ['type' => 'integer', 'minimum' => 1],
                    'perPage' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
                    'total' => ['type' => 'integer', 'minimum' => 0],
                    'totalPages' => ['type' => 'integer', 'minimum' => 0],
                    'totalUnit' => ['type' => 'string', 'const' => $definition['stats'] ? 'intervals' : 'items'],
                ];
            }
            $result = self::object([
                'report' => ['type' => 'string', 'const' => $report],
                'data' => self::object($data, array_keys($data)),
                'meta' => self::object($meta, array_keys($meta)),
            ], ['report', 'data', 'meta']);
            $schemas[self::name($report) . 'Result'] = $result;
            if ($definition['dates']) {
                $result['properties']['comparison'] = ['$ref' => '#/components/schemas/' . self::name($report) . 'Result'];
            }
            $schemas[self::name($report) . 'Response'] = $result;
        }
        return ['schemas' => $schemas];
    }

    private static function name(string $report): string
    {
        return 'WooAnalytics' . str_replace(' ', '', ucwords(str_replace('/', ' ', $report)));
    }

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
        if ($required !== []) {
            $schema['required'] = $required;
        }
        return $schema;
    }
}
