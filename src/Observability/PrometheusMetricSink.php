<?php

declare(strict_types=1);

namespace BetterRoute\Observability;

final class PrometheusMetricSink implements MetricSinkInterface
{
    /** @var array<string, array{name: string, labels: array<string, string>, value: int}> */
    private array $counters = [];

    /** @var array<string, array{name: string, labels: array<string, string>, count: int, sum: float}> */
    private array $observations = [];

    /**
     * @param array<string, string> $labels
     */
    public function increment(string $name, int $value = 1, array $labels = []): void
    {
        $key = $this->key($name, $labels);

        if (!isset($this->counters[$key])) {
            $this->counters[$key] = [
                'name' => $name,
                'labels' => $labels,
                'value' => 0,
            ];
        }

        $this->counters[$key]['value'] += $value;
    }

    /**
     * @param array<string, string> $labels
     */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $key = $this->key($name, $labels);

        if (!isset($this->observations[$key])) {
            $this->observations[$key] = [
                'name' => $name,
                'labels' => $labels,
                'count' => 0,
                'sum' => 0.0,
            ];
        }

        $this->observations[$key]['count']++;
        $this->observations[$key]['sum'] += $value;
    }

    public function render(): string
    {
        $lines = [];

        $counterNames = [];
        foreach ($this->counters as $counter) {
            $counterNames[$counter['name']] = true;
        }

        foreach (array_keys($counterNames) as $name) {
            $lines[] = '# TYPE ' . $name . ' counter';

            foreach ($this->counters as $counter) {
                if ($counter['name'] !== $name) {
                    continue;
                }

                $lines[] = $this->metricLine($name, $counter['labels'], (string) $counter['value']);
            }
        }

        $observationNames = [];
        foreach ($this->observations as $observation) {
            $observationNames[$observation['name']] = true;
        }

        foreach (array_keys($observationNames) as $name) {
            $lines[] = '# TYPE ' . $name . ' summary';

            foreach ($this->observations as $observation) {
                if ($observation['name'] !== $name) {
                    continue;
                }

                $lines[] = $this->metricLine($name . '_sum', $observation['labels'], sprintf('%.6F', $observation['sum']));
                $lines[] = $this->metricLine($name . '_count', $observation['labels'], (string) $observation['count']);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $labels
     */
    private function key(string $name, array $labels): string
    {
        ksort($labels);
        return $name . '|' . json_encode($labels);
    }

    /**
     * @param array<string, string> $labels
     */
    private function metricLine(string $name, array $labels, string $value): string
    {
        if ($labels === []) {
            return $name . ' ' . $value;
        }

        ksort($labels);
        $parts = [];
        foreach ($labels as $label => $labelValue) {
            $parts[] = sprintf('%s="%s"', $label, $this->escapeLabelValue($labelValue));
        }

        return sprintf('%s{%s} %s', $name, implode(',', $parts), $value);
    }

    private function escapeLabelValue(string $value): string
    {
        return str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value);
    }
}
