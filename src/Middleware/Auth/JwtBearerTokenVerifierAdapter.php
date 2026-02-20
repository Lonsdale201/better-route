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

    public function verify(string $token): array
    {
        return $this->verifier->verify($token);
    }
}
