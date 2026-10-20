<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

final class AnalyticsQueryParser
{
    public function __construct(private readonly ?\DateTimeZone $timezone = null)
    {
    }

    public function parse(string $report, mixed $request): AnalyticsQuery
    {
        $definition = AnalyticsReports::definition($report);
        $params = is_object($request) && method_exists($request, 'get_params') ? $request->get_params() : $request;
        if (!is_array($params)) {
            throw $this->invalid('query', 'must be an object of report parameters');
        }
        $allowed = [];
        if ($definition['dates']) {
            $allowed = ['after', 'before', 'compare_after', 'compare_before'];
        }
        if ($definition['paginated']) {
            $allowed = array_merge($allowed, ['page', 'per_page', 'orderby', 'order']);
        }
        if ($definition['interval']) {
            $allowed[] = 'interval';
        }
        if ($definition['segments'] !== []) {
            $allowed[] = 'segmentby';
        }
        foreach (array_keys($params) as $key) {
            if (!in_array($key, $allowed, true)) {
                throw $this->invalid((string) $key, 'unsupported parameter for this report');
            }
        }
        $result = [];
        if ($definition['paginated']) {
            $result['page'] = $this->integer($params['page'] ?? 1, 'page', 10000);
            $result['per_page'] = $this->integer($params['per_page'] ?? 20, 'per_page', 100);
            $result['orderby'] = $this->choice($params['orderby'] ?? $definition['sort'][0], 'orderby', $definition['sort']);
            $result['order'] = $this->choice($params['order'] ?? ($report === 'stock' ? 'asc' : 'desc'), 'order', ['asc', 'desc']);
        }
        if ($definition['interval']) {
            $result['interval'] = $this->choice($params['interval'] ?? 'day', 'interval', ['hour', 'day', 'week', 'month', 'quarter', 'year']);
        }
        if (isset($params['segmentby'])) {
            $result['segmentby'] = $this->choice($params['segmentby'], 'segmentby', $definition['segments']);
        }
        $comparison = null;
        if ($definition['dates']) {
            $timezone = $this->timezone ?? (function_exists('wp_timezone') ? wp_timezone() : new \DateTimeZone('UTC'));
            $now = new \DateTimeImmutable('now', $timezone);
            $after = $this->date($params['after'] ?? $now->modify('-7 days')->format('Y-m-d\TH:i:s'), 'after', $timezone);
            $before = $this->date($params['before'] ?? $now->format('Y-m-d\TH:i:s'), 'before', $timezone);
            $this->window($after, $before, $result['interval'] ?? null);
            $afterKey = $report === 'customers/stats' ? 'registered_after' : 'after';
            $beforeKey = $report === 'customers/stats' ? 'registered_before' : 'before';
            $result[$afterKey] = $after->format('Y-m-d\TH:i:s');
            $result[$beforeKey] = $before->format('Y-m-d\TH:i:s');
            if (array_key_exists('compare_after', $params) || array_key_exists('compare_before', $params)) {
                if (!isset($params['compare_after'], $params['compare_before'])) {
                    throw $this->invalid('compare_after', 'both comparison dates are required');
                }
                $ca = $this->date($params['compare_after'], 'compare_after', $timezone);
                $cb = $this->date($params['compare_before'], 'compare_before', $timezone);
                $this->window($ca, $cb, $result['interval'] ?? null);
                $comparison = array_replace($result, [$afterKey => $ca->format('Y-m-d\TH:i:s'), $beforeKey => $cb->format('Y-m-d\TH:i:s')]);
            }
        }
        return new AnalyticsQuery($report, $result, $comparison);
    }

    private function integer(mixed $value, string $key, int $max): int
    {
        if ((!is_int($value) && !(is_string($value) && ctype_digit($value))) || (float) $value < 1 || (float) $value > $max) {
            throw $this->invalid($key, sprintf('must be an integer between 1 and %d', $max));
        }
        return (int) $value;
    }

    /** @param list<string> $allowed */
    private function choice(mixed $value, string $key, array $allowed): string
    {
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw $this->invalid($key, 'unsupported value');
        }
        return $value;
    }

    private function date(mixed $value, string $key, \DateTimeZone $timezone): \DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})?$/D', $value) !== 1) {
            throw $this->invalid($key, 'must be an ISO8601 datetime');
        }
        try {
            $date = new \DateTimeImmutable($value, $timezone);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                throw $this->invalid($key, 'invalid calendar datetime');
            }
            return $date->setTimezone($timezone);
        } catch (\Exception) {
            throw $this->invalid($key, 'invalid calendar datetime');
        }
    }

    private function window(\DateTimeImmutable $after, \DateTimeImmutable $before, ?string $interval): void
    {
        $seconds = $before->getTimestamp() - $after->getTimestamp();
        if ($seconds < 0 || $seconds > 366 * 86400 || ($interval === 'hour' && $seconds > 31 * 86400)) {
            throw $this->invalid('after', 'window must be ordered and at most 366 days (31 for hourly intervals)');
        }
    }

    private function invalid(string $key, string $message): ApiException
    {
        return new ApiException('Invalid analytics query.', 400, 'validation_failed', ['fieldErrors' => [$key => [$message]]]);
    }
}
