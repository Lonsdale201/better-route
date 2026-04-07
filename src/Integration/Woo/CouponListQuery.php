<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

final class CouponListQuery
{
    /**
     * @param list<string> $fields
     */
    public function __construct(
        public readonly array $fields,
        public readonly ?string $code,
        public readonly ?string $search,
        public readonly string $sortField,
        public readonly string $sortDirection,
        public readonly int $page,
        public readonly int $perPage
    ) {
    }
}
