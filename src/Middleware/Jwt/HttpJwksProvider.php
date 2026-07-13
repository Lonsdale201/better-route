<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Jwt;

use RuntimeException;

final class HttpJwksProvider implements JwksProviderInterface
{
    /** @var null|list<array<string, string>> */
    private ?array $memoryKeys = null;

    /** @var callable(string): string */
    private $httpGet;

    /** @var callable(string): mixed */
    private $getTransient;

    /** @var callable(string, mixed, int): bool */
    private $setTransient;

    /** @var callable(string): bool */
    private $deleteTransient;

    /** @var callable(): int */
    private $now;

    private ?int $lastRefreshAt = null;

    /**
     * @param null|callable(string): string $httpGet
     * @param null|callable(string): mixed $getTransient
     * @param null|callable(string, mixed, int): bool $setTransient
     * @param null|callable(string): bool $deleteTransient
     * @param null|callable(): int $now
     */
    public function __construct(
        private readonly string $jwksUri,
        private readonly int $ttlSeconds = 3600,
        ?string $cacheKey = null,
        private readonly ?string $issuer = null,
        ?callable $httpGet = null,
        ?callable $getTransient = null,
        ?callable $setTransient = null,
        ?callable $deleteTransient = null,
        private readonly int $minimumRefreshIntervalSeconds = 30,
        ?callable $now = null
    ) {
        $parts = parse_url($jwksUri);
        if (!is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || !isset($parts['host'])
            || isset($parts['user'], $parts['pass'])
        ) {
            throw new RuntimeException('JWKS URI must use https.');
        }
        if ($ttlSeconds < 1) {
            throw new RuntimeException('JWKS cache TTL must be positive.');
        }
        if ($minimumRefreshIntervalSeconds < 0) {
            throw new RuntimeException('JWKS minimum refresh interval must not be negative.');
        }

        $this->cacheKey = $cacheKey ?? ('better_route_jwks_' . sha1($jwksUri));
        $this->httpGet = $httpGet ?? $this->defaultHttpGet();
        $this->getTransient = $getTransient ?? $this->defaultGetTransient();
        $this->setTransient = $setTransient ?? $this->defaultSetTransient();
        $this->deleteTransient = $deleteTransient ?? $this->defaultDeleteTransient();
        $this->now = $now ?? static fn (): int => time();

        $this->registerRefreshAction();
    }

    private readonly string $cacheKey;

    public function keys(): array
    {
        if ($this->memoryKeys !== null) {
            return $this->memoryKeys;
        }

        $cachedKeys = $this->cachedKeys();
        if ($cachedKeys !== null) {
            $this->memoryKeys = $cachedKeys;
            return $cachedKeys;
        }

        $this->withRefreshLock(function (): void {
            $cachedKeys = $this->cachedKeys();
            if ($cachedKeys !== null) {
                $this->memoryKeys = $cachedKeys;
                return;
            }

            $this->memoryKeys = $this->fetchKeys();
            ($this->setTransient)($this->cacheKey, $this->memoryKeys, $this->ttlSeconds);
            $this->markRefreshed();
        });

        if ($this->memoryKeys === null) {
            // A lock holder may have completed just as our bounded wait ended.
            $this->memoryKeys = $this->cachedKeys();
        }
        if ($this->memoryKeys === null) {
            throw new RuntimeException('Unable to acquire JWKS refresh lock.');
        }

        return $this->memoryKeys;
    }

    public function refresh(): void
    {
        if (!$this->mayRefresh()) {
            return;
        }

        $this->withRefreshLock(function (): void {
            // Another request may have refreshed while this request waited for
            // the database lock. Re-check the persistent cooldown inside it.
            if (!$this->mayRefresh()) {
                return;
            }

            // Mark before I/O to throttle failures as well as successful fetches.
            // Keep the last known-good keys intact if the fetch fails.
            $this->markRefreshed();
            $freshKeys = $this->fetchKeys();
            $this->memoryKeys = $freshKeys;
            ($this->setTransient)($this->cacheKey, $freshKeys, $this->ttlSeconds);
        });
    }

    public function clearCache(): void
    {
        $this->memoryKeys = null;
        ($this->deleteTransient)($this->cacheKey);
        $this->lastRefreshAt = null;
        ($this->deleteTransient)($this->refreshKey());
    }

    /**
     * @return list<array<string, string>>
     */
    private function fetchKeys(): array
    {
        $body = ($this->httpGet)($this->jwksUri);
        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_array($decoded['keys'] ?? null)) {
            throw new RuntimeException('Invalid JWKS response.');
        }

