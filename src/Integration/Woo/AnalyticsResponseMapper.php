<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

final class AnalyticsResponseMapper
{
    public function __construct(private readonly int $currencyDecimals = 2)
    {
        if ($currencyDecimals < 0 || $currencyDecimals > 8) {
            throw new \InvalidArgumentException('Currency decimals must be between 0 and 8.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function map(string $report, mixed $data): array
    {
        $definition = AnalyticsReports::definition($report);
        if ($data instanceof \stdClass) {
            $data = get_object_vars($data);
        }
        if (!is_array($data)) {
            throw new ApiException('Invalid analytics response.', 502, 'analytics_invalid_response');
        }
        if (!$definition['stats']) {
            $items = [];
            foreach ($this->rows($data) as $row) {
                $items[] = $this->metrics($row, $definition['fields']);
            }
            return ['items' => $items];
        }
        if (!isset($data['totals']) || (!is_array($data['totals']) && !$data['totals'] instanceof \stdClass)) {
            throw new ApiException('Analytics totals are unavailable.', 503, 'analytics_data_not_ready');
        }
        $result = ['totals' => $this->metrics((array) $data['totals'], $definition['fields'])];
        if ($definition['interval']) {
            $intervals = [];
            foreach ($this->rows($data['intervals'] ?? []) as $row) {
                $interval = [];
                foreach (['interval', 'date_start', 'date_end', 'date_start_gmt', 'date_end_gmt'] as $key) {
                    if (isset($row[$key]) && is_string($row[$key])) {
                        $interval[$key] = $row[$key];
                    }
                }
                $interval['subtotals'] = $this->metrics($this->objectRow($row['subtotals'] ?? null), $definition['fields']);
                $intervals[] = $interval;
            }
            $result['intervals'] = $intervals;
        }
        return $result;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, string> $fields
     * @return array<string, mixed>|\stdClass
     */
    private function metrics(array $row, array $fields, bool $segments = true): array|\stdClass
    {
        $result = [];
        foreach ($fields as $key => $type) {
            if (!array_key_exists($key, $row) || $row[$key] === null) {
                continue;
            }
            $value = $row[$key];
            if ($type === 'string') {
                if (is_string($value)) {
                    $result[$key] = $value;
                }
            } elseif (is_numeric($value) && is_finite((float) $value)) {
                $result[$key] = match ($type) {
                    'count' => (int) $value,
                    'number' => (float) $value,
                    // Preserve upstream decimal strings without a float round-trip.
                    'money' => is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/D', $value) === 1
                        ? $value : number_format((float) $value, $this->currencyDecimals, '.', ''),
                    default => throw new \LogicException('Invalid analytics field type.'),
                };
            }
        }
        if ($segments && isset($row['segments']) && is_array($row['segments'])) {
            $result['segments'] = [];
            foreach ($this->rows($row['segments']) as $segment) {
                $id = $segment['segment_id'] ?? null;
                if (!is_int($id) && !is_string($id)) {
                    continue;
                }
                $result['segments'][] = [
                    'segment_id' => $id,
                    'subtotals' => $this->metrics($this->objectRow($segment['subtotals'] ?? null), $fields, false),
                ];
            }
        }
        return $result !== [] ? $result : (object) [];
    }

    /**
     * @param array<mixed> $rows
     * @return list<array<string, mixed>>
     */
    private function rows(array $rows): array
    {
        if (!array_is_list($rows) || count($rows) > 100) {
            throw new ApiException('Invalid or excessive analytics response.', 502, 'analytics_invalid_response');
        }
        foreach ($rows as $index => $row) {
            if ($row instanceof \stdClass) {
                $rows[$index] = get_object_vars($row);
                continue;
            }
            if (!is_array($row)) {
                throw new ApiException('Invalid analytics response row.', 502, 'analytics_invalid_response');
            }
        }
        return $rows;
    }

    /** @return array<string, mixed> */
    private function objectRow(mixed $row): array
    {
        return is_array($row) ? $row : ($row instanceof \stdClass ? get_object_vars($row) : []);
    }
}
