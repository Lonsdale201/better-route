<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

final class OrderListQuery
{
    /**
     * @param list<string> $fields
     * @param list<string> $status
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $status,
        public readonly ?int $customerId,
        public readonly ?string $search,
        public readonly string $sortField,
        public readonly string $sortDirection,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }
}
