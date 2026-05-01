<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Network;

use InvalidArgumentException;

final class CidrMatcher
{
    public static function matches(string $ip, string $cidr): bool
    {
        $parsed = self::parseCidr($cidr);
        $ipBytes = @inet_pton($ip);
        if ($ipBytes === false || strlen($ipBytes) !== strlen($parsed['networkBytes'])) {
            return false;
        }

        $prefix = $parsed['prefix'];
        $fullBytes = intdiv($prefix, 8);
        $remainingBits = $prefix % 8;

        if ($fullBytes > 0 && substr($ipBytes, 0, $fullBytes) !== substr($parsed['networkBytes'], 0, $fullBytes)) {
            return false;
        }

        if ($remainingBits === 0) {
            return true;
        }

        $mask = (0xff << (8 - $remainingBits)) & 0xff;

        return (ord($ipBytes[$fullBytes]) & $mask) === (ord($parsed['networkBytes'][$fullBytes]) & $mask);
    }

    public static function assertValid(string $cidr): void
    {
        self::parseCidr($cidr);
    }

    /**
     * @return array{networkBytes: string, prefix: int}
     */
    private static function parseCidr(string $cidr): array
    {
        $cidr = trim($cidr);
        if ($cidr === '') {
            throw new InvalidArgumentException('CIDR must not be empty.');
        }

        $parts = explode('/', $cidr, 2);
        $ip = $parts[0];
        $bytes = @inet_pton($ip);
        if ($bytes === false) {
            throw new InvalidArgumentException(sprintf('Invalid CIDR IP "%s".', $cidr));
        }

        $maxPrefix = strlen($bytes) === 4 ? 32 : 128;
        if (!isset($parts[1]) || $parts[1] === '') {
            $prefix = $maxPrefix;
        } elseif (preg_match('/^\d+$/', $parts[1]) === 1) {
            $prefix = (int) $parts[1];
        } else {
            throw new InvalidArgumentException(sprintf('Invalid CIDR prefix "%s".', $cidr));
        }

        if ($prefix < 0 || $prefix > $maxPrefix) {
            throw new InvalidArgumentException(sprintf('CIDR prefix out of range "%s".', $cidr));
        }

        return [
            'networkBytes' => $bytes,
            'prefix' => $prefix,
        ];
    }
}
