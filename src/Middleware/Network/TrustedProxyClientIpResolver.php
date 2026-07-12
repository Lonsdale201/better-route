<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Network;

final class TrustedProxyClientIpResolver implements ClientIpResolverInterface
{
    /** @var callable(): array<string, mixed> */
    private $serverResolver;

    /**
     * @param list<string> $trustedProxyCidrs
     * @param list<string> $forwardedHeaders
     * @param null|callable(): array<string, mixed> $serverResolver
     */
    public function __construct(
        private readonly array $trustedProxyCidrs = [],
        private readonly array $forwardedHeaders = ['CF-Connecting-IP', 'X-Forwarded-For'],
        ?callable $serverResolver = null
    ) {
        foreach ($trustedProxyCidrs as $cidr) {
            CidrMatcher::assertValid($cidr);
        }

        $this->serverResolver = $serverResolver ?? static fn (): array => $_SERVER;
    }

    public function resolve(mixed $request = null): ?string
    {
        $server = ($this->serverResolver)();
        $remoteAddr = $this->stringOrNull($server['REMOTE_ADDR'] ?? null);
        if ($remoteAddr === null || filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        if (!$this->isTrustedProxy($remoteAddr)) {
            return $remoteAddr;
        }

        foreach ($this->forwardedHeaders as $header) {
            $value = $this->headerValue($request, $server, $header);
            if ($value === null) {
                continue;
            }

            $candidate = $this->clientIpFromHeader($value);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return $remoteAddr;
    }

    public static function serverHeaderName(string $header): string
    {
        $header = str_replace('-', '_', strtoupper($header));
        if (str_starts_with($header, 'HTTP_')) {
            return $header;
        }

        return 'HTTP_' . $header;
    }

    public static function headerNameFromServer(string $serverHeader): string
    {
        $serverHeader = strtoupper($serverHeader);
        if (str_starts_with($serverHeader, 'HTTP_')) {
            $serverHeader = substr($serverHeader, 5);
        }

        return str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $serverHeader))));
    }

    private function isTrustedProxy(string $remoteAddr): bool
    {
        foreach ($this->trustedProxyCidrs as $cidr) {
            if (CidrMatcher::matches($remoteAddr, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $server
     */
    private function headerValue(mixed $request, array $server, string $header): ?string
    {
        $headerNames = array_values(array_unique([
            $header,
            self::headerNameFromServer($header),
        ]));

        if (is_object($request) && method_exists($request, 'get_header')) {
            foreach ($headerNames as $headerName) {
                $value = $request->get_header($headerName);
                $value = $this->stringOrNull($value);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        foreach ([$header, self::serverHeaderName($header)] as $serverName) {
            $value = $this->stringOrNull($server[$serverName] ?? null);
            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    /**
     * Resolve the client IP from a forwarded header by walking the hop list
     * from right to left and returning the first address that is NOT one of
     * our own trusted proxies. Appending proxies (e.g. nginx
     * proxy_add_x_forwarded_for) place the real peer at the right, while any
     * client-supplied, spoofable entries sit to the left — so the leftmost
     * value must never be trusted. When every hop is a trusted proxy there is
     * no untrusted client address to report, so we fall back to REMOTE_ADDR.
     */
    private function clientIpFromHeader(string $value): ?string
    {
        $parts = array_reverse(explode(',', $value));
        foreach ($parts as $part) {
            $candidate = trim($part);
            if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                continue;
            }

            if (!$this->isTrustedProxy($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
