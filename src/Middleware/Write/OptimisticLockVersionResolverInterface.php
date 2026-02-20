<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\RequestContext;

interface OptimisticLockVersionResolverInterface
{
    public function resolve(RequestContext $context): string|int|null;
}
