<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

final class ArrayHmacSecretProvider implements HmacSecretProviderInterface
{
    /**
     * @param array<string, string> $secrets
     */
    public function __construct(private readonly array $secrets)
    {
    }

    public function secretFor(string $keyId): ?string
    {
        $secret = $this->secrets[$keyId] ?? null;
        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
