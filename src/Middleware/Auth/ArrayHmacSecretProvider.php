<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

final class ArrayHmacSecretProvider implements HmacSecretProviderInterface
{
    /**
     * @param array<string, string> $secrets
     */
    public function __construct(#[\SensitiveParameter] private readonly array $secrets)
    {
    }

    #[\Override]
    public function secretFor(string $keyId): ?string
    {
        $secret = $this->secrets[$keyId] ?? null;
        return is_string($secret) && $secret !== '' ? $secret : null;
    }
}
