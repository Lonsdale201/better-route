<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Network;

interface ClientIpResolverInterface
{
    public function resolve(mixed $request = null): ?string;
}
