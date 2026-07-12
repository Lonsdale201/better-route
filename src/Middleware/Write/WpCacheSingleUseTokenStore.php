<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use RuntimeException;

final class WpCacheSingleUseTokenStore implements SingleUseTokenStoreInterface
{
    public function __construct(
        private readonly string $group = 'better_route_single_use',
        private readonly int $lockTtlSeconds = 10
    ) {
        foreach (['wp_cache_add', 'wp_cache_delete', 'get_transient', 'set_transient', 'delete_transient'] as $function) {
            if (!function_exists($function)) {
                throw new RuntimeException(sprintf('%s is unavailable.', $function));
            }
        }

        // The wp_cache_add() lock only provides cross-request mutual exclusion
        // when a persistent object cache is installed. On the default in-process
        // cache two concurrent requests could each acquire the lock and consume
        // the same token twice, so refuse to run without persistence — use
        // WpdbSingleUseTokenStore (atomic UPDATE) on stores without Redis/Memcached.
        if (function_exists('wp_using_ext_object_cache') && !wp_using_ext_object_cache()) {
            throw new RuntimeException(
                'WpCacheSingleUseTokenStore requires a persistent object cache. '
                . 'Use WpdbSingleUseTokenStore when no persistent object cache is available.'
            );
        }
    }

    public function consume(string $tokenHash): ?array
    {
        $lockKey = $this->lockKey($tokenHash);
        if (!wp_cache_add($lockKey, 1, $this->group, max(1, $this->lockTtlSeconds))) {
            return null;
        }

        try {
            $record = get_transient($this->recordKey($tokenHash));
            if (!is_array($record)) {
                return null;
            }

            $expiresAt = isset($record['expires_at']) && is_numeric($record['expires_at']) ? (int) $record['expires_at'] : 0;
            if ($expiresAt <= time()) {
                delete_transient($this->recordKey($tokenHash));
                return null;
            }

            delete_transient($this->recordKey($tokenHash));
            set_transient($this->consumedKey($tokenHash), 1, max(1, $expiresAt - time()));

            $context = $record['context'] ?? [];
            return is_array($context) ? $context : [];
        } finally {
            wp_cache_delete($lockKey, $this->group);
        }
    }

    public function store(string $tokenHash, array $context, int $ttlSeconds): void
    {
        $ttlSeconds = max(1, $ttlSeconds);
        set_transient($this->recordKey($tokenHash), [
            'context' => $context,
            'expires_at' => time() + $ttlSeconds,
        ], $ttlSeconds);
        delete_transient($this->consumedKey($tokenHash));
    }

    public function wasConsumed(string $tokenHash): bool
    {
        return get_transient($this->consumedKey($tokenHash)) !== false;
    }

    private function recordKey(string $tokenHash): string
    {
        return 'better_route_sut_' . sha1($tokenHash);
    }

    private function consumedKey(string $tokenHash): string
    {
        return 'better_route_sut_used_' . sha1($tokenHash);
    }

    private function lockKey(string $tokenHash): string
    {
        return 'better_route_sut_lock_' . sha1($tokenHash);
    }
}
