<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use RuntimeException;

final class WpdbSingleUseTokenStore implements SingleUseTokenStoreInterface
{
    public function __construct(
        private readonly string $table = 'better_route_single_use_tokens',
        private readonly ?string $prefix = null
    ) {
        $this->assertWpdb();
    }

    public function consume(string $tokenHash): ?array
    {
        $wpdb = $this->wpdb();
        $table = $this->tableName();
        $storageKey = $this->storageKey($tokenHash);
        $now = time();

        $this->deleteExpired($now);

        $updated = $wpdb->query($wpdb->prepare(
            sprintf('UPDATE %s SET used = 1, consumed_at = %%d, updated_at = %%d WHERE token_hash = %%s AND used = 0 AND expires_at > %%d', $table),
            $now,
            $now,
            $storageKey,
            $now
        ));

        if ($updated === false) {
            throw new RuntimeException('Unable to consume single-use token.');
        }

        if ((int) $updated !== 1) {
            return null;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                sprintf('SELECT context FROM %s WHERE token_hash = %%s LIMIT 1', $table),
                $storageKey
            ),
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );

        if (!is_array($row)) {
            return [];
        }

        return $this->decodeContext(is_string($row['context'] ?? null) ? $row['context'] : '');
    }

    public function store(string $tokenHash, array $context, int $ttlSeconds): void
    {
        $wpdb = $this->wpdb();
        $table = $this->tableName();
        $now = time();
        $expiresAt = $now + max(1, $ttlSeconds);

        $result = $wpdb->query($wpdb->prepare(
            sprintf(
                'REPLACE INTO %s (token_hash, context, used, consumed_at, expires_at, updated_at) VALUES (%%s, %%s, %%d, %%d, %%d, %%d)',
                $table
            ),
            $this->storageKey($tokenHash),
            serialize($context),
            0,
            0,
            $expiresAt,
            $now
        ));

        if ($result === false) {
            throw new RuntimeException('Unable to store single-use token.');
        }
    }

    public function wasConsumed(string $tokenHash): bool
    {
        $wpdb = $this->wpdb();
        $table = $this->tableName();
        $now = time();

        $this->deleteExpired($now);

        $row = $wpdb->get_row(
            $wpdb->prepare(
                sprintf('SELECT used FROM %s WHERE token_hash = %%s AND expires_at > %%d LIMIT 1', $table),
                $this->storageKey($tokenHash),
                $now
            ),
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );

        return is_array($row) && (int) ($row['used'] ?? 0) === 1;
    }

    public function installSchema(): void
    {
        $wpdb = $this->wpdb();
        $charsetCollate = method_exists($wpdb, 'get_charset_collate') ? (string) $wpdb->get_charset_collate() : '';
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                token_hash varchar(64) NOT NULL,
                context longtext NOT NULL,
                used tinyint(1) unsigned NOT NULL DEFAULT 0,
                consumed_at bigint unsigned NOT NULL DEFAULT 0,
                expires_at bigint unsigned NOT NULL,
                updated_at bigint unsigned NOT NULL,
                PRIMARY KEY  (token_hash),
                KEY used (used),
                KEY expires_at (expires_at)
            ) %s',
            $this->tableName(),
            $charsetCollate
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new RuntimeException('Unable to install single-use token table.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeContext(string $serialized): array
    {
        if ($serialized === '') {
            return [];
        }

        $value = unserialize($serialized, ['allowed_classes' => false]);
        return is_array($value) ? $value : [];
    }

    private function deleteExpired(int $now): void
    {
        $wpdb = $this->wpdb();
        $wpdb->query($wpdb->prepare(
            sprintf('DELETE FROM %s WHERE expires_at <= %%d', $this->tableName()),
            $now
        ));
    }

    private function storageKey(string $tokenHash): string
    {
        return hash('sha256', $tokenHash);
    }

    private function tableName(): string
    {
        $table = $this->table;
        if (str_contains($table, '.')) {
            throw new RuntimeException('Cross-database single-use token table names are not allowed.');
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            throw new RuntimeException('Invalid single-use token table name.');
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
