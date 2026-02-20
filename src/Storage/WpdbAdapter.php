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

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public function create(string $table, string $primaryKey, array $payload, array $fields): array
    {
        $this->assertIdentifier($primaryKey, 'primary key');
        $this->assertWritablePayload($payload, $fields);

        $wpdb = $this->wpdb();
        $raw = $this->wpdbRaw();
        $tableName = $this->qualifiedTableName($table);

        $columns = [];
        $placeholders = [];
        $bindings = [];
        foreach ($payload as $column => $value) {
            $columns[] = $this->quoteIdentifier($column);
            $placeholders[] = $this->placeholderFor($value);
            $bindings[] = $value;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $tableName,
            implode(', ', $columns),
            implode(', ', $placeholders)
        );

        $prepared = $wpdb->prepare($sql, ...$bindings);
        $this->executeWriteQuery($raw, $prepared);

        $id = $this->lastInsertId($raw);
        if ($id < 1) {
            throw new RuntimeException('Unable to resolve inserted row id.');
        }

        $row = $this->get($table, $primaryKey, $id, $fields);
        return $row ?? [$primaryKey => $id];
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function update(string $table, string $primaryKey, int $id, array $payload, array $fields): ?array
    {
        $existing = $this->get($table, $primaryKey, $id, $fields);
        if ($existing === null) {
            return null;
        }

        if ($payload === []) {
            return $existing;
        }

        $this->assertIdentifier($primaryKey, 'primary key');
        $this->assertWritablePayload($payload, $fields);

        $wpdb = $this->wpdb();
        $raw = $this->wpdbRaw();
        $tableName = $this->qualifiedTableName($table);

        $sets = [];
        $bindings = [];
        foreach ($payload as $column => $value) {
            $sets[] = sprintf('%s = %s', $this->quoteIdentifier($column), $this->placeholderFor($value));
            $bindings[] = $value;
        }
        $bindings[] = $id;

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = %%d',
            $tableName,
            implode(', ', $sets),
            $this->quoteIdentifier($primaryKey)
        );

        $prepared = $wpdb->prepare($sql, ...$bindings);
        $this->executeWriteQuery($raw, $prepared);

        return $this->get($table, $primaryKey, $id, $fields);
    }

    public function delete(string $table, string $primaryKey, int $id): bool
    {
        $this->assertIdentifier($primaryKey, 'primary key');
        $wpdb = $this->wpdb();
        $raw = $this->wpdbRaw();
        $tableName = $this->qualifiedTableName($table);

        $sql = sprintf(
            'DELETE FROM %s WHERE %s = %%d LIMIT 1',
            $tableName,
            $this->quoteIdentifier($primaryKey)
        );
        $prepared = $wpdb->prepare($sql, $id);
        $affected = $this->executeWriteQuery($raw, $prepared);

        return $affected > 0;
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
                return new class ($wpdb) implements WpdbClient {
                    public function __construct(private readonly object $wpdb)
                    {
                    }

                    public function prepare(string $query, mixed ...$args): mixed
                    {
                        return $this->wpdb->prepare($query, ...$args);
                    }

                    public function get_results(mixed $query, mixed $output = null): mixed
                    {
                        return $this->wpdb->get_results($query, $output);
                    }

                    public function get_var(mixed $query): mixed
                    {
                        return $this->wpdb->get_var($query);
                    }

                    public function get_row(mixed $query, mixed $output = null): mixed
                    {
                        return $this->wpdb->get_row($query, $output);
                    }
                };
            }
        }

        throw new RuntimeException('Global $wpdb is not available.');
    }

    private function wpdbRaw(): object
    {
        if (isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && method_exists($GLOBALS['wpdb'], 'query')) {
            return $GLOBALS['wpdb'];
        }

        throw new RuntimeException('Global $wpdb query API is not available.');
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

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     */
    private function assertWritablePayload(array $payload, array $fields): void
    {
        if ($payload === []) {
            throw new RuntimeException('Payload cannot be empty.');
        }

        $allowed = array_flip($fields);
        foreach ($payload as $key => $value) {
            $column = (string) $key;
            if (!isset($allowed[$column])) {
                throw new RuntimeException(sprintf('Column "%s" is not allowed.', $column));
            }
            $this->assertIdentifier($column, 'field');
            if (is_object($value)) {
                throw new RuntimeException(sprintf('Column "%s" cannot contain object payload.', $column));
            }
        }
    }

    private function executeWriteQuery(object $wpdb, mixed $preparedQuery): int
    {
        $result = $wpdb->query($preparedQuery);
        if ($result === false) {
            throw new RuntimeException('Database write operation failed.');
        }

        return (int) $result;
    }

    private function lastInsertId(object $wpdb): int
    {
        if (isset($wpdb->insert_id) && is_numeric($wpdb->insert_id)) {
            return (int) $wpdb->insert_id;
        }

        if (method_exists($wpdb, 'get_var')) {
            $value = $wpdb->get_var('SELECT LAST_INSERT_ID()');
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return 0;
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
