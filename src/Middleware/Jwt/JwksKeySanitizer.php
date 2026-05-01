<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Jwt;

/**
 * @internal
 */
final class JwksKeySanitizer
{
    /** @var array<string, true> */
    private const PUBLIC_FIELDS = [
        'alg' => true,
        'crv' => true,
        'e' => true,
        'kid' => true,
        'kty' => true,
        'n' => true,
        'use' => true,
        'x' => true,
        'y' => true,
    ];

    /**
     * @param list<mixed> $keys
     * @return list<array<string, string>>
     */
    public static function sanitizeKeys(array $keys): array
    {
        $result = [];

        foreach ($keys as $key) {
            $sanitized = self::sanitizeKey($key);
            if ($sanitized !== null) {
                $result[] = $sanitized;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>|null
     */
    public static function sanitizeKey(mixed $key): ?array
    {
        if (!is_array($key)) {
            return null;
        }

        $sanitized = [];
        foreach ($key as $field => $value) {
            if (!is_string($field) || !isset(self::PUBLIC_FIELDS[$field]) || !is_string($value) || $value === '') {
                continue;
            }

            $sanitized[$field] = $value;
        }

        if (!isset($sanitized['kid'], $sanitized['kty'])) {
            return null;
        }

        if ($sanitized['kty'] === 'RSA' && isset($sanitized['n'], $sanitized['e'])) {
            return $sanitized;
        }

        if ($sanitized['kty'] === 'EC' && isset($sanitized['crv'], $sanitized['x'], $sanitized['y'])) {
            return $sanitized;
        }

        return null;
    }
}
