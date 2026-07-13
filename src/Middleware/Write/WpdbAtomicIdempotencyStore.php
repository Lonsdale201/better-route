<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use RuntimeException;

final class WpdbAtomicIdempotencyStore implements LeaseAwareAtomicIdempotencyStoreInterface
{
    public function __construct(
        private readonly string $table = 'better_route_atomic_idempotency',
        private readonly ?string $prefix = null
    ) {
        $this->assertWpdb();
    }

    public function reserve(string $key, string $fingerprint, int $ttlSeconds): AtomicIdempotencyRecord
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $record = $this->reserveOnce($key, $fingerprint, $ttlSeconds);
            if ($record !== null) {
                return $record;
            }
        }

        throw new RuntimeException('Unable to read the idempotency reservation after repeated attempts.');
    }

    private function reserveOnce(string $key, string $fingerprint, int $ttlSeconds): ?AtomicIdempotencyRecord
    {
        $wpdb = $this->wpdb();
        $table = $this->tableName();
        $storageKey = $this->storageKey($key);
        $now = time();
        $expiresAt = $now + max(1, $ttlSeconds);
        $reservationToken = $this->reservationToken();

        $wpdb->query($wpdb->prepare(
            sprintf('DELETE FROM %s WHERE idempotency_key = %%s AND expires_at <= %%d', $table),
            $storageKey,
            $now
        ));

        $inserted = $wpdb->query($wpdb->prepare(
            sprintf(
                'INSERT IGNORE INTO %s (idempotency_key, fingerprint, reservation_token, status, response, expires_at, updated_at) VALUES (%%s, %%s, %%s, %%s, %%s, %%d, %%d)',
                $table
            ),
            $storageKey,
            $fingerprint,
            $reservationToken,
            'in_progress',
            '',
            $expiresAt,
            $now
        ));

        if ($inserted === false) {
            throw new RuntimeException('Unable to reserve idempotency record.');
        }

        if ((int) $inserted === 1) {
            return new AtomicIdempotencyRecord(
                AtomicIdempotencyRecord::RESERVED,
                $fingerprint,
                null,
                $reservationToken
            );
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                sprintf('SELECT fingerprint, reservation_token, status, response, expires_at FROM %s WHERE idempotency_key = %%s LIMIT 1', $table),
                $storageKey
            ),
            defined('ARRAY_A') ? ARRAY_A : 'ARRAY_A'
        );

        if (!is_array($row)) {
            return null;
        }

        $storedFingerprint = is_string($row['fingerprint'] ?? null) ? $row['fingerprint'] : '';
        if ($storedFingerprint !== $fingerprint) {
            return new AtomicIdempotencyRecord(AtomicIdempotencyRecord::CONFLICT, $storedFingerprint);
        }

        $status = is_string($row['status'] ?? null) ? $row['status'] : '';
        if ($status === 'complete') {
            return new AtomicIdempotencyRecord(
                AtomicIdempotencyRecord::REPLAY,
                $fingerprint,
                $this->decodeResponse(is_string($row['response'] ?? null) ? $row['response'] : '')
            );
        }

        return new AtomicIdempotencyRecord(AtomicIdempotencyRecord::IN_PROGRESS, $fingerprint);
    }

    public function complete(string $key, string $fingerprint, mixed $response, int $ttlSeconds): void
    {
        throw new RuntimeException('Use completeReservation() with the reservation token.');
    }

    public function completeReservation(
        string $key,
        string $fingerprint,
        string $reservationToken,
        mixed $response,
        int $ttlSeconds
    ): void {
        $wpdb = $this->wpdb();
        $table = $this->tableName();
        $result = $wpdb->query($wpdb->prepare(
            sprintf(
                'UPDATE %s SET status = %%s, response = %%s, expires_at = %%d, updated_at = %%d WHERE idempotency_key = %%s AND fingerprint = %%s AND reservation_token = %%s AND status = %%s',
                $table
            ),
            'complete',
            StoredResponseCodec::encode($response),
            time() + max(1, $ttlSeconds),
            time(),
            $this->storageKey($key),
            $fingerprint,
            $reservationToken,
            'in_progress'
        ));

        if ((int) $result !== 1) {
            throw new RuntimeException('Idempotency reservation is no longer owned by this request.');
        }
    }

    public function release(string $key, string $fingerprint): void
    {
        throw new RuntimeException('Use releaseReservation() with the reservation token.');
    }

    public function releaseReservation(string $key, string $fingerprint, string $reservationToken): void
    {
        $wpdb = $this->wpdb();
        $table = $this->tableName();
        $wpdb->query($wpdb->prepare(
            sprintf('DELETE FROM %s WHERE idempotency_key = %%s AND fingerprint = %%s AND reservation_token = %%s AND status <> %%s', $table),
            $this->storageKey($key),
            $fingerprint,
            $reservationToken,
            'complete'
        ));
    }

    public function installSchema(): void
    {
        $wpdb = $this->wpdb();
        $charsetCollate = method_exists($wpdb, 'get_charset_collate') ? (string) $wpdb->get_charset_collate() : '';
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                idempotency_key varchar(64) NOT NULL,
                fingerprint varchar(64) NOT NULL,
                reservation_token varchar(64) NOT NULL,
                status varchar(20) NOT NULL,
                response longtext NOT NULL,
                expires_at bigint unsigned NOT NULL,
                updated_at bigint unsigned NOT NULL,
                PRIMARY KEY  (idempotency_key),
                KEY fingerprint (fingerprint),
                KEY status (status),
                KEY expires_at (expires_at)
            ) %s',
            $this->tableName(),
            $charsetCollate
        );

        $result = $wpdb->query($sql);
        if ($result === false) {
            throw new RuntimeException('Unable to install atomic idempotency table.');
        }

        $column = $wpdb->get_var(sprintf(
            "SHOW COLUMNS FROM %s LIKE 'reservation_token'",
            $this->tableName()
        ));
        if (!is_string($column) || $column === '') {
            $altered = $wpdb->query(sprintf(
                "ALTER TABLE %s ADD reservation_token varchar(64) NOT NULL DEFAULT '' AFTER fingerprint",
                $this->tableName()
            ));
            if ($altered === false) {
                throw new RuntimeException('Unable to migrate atomic idempotency table.');
            }
        }
    }

    private function decodeResponse(string $serialized): mixed
    {
        if ($serialized === '') {
            return null;
        }

        return StoredResponseCodec::decode($serialized);
    }

    private function reservationToken(): string
    {
        return bin2hex(random_bytes(16));
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
            && method_exists($GLOBALS['wpdb'], 'get_var')
            && method_exists($GLOBALS['wpdb'], 'query')
        ) {
            return $GLOBALS['wpdb'];
        }

        throw new RuntimeException('Global $wpdb is not available.');
    }
}
