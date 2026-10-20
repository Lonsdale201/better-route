<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

final class AnalyticsQuery
{
    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed>|null $comparison
     */
    public function __construct(
        public readonly string $report,
        public readonly array $params,
        public readonly ?array $comparison = null
    ) {
    }
}
