<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Table;

use BetterRoute\Storage\WpdbAdapter;

final class WordPressTableRepository implements TableRepositoryInterface
{
    public function __construct(
        private readonly WpdbAdapter $adapter = new WpdbAdapter()
    ) {
    }

    public function list(string $table, string $primaryKey, TableListQuery $query): array
    {
        return $this->adapter->list(
            table: $table,
            primaryKey: $primaryKey,
            fields: $query->fields,
            filters: $query->filters,
            sortField: $query->sortField,
            sortDirection: $query->sortDirection,
            page: $query->page,
            perPage: $query->perPage
        );
    }

    public function get(string $table, string $primaryKey, int $id, array $fields): ?array
    {
        return $this->adapter->get(
            table: $table,
            primaryKey: $primaryKey,
            id: $id,
            fields: $fields
        );
    }

    public function create(string $table, string $primaryKey, array $payload, array $fields): array
    {
        return $this->adapter->create(
            table: $table,
            primaryKey: $primaryKey,
            payload: $payload,
            fields: $fields
        );
    }

    public function update(string $table, string $primaryKey, int $id, array $payload, array $fields): ?array
    {
        return $this->adapter->update(
            table: $table,
            primaryKey: $primaryKey,
            id: $id,
            payload: $payload,
            fields: $fields
        );
    }

    public function delete(string $table, string $primaryKey, int $id): bool
    {
        return $this->adapter->delete(
            table: $table,
            primaryKey: $primaryKey,
            id: $id
        );
    }
}
