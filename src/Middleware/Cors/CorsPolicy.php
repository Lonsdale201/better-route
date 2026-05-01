<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Cors;

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
            'Access-Control-Max-Age' => (string) max(0, $this->maxAgeSeconds),
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
}