        $keys = JwksKeySanitizer::sanitizeKeys(array_values($decoded['keys']));
        if ($keys === []) {
            throw new RuntimeException('JWKS response contains no usable signing keys.');
        }

        return $keys;
    }

    /** @return null|list<array<string, string>> */
    private function cachedKeys(): ?array
    {
        $cached = ($this->getTransient)($this->cacheKey);
        if (!is_array($cached)) {
            return null;
        }

        $keys = JwksKeySanitizer::sanitizeKeys(array_values($cached));
        return $keys !== [] ? $keys : null;
    }

    /**
     * @return callable(string): string
     */
    private function defaultHttpGet(): callable
    {
        return static function (string $uri): string {
            if (!function_exists('wp_safe_remote_get')
                || !function_exists('wp_remote_retrieve_response_code')
                || !function_exists('wp_remote_retrieve_body')
            ) {
                throw new RuntimeException('WordPress HTTP API is unavailable.');
            }

            // Require wp_safe_remote_get(): it applies WordPress's SSRF guard
            // (blocks internal/loopback hosts). Bound redirects and response
            // size so a hostile or misbehaving issuer cannot pivot internally
            // or exhaust memory. JWKS documents are small and public.
            $args = [
                'headers' => ['Accept' => 'application/json'],
                'sslverify' => true,
                'timeout' => 10,
                'redirection' => 1,
                'limit_response_size' => 256 * 1024,
            ];

            $response = wp_safe_remote_get($uri, $args);

            if (function_exists('is_wp_error') && is_wp_error($response)) {
                throw new RuntimeException('Unable to fetch JWKS.');
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            if ($status !== 200) {
                throw new RuntimeException('JWKS endpoint returned a non-200 response.');
            }

            $body = wp_remote_retrieve_body($response);
            if (!is_string($body) || $body === '') {
                throw new RuntimeException('JWKS endpoint returned an empty body.');
            }

            return $body;
        };
    }

    /**
     * @return callable(string): mixed
     */
    private function defaultGetTransient(): callable
    {
        return static fn (string $key): mixed => function_exists('get_transient') ? get_transient($key) : null;
    }

    /**
     * @return callable(string, mixed, int): bool
     */
    private function defaultSetTransient(): callable
    {
        return static fn (string $key, mixed $value, int $ttl): bool => function_exists('set_transient')
            ? (bool) set_transient($key, $value, $ttl)
            : true;
    }

    /**
     * @return callable(string): bool
     */
    private function defaultDeleteTransient(): callable
    {
        return static fn (string $key): bool => function_exists('delete_transient')
            ? (bool) delete_transient($key)
            : true;
    }

    private function registerRefreshAction(): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('better_route/jwks_refresh', function (mixed $issuer = null): void {
            $issuer = is_string($issuer) && $issuer !== '' ? $issuer : null;
            if ($this->issuer === null || $issuer === null || $issuer === $this->issuer) {
                $this->clearCache();
            }
        }, 10, 1);
    }

    private function mayRefresh(): bool
    {
        $now = ($this->now)();
        if ($this->lastRefreshAt !== null
            && $now - $this->lastRefreshAt < $this->minimumRefreshIntervalSeconds
        ) {
            return false;
        }

        $persisted = ($this->getTransient)($this->refreshKey());
        return !is_numeric($persisted)
            || $now - (int) $persisted >= $this->minimumRefreshIntervalSeconds;
    }

    private function markRefreshed(): void
    {
        $this->lastRefreshAt = ($this->now)();
        ($this->setTransient)(
            $this->refreshKey(),
            $this->lastRefreshAt,
            max(1, $this->minimumRefreshIntervalSeconds)
        );
    }

    private function refreshKey(): string
    {
        return $this->cacheKey . '_refresh_lock';
    }

    private function withRefreshLock(callable $callback): void
    {
        if (!isset($GLOBALS['wpdb'])
            || !is_object($GLOBALS['wpdb'])
            || !method_exists($GLOBALS['wpdb'], 'prepare')
            || !method_exists($GLOBALS['wpdb'], 'get_var')
        ) {
            $callback();
            return;
        }

        $wpdb = $GLOBALS['wpdb'];
        $lockName = 'better_route_jwks_' . sha1($this->cacheKey);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 2));
        if ((int) $acquired !== 1) {
            return;
        }

        try {
            $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }
}
