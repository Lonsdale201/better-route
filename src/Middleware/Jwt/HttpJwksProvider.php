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

    /**
     * @param null|callable(string): string $httpGet
     * @param null|callable(string): mixed $getTransient
     * @param null|callable(string, mixed, int): bool $setTransient
     * @param null|callable(string): bool $deleteTransient
     */
    public function __construct(
        private readonly string $jwksUri,
        private readonly int $ttlSeconds = 3600,
        ?string $cacheKey = null,
        private readonly ?string $issuer = null,
        ?callable $httpGet = null,
        ?callable $getTransient = null,
        ?callable $setTransient = null,
        ?callable $deleteTransient = null
    ) {
        if ((string) parse_url($jwksUri, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('JWKS URI must use https.');
        }
        if ($ttlSeconds < 1) {
            throw new RuntimeException('JWKS cache TTL must be positive.');
        }

        $this->cacheKey = $cacheKey ?? ('better_route_jwks_' . sha1($jwksUri));
        $this->httpGet = $httpGet ?? $this->defaultHttpGet();
        $this->getTransient = $getTransient ?? $this->defaultGetTransient();
        $this->setTransient = $setTransient ?? $this->defaultSetTransient();
        $this->deleteTransient = $deleteTransient ?? $this->defaultDeleteTransient();

        $this->registerRefreshAction();
    }

    private readonly string $cacheKey;

    public function keys(): array
    {
        if ($this->memoryKeys !== null) {
            return $this->memoryKeys;
        }

        $cached = ($this->getTransient)($this->cacheKey);
        if (is_array($cached)) {
            $keys = JwksKeySanitizer::sanitizeKeys(array_values($cached));
            if ($keys !== []) {
                $this->memoryKeys = $keys;
                return $keys;
            }
        }

        $this->memoryKeys = $this->fetchKeys();
        ($this->setTransient)($this->cacheKey, $this->memoryKeys, $this->ttlSeconds);

        return $this->memoryKeys;
    }

    public function refresh(): void
    {
        $this->clearCache();
        $this->memoryKeys = $this->fetchKeys();
        ($this->setTransient)($this->cacheKey, $this->memoryKeys, $this->ttlSeconds);
    }

    public function clearCache(): void
    {
        $this->memoryKeys = null;
        ($this->deleteTransient)($this->cacheKey);
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

    /**
     * @return callable(string): string
     */
    private function defaultHttpGet(): callable
    {
        return static function (string $uri): string {
            if (!function_exists('wp_remote_retrieve_response_code') || !function_exists('wp_remote_retrieve_body')) {
                throw new RuntimeException('WordPress HTTP API is unavailable.');
            }

            // Prefer wp_safe_remote_get(): it applies WordPress's SSRF guard
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

            if (function_exists('wp_safe_remote_get')) {
                $response = wp_safe_remote_get($uri, $args);
            } elseif (function_exists('wp_remote_get')) {
                $response = wp_remote_get($uri, $args);
            } else {
                throw new RuntimeException('WordPress HTTP API is unavailable.');
            }

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
}
