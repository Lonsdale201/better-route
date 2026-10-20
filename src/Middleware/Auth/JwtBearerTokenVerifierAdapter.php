<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

use BetterRoute\Middleware\Jwt\JwtVerifierInterface;

final class JwtBearerTokenVerifierAdapter implements BearerTokenVerifierInterface
{
    public function __construct(
        private readonly JwtVerifierInterface $verifier
    ) {
    }

    #[\Override]
    public function verify(#[\SensitiveParameter] string $token): array
    {
        return $this->verifier->verify($token);
    }
}
