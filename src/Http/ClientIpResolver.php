<?php

declare(strict_types=1);

namespace BetterRoute\Http;

final class ClientIpResolver
{
    /**
     * @param list<string> $trustedProxies
     * @param list<string> $trustedHeaders
     */
    public function __construct(
        private readonly array $trustedProxies = [],
        private readonly array $trustedHeaders = ['HTTP_X_FORWARDED_FOR', 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP']
    ) {
    }

    /**
     * @param array<string, mixed>|null $server
     */
    public function resolve(?array $server = null): ?string
    {
        $server ??= $_SERVER;
        $remoteAddr = $this->stringOrNull($server['REMOTE_ADDR'] ?? null);
        if ($remoteAddr === null) {
            return null;
        }

        if (!$this->isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        foreach ($this->trustedHeaders as $header) {
            $value = $this->stringOrNull($server[$header] ?? null);
            if ($value === null) {
                continue;
            }

            $candidate = $this->firstIpFromHeader($value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $remoteAddr;
    }

    private function isTrustedProxy(string $remoteAddr): bool
    {
        return in_array($remoteAddr, $this->trustedProxies, true);
    }

    private function firstIpFromHeader(string $value): ?string
    {
        foreach (explode(',', $value) as $part) {
            $candidate = trim($part);
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
