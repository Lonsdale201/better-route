<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

final class CustomerListQuery
{
    /**
     * @param list<string> $fields
     * @param list<string> $role
     */
    public function __construct(
        public readonly array $fields,
        public readonly array $role,
        public readonly ?string $email,
        public readonly ?string $search,
        public readonly string $sortField,
        public readonly string $sortDirection,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }
}
