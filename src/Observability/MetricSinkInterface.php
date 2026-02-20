<?php

declare(strict_types=1);

namespace BetterRoute\Observability;

interface MetricSinkInterface
{
    /**
     * @param array<string, string> $labels
     */
    public function increment(string $name, int $value = 1, array $labels = []): void;

    /**
     * @param array<string, string> $labels
     */
    public function observe(string $name, float $value, array $labels = []): void;
}
