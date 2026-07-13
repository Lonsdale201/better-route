<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Cors;

use InvalidArgumentException;

final class CorsPolicy
{
    /**
     * @param list<string> $allowedOrigins
     * @param list<string> $allowedMethods
     * @param list<string> $allowedHeaders
     * @param list<string> $exposedHeaders
     */
    public function __construct(
        private readonly array $allowedOrigins,
        private readonly array $allowedMethods = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        private readonly array $allowedHeaders = [
            'Authorization',
            'Content-Type',
            'Idempotency-Key',
            'If-Match',
            'If-None-Match',
            'X-Request-ID',
            'X-WP-Nonce',
        ],
        private readonly array $exposedHeaders = [
            'ETag',
            'Idempotency-Replayed',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
            'X-Request-ID',
        ],
        private readonly bool $allowCredentials = false,
        private readonly int $maxAgeSeconds = 600
    ) {
        // A wildcard origin with credentials would reflect ANY origin back with
        // Access-Control-Allow-Credentials: true, defeating the same-origin
        // policy for authenticated endpoints. Refuse the combination outright.
        if ($allowCredentials && in_array('*', $allowedOrigins, true)) {
            throw new InvalidArgumentException(
                'CORS wildcard origin ("*") cannot be combined with credentials. '
                . 'List explicit allowed origins when allowCredentials is enabled.'
            );
        }

        if ($maxAgeSeconds < 0) {
            throw new InvalidArgumentException('CORS maxAgeSeconds must be zero or greater.');
        }

        foreach ($allowedOrigins as $origin) {
            $this->assertValidOrigin($origin);
        }

        foreach ($allowedMethods as $method) {
            $this->assertToken($method, 'method');
        }

        foreach (array_merge($allowedHeaders, $exposedHeaders) as $header) {
            $this->assertToken($header, 'header name');
        }
    }

    /**
     * @return array<string, string>
     */
    public function headersFor(?string $origin): array
    {
        $allowOrigin = $this->allowedOriginFor($origin);
        if ($allowOrigin === null) {
            return [];
        }

        $headers = [
            'Access-Control-Allow-Origin' => $allowOrigin,
            'Access-Control-Allow-Methods' => implode(', ', $this->normalizeTokens($this->allowedMethods)),
            'Access-Control-Allow-Headers' => implode(', ', $this->normalizeTokens($this->allowedHeaders)),
            'Access-Control-Expose-Headers' => implode(', ', $this->normalizeTokens($this->exposedHeaders)),
            'Access-Control-Max-Age' => (string) $this->maxAgeSeconds,
            'Vary' => 'Origin',
        ];

        if ($this->allowCredentials) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }

    private function allowedOriginFor(?string $origin): ?string
    {
        $origins = array_values(array_filter(
            array_map(static fn (string $value): string => trim($value), $this->allowedOrigins),
            static fn (string $value): bool => $value !== ''
        ));

        if ($origin === null || $origin === '') {
            return in_array('*', $origins, true) && !$this->allowCredentials ? '*' : null;
        }

        if (in_array('*', $origins, true)) {
            return $this->allowCredentials ? $origin : '*';
        }

        return in_array($origin, $origins, true) ? $origin : null;
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function normalizeTokens(array $tokens): array
    {
        $result = [];
        foreach ($tokens as $token) {
            $token = trim($token);
            if ($token !== '') {
                $result[] = $token;
            }
        }

        return array_values(array_unique($result));
    }

    private function assertValidOrigin(string $origin): void
    {
        $origin = trim($origin);
        if ($origin === '*' || $origin === 'null') {
            return;
        }

        if ($origin === '' || preg_match('/[\r\n]/', $origin) === 1) {
            throw new InvalidArgumentException('CORS origins must be valid serialized origins.');
        }

        $parts = parse_url($origin);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '' && $parts['path'] !== '/')
        ) {
            throw new InvalidArgumentException(sprintf('Invalid CORS origin: %s', $origin));
        }
    }

    private function assertToken(string $value, string $type): void
    {
        $value = trim($value);
        if ($value === '' || preg_match("/^[!#$%&'*+.^_`|~0-9A-Za-z-]+$/D", $value) !== 1) {
            throw new InvalidArgumentException(sprintf('Invalid CORS %s: %s', $type, $value));
        }
    }
}
