<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

interface HmacSecretProviderInterface
{
    public function secretFor(string $keyId): ?string;
}
