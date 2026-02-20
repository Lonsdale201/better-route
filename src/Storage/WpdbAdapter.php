<?php

declare(strict_types=1);

namespace BetterRoute\Storage;

use RuntimeException;

final class WpdbAdapter
{
    /**
     * @param list<string> $fields
     * @param array<string, mixed> $filters
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   perPage: int
     * }
     */
    public function list(
        string $table,
        string $primaryKey,
        array $fields,
        array $filters,
        ?string $sortField,
        string $sortDirection,
        int $page,
        int $perPage
    ): array {
        $wpdb = $this->wpdb();
        $tableName = $this->qualifiedTableName($table);
        $this->assertIdentifier($primaryKey, 'primary key');

        foreach ($fields as $field) {
            $this->assertIdentifier($field, 'field');
        }

        foreach (array_keys($filters) as $filterField) {
            $this->assertIdentifier((string) $filterField, 'filter field');
        }

        if ($sortField !== null) {
            $this->assertIdentifier($sortField, 'sort field');
        }

        $select = implode(', ', array_map(fn (string $field): string => $this->quoteIdentifier($field), $fields));

        $whereSql = '';
        $whereBindings = [];
        if ($filters !== []) {
            $conditions = [];
            foreach ($filters as $column => $value) {
                if ($value === null) {
                    $conditions[] = sprintf('%s IS NULL', $this->quoteIdentifier($column));
                    continue;
                }

                $conditions[] = sprintf('%s = %s', $this->quoteIdentifier($column), $this->placeholderFor($value));
                $whereBindings[] = $value;
            }

            if ($conditions !== []) {
                $whereSql = ' WHERE ' . implode(' AND ', $conditions);
            }
        }

        $orderBySql = '';
        if ($sortField !== null) {
            $direction = strtoupper($sortDirection) === 'DESC' ? 'DESC' : 'ASC';
            $orderBySql = sprintf(' ORDER BY %s %s', $this->quoteIdentifier($sortField), $direction);
        }

        $offset = ($page - 1) * $perPage;
        $itemsSql = sprintf(
            'SELECT %s FROM %s%s%s LIMIT %%d OFFSET %%d',
            $select,
            $tableName,
            $whereSql,
            $orderBySql
        );
        $itemsPrepared = $wpdb->prepare($itemsSql, ...array_merge($whereBindings, [$perPage, $offset]));
        $format = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $items = $wpdb->get_results($itemsPrepared, $format);
        if (!is_array($items)) {
            $items = [];
        }

        $countSql = sprintf('SELECT COUNT(*) FROM %s%s', $tableName, $whereSql);
        $countPrepared = $wpdb->prepare($countSql, ...$whereBindings);
        $total = (int) $wpdb->get_var($countPrepared);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'perPage' => $perPage,
        ];
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function get(string $table, string $primaryKey, int $id, array $fields): ?array
    {
        $wpdb = $this->wpdb();
        $tableName = $this->qualifiedTableName($table);
        $this->assertIdentifier($primaryKey, 'primary key');
        foreach ($fields as $field) {
            $this->assertIdentifier($field, 'field');
        }

        $select = implode(', ', array_map(fn (string $field): string => $this->quoteIdentifier($field), $fields));
        $idPlaceholder = $this->placeholderFor($id);
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = %s LIMIT 1',
            $select,
            $tableName,
            $this->quoteIdentifier($primaryKey),
            $idPlaceholder
        );
        $prepared = $wpdb->prepare($sql, $id);
        $format = defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A';
        $row = $wpdb->get_row($prepared, $format);

        return is_array($row) ? $row : null;
    }

    private function wpdb(): WpdbClient
    {
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb'])) {
            $wpdb = $GLOBALS['wpdb'];
            if (
                method_exists($wpdb, 'prepare')
                && method_exists($wpdb, 'get_results')
                && method_exists($wpdb, 'get_var')
                && method_exists($wpdb, 'get_row')
            ) {
                /** @var WpdbClient $wpdb */
                return $wpdb;
            }
        }

        throw new RuntimeException('Global $wpdb is not available.');
    }

    private function qualifiedTableName(string $table): string
    {
        $resolved = $table;
        $prefix = $this->wpdbPrefix();
        if ($prefix !== '' && !str_contains($table, '.') && !str_starts_with($table, $prefix)) {
            $resolved = $prefix . $table;
        }

        foreach (explode('.', $resolved) as $segment) {
            $this->assertIdentifier($segment, 'table segment');
        }

        return implode('.', array_map(fn (string $segment): string => $this->quoteIdentifier($segment), explode('.', $resolved)));
    }

    private function wpdbPrefix(): string
    {
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && isset($GLOBALS['wpdb']->prefix) && is_string($GLOBALS['wpdb']->prefix)) {
            return $GLOBALS['wpdb']->prefix;
        }

        return '';
    }

    private function assertIdentifier(string $identifier, string $label): void
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier)) {
            throw new RuntimeException(sprintf('Invalid %s "%s".', $label, $identifier));
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . $identifier . '`';
    }

    private function placeholderFor(mixed $value): string
    {
        if (is_int($value) || is_bool($value)) {
            return '%d';
        }

        if (is_float($value)) {
            return '%f';
        }

        return '%s';
    }
}
