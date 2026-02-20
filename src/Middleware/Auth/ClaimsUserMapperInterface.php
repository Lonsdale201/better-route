<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

use BetterRoute\Http\RequestContext;

interface ClaimsUserMapperInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function mapUserId(array $claims, RequestContext $context): ?int;
}
