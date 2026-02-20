<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Jwt;

interface JwtVerifierInterface
{
    /**
     * @return array<string, mixed>
     */
    public function verify(string $token): array;
}
