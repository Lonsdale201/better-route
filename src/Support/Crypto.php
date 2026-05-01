<?php

declare(strict_types=1);

namespace BetterRoute\Support;

use RuntimeException;

final class Crypto
{
    public static function equals(string $known, string $user): bool
    {
        return hash_equals($known, $user);
    }

    public static function token(int $bytes = 32, string|CryptoEncoding $encoding = CryptoEncoding::Base64Url): string
    {
        if ($bytes < 1) {
            throw new RuntimeException('Token byte length must be positive.');
        }

        return self::encode(random_bytes($bytes), $encoding);
    }

    public static function tokenHex(int $bytes = 32): string
    {
        if ($bytes < 1) {
            throw new RuntimeException('Token byte length must be positive.');
        }

        return bin2hex(random_bytes($bytes));
    }

    public static function encode(string $raw, string|CryptoEncoding $encoding): string
    {
        return match (self::normalizeEncoding($encoding)) {
            CryptoEncoding::Hex => bin2hex($raw),
            CryptoEncoding::Base64 => base64_encode($raw),
            CryptoEncoding::Base64Url => self::base64UrlEncode($raw),
        };
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $encoded): string
    {
        if (preg_match('/^[A-Za-z0-9_-]*={0,2}$/', $encoded) !== 1) {
            throw new RuntimeException('Invalid base64url payload.');
        }

        $unpadded = rtrim($encoded, '=');
        if (str_contains($unpadded, '=')) {
            throw new RuntimeException('Invalid base64url padding.');
        }

        if (strlen($unpadded) % 4 === 1) {
            throw new RuntimeException('Invalid base64url length.');
        }

        $normalized = strtr($unpadded, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid base64url payload.');
        }

        return $decoded;
    }

    private static function normalizeEncoding(string|CryptoEncoding $encoding): CryptoEncoding
    {
        if ($encoding instanceof CryptoEncoding) {
            return $encoding;
        }

        return CryptoEncoding::tryFrom(strtolower($encoding))
            ?? throw new RuntimeException('Unsupported token encoding.');
    }
}
