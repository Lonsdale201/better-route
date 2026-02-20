<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Table;

interface TableRepositoryInterface
{
    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   perPage: int
     * }
     */
    public function list(string $table, string $primaryKey, TableListQuery $query): array;

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function get(string $table, string $primaryKey, int $id, array $fields): ?array;

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public function create(string $table, string $primaryKey, array $payload, array $fields): array;

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function update(string $table, string $primaryKey, int $id, array $payload, array $fields): ?array;

    public function delete(string $table, string $primaryKey, int $id): bool;
}
