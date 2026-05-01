<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Jwt;

interface JwksProviderInterface
{
    /**
     * @return list<array<string, string>>
     */
    public function keys(): array;

    public function refresh(): void;
}
