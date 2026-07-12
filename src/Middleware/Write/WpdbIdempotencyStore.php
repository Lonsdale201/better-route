<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use RuntimeException;

final class WpdbIdempotencyStore implements IdempotencyStoreInterface
{
    public function __construct(
        private readonly string $table = 'better_route_idempotency',
        private readonly ?string $prefix = null
    ) {
        $this->assertWpdb();
    }

    public function get(string $key): mixed
    {
        $wpdb = $this->wpdb();
        $storageKey = $this->storageKey($key);
        $now = time();
        $table = $this->tableName();

        $row = $wpdb->get_row(
            $wpdb->prepare(
                sprintf('SELECT value, expires_at FROM %s WHERE idempotency_key = %%s LIMIT 1', $table),
                $storageKey
            ),
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );

        if (!is_array($row)) {
            return null;
        }

        $expiresAt = isset($row['expires_at']) && is_numeric($row['expires_at']) ? (int) $row['expires_at'] : 0;
        if ($expiresAt <= $now) {
            $wpdb->query($wpdb->prepare(
                sprintf('DELETE FROM %s WHERE idempotency_key = %%s', $table),
                $storageKey
            ));
            return null;
        }

        $serialized = is_string($row['value'] ?? null) ? $row['value'] : '';
        if ($serialized === '') {
            return null;
        }

        // Restrict object deserialization to the library's own response DTO so a
        // tampered row cannot trigger PHP object injection via __wakeup/__destruct.
        return unserialize($serialized, ['allowed_classes' => [\BetterRoute\Http\Response::class]]);
    }

    public function set(string $key, mixed $value, int $ttlSeconds): void
    {
        $wpdb = $this->wpdb();
        $table = $this->tableName();
        $expiresAt = time() + max(1, $ttlSeconds);

        $result = $wpdb->query($wpdb->prepare(
            sprintf(
                'REPLACE INTO %s (idempotency_key, value, expires_at, updated_at) VALUES (%%s, %%s, %%d, %%d)',
                $table
            ),
            $this->storageKey($key),
            serialize($value),
            $expiresAt,
            time()
        ));

        if ($result === false) {
            throw new RuntimeException('Unable to write idempotency record.');
        }
    }

    public function installSchema(): void
    {
        $wpdb = $this->wpdb();
        $charsetCollate = method_exists($wpdb, 'get_charset_collate') ? (string) $wpdb->get_charset_collate() : '';
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                idempotency_key varchar(64) NOT NULL,
                value longtext NOT NULL,
                expires_at bigint unsigned NOT NULL,
                updated_at bigint unsigned NOT NULL,
                PRIMARY KEY  (idempotency_key),
                KEY expires_at (expires_at)
            ) %s',
            $this->tableName(),
            $charsetCollate
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new RuntimeException('Unable to install idempotency table.');
        }
    }

    private function storageKey(string $key): string
    {
        return sha1($key);
    }

    private function tableName(): string
    {
        $table = $this->table;
        if (str_contains($table, '.')) {
            throw new RuntimeException('Cross-database idempotency table names are not allowed.');
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new RuntimeException('Invalid idempotency table name.');
        }

        $prefix = $this->prefix;
        if ($prefix === null && isset($GLOBALS['wpdb']) && is_object($GLOBALS['wpdb']) && isset($GLOBALS['wpdb']->prefix) && is_string($GLOBALS['wpdb']->prefix)) {
            $prefix = $GLOBALS['wpdb']->prefix;
        }

        if (is_string($prefix) && $prefix !== '' && !str_starts_with($table, $prefix)) {
            $table = $prefix . $table;
        }

        return '`' . $table . '`';
    }

    private function assertWpdb(): void
    {
        $this->wpdb();
    }

    private function wpdb(): object
    {
        if (
            isset($GLOBALS['wpdb'])
            && is_object($GLOBALS['wpdb'])
            && method_exists($GLOBALS['wpdb'], 'prepare')
            && method_exists($GLOBALS['wpdb'], 'get_row')
            && method_exists($GLOBALS['wpdb'], 'query')
        ) {
            return $GLOBALS['wpdb'];
        }

        throw new RuntimeException('Global $wpdb is not available.');
    }
}
