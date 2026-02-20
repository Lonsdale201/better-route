<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Table;

final class TableListQuery
{
    /**
     * @param list<string> $fields
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $filters,
        public readonly ?string $sortField,
        public readonly string $sortDirection,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }
}
