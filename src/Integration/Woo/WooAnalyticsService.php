<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

/** Read-only reports. Caller middleware authenticates; native WP/Woo permissions still apply. */
final class WooAnalyticsService
{
    /**
     * @return list<string>
     */
    public function reports(): array
    {
        return AnalyticsReports::REPORTS;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(string $report, mixed $request = []): array
    {
        // This check is mandatory even when the enclosing route has a custom permission callback.
        if (!function_exists('current_user_can') || !current_user_can('view_woocommerce_reports')) {
            throw new ApiException('Forbidden.', 403, 'forbidden');
        }
        $query = (new AnalyticsQueryParser())->parse($report, $request);
        $features = 'Automattic\\WooCommerce\\Admin\\Features\\Features';
        if (!class_exists($features) || !$features::is_enabled('analytics')) {
            throw new ApiException('WooCommerce Analytics is disabled or unavailable.', 503, 'analytics_unavailable');
        }
        $readiness = $report === 'stock' ? 'not_applicable' : $this->readiness($query->params);
        $comparisonReadiness = $query->comparison !== null ? $this->readiness($query->comparison) : $readiness;
        if (in_array($readiness, ['importing', 'incomplete', 'unknown'], true) || in_array($comparisonReadiness, ['importing', 'incomplete', 'unknown'], true)) {
            throw new ApiException('WooCommerce Analytics import is incomplete or its readiness cannot be verified.', 503, 'analytics_data_not_ready');
        }
        $result = $this->dispatch($query->report, $query->params);
        $result['meta']['readiness'] = $readiness;
        if ($query->comparison !== null) {
            $result['comparison'] = $this->dispatch($query->report, $query->comparison);
            $result['comparison']['meta']['readiness'] = $readiness;
        }
        return $result;
    }

    /** @param array<string, mixed> $params */
    private function readiness(array $params): string
    {
        $sync = 'Automattic\\WooCommerce\\Admin\\ReportsSync';
        if (!class_exists($sync)) {
            return 'unknown';
        }
        try {
            $stats = $sync::get_import_stats();
            if (!empty($stats['is_importing'])) {
                return 'importing';
            }
            foreach ($stats as $entry) {
                if (is_array($entry) && isset($entry['total'], $entry['imported']) && (int) $entry['imported'] < (int) $entry['total']) {
                    return 'incomplete';
                }
            }
            if (!isset($stats['imported_from'])) {
                return 'unknown';
            }
            if ((string) $stats['imported_from'] !== '-1') {
                $coverage = new \DateTimeImmutable((string) $stats['imported_from'], new \DateTimeZone('UTC'));
                $after = new \DateTimeImmutable((string) ($params['after'] ?? $params['registered_after'] ?? ''), wp_timezone());
                if ($after < $coverage) {
                    return 'incomplete';
                }
            }
            return 'idle';
        } catch (\Throwable) {
            return 'unknown';
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function dispatch(string $report, array $params): array
    {
        AnalyticsReports::definition($report);
        if (!function_exists('rest_do_request') || !class_exists('WP_REST_Request')) {
            throw new ApiException('WordPress REST API is unavailable.', 503, 'analytics_unavailable');
        }
        $request = new \WP_REST_Request('GET', '/wc-analytics/reports/' . $report);
        $request->set_query_params($params);
        // No loopback HTTP, credentials, user switching or permission bypass.
        $response = rest_do_request($request);
        $status = $response->get_status();
        if ($status >= 400) {
            if ($status === 401 || $status === 403) {
                throw new ApiException('Forbidden.', 403, 'forbidden');
            }
            throw new ApiException('WooCommerce Analytics report is unavailable.', 503, 'analytics_report_unavailable');
        }
        $mapper = new AnalyticsResponseMapper(function_exists('wc_get_price_decimals') ? wc_get_price_decimals() : 2);
        $data = $mapper->map($report, $response->get_data());
        $headers = array_change_key_case($response->get_headers(), CASE_LOWER);
        $meta = [];
        if (isset($params['page'], $params['per_page'])) {
            $meta = [
                'page' => $params['page'], 'perPage' => $params['per_page'],
                'total' => (int) ($headers['x-wp-total'] ?? 0),
                'totalPages' => (int) ($headers['x-wp-totalpages'] ?? 0),
                'totalUnit' => str_ends_with($report, '/stats') ? 'intervals' : 'items',
            ];
        }
        // No wrapper cache: never reuse caller-dependent data across users/scopes.
        return ['report' => $report, 'data' => $data, 'meta' => $meta];
    }
}
