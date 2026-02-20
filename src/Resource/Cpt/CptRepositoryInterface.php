<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Cpt;

interface CptRepositoryInterface
{
    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   perPage: int
     * }
     */
    public function list(string $postType, CptListQuery $query): array;

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function get(string $postType, int $id, array $fields): ?array;
}
