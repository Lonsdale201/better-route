<?php

declare(strict_types=1);

namespace BetterRoute\Http;

use BetterRoute\Middleware\Network\TrustedProxyClientIpResolver;

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
        $resolver = new TrustedProxyClientIpResolver(
            trustedProxyCidrs: $this->trustedProxies,
            forwardedHeaders: $this->trustedHeaders(),
            serverResolver: static fn (): array => $server
        );

        return $resolver->resolve();
    }

    /**
     * @return list<string>
     */
    private function trustedHeaders(): array
    {
        return array_values(array_map(
            static fn (string $header): string => TrustedProxyClientIpResolver::headerNameFromServer($header),
            $this->trustedHeaders
        ));
    }
}
