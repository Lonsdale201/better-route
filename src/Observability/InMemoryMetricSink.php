<?php

declare(strict_types=1);

namespace BetterRoute\Observability;

final class InMemoryMetricSink implements MetricSinkInterface
{
    /** @var array<string, int> */
    private array $counters = [];

    /** @var array<string, array{count: int, sum: float}> */
    private array $observations = [];

    /**
     * @param array<string, string> $labels
     */
    public function increment(string $name, int $value = 1, array $labels = []): void
    {
        $key = $this->key($name, $labels);
        $this->counters[$key] = ($this->counters[$key] ?? 0) + $value;
    }

    /**
     * @param array<string, string> $labels
     */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $key = $this->key($name, $labels);

        if (!isset($this->observations[$key])) {
            $this->observations[$key] = ['count' => 0, 'sum' => 0.0];
        }

        $this->observations[$key]['count']++;
        $this->observations[$key]['sum'] += $value;
    }

    /**
     * @return array<string, int>
     */
    public function counters(): array
    {
        return $this->counters;
    }

    /**
     * @return array<string, array{count: int, sum: float}>
     */
    public function observations(): array
    {
        return $this->observations;
    }

    /**
     * @param array<string, string> $labels
     */
    private function key(string $name, array $labels): string
    {
        ksort($labels);
        return $name . '|' . json_encode($labels);
    }
}
