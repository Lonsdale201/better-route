<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

interface BearerTokenVerifierInterface
{
    /**
     * @return array<string, mixed>
     */
    public function verify(string $token): array;
}
