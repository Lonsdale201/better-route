<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

final class ProductListQuery
{
    /**
     * @param list<string> $fields
     * @param list<string> $status
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $status,
        public readonly ?string $type,
        public readonly ?string $sku,
        public readonly ?string $search,
        public readonly ?string $stockStatus,
        public readonly string $sortField,
        public readonly string $sortDirection,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }
}
