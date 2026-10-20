<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

final class AuthIdentity
{
    /**
     * @param array<string, mixed> $claims
     * @param list<string> $scopes
     */
    public function __construct(
        public readonly string $provider,
        public readonly ?int $userId = null,
        #[\SensitiveParameter] public readonly ?string $subject = null,
        #[\SensitiveParameter] public readonly array $claims = [],
        #[\SensitiveParameter] public readonly array $scopes = [],
        public readonly mixed $user = null
    ) {
    }
}
