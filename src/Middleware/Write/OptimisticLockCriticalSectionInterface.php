<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\RequestContext;

interface OptimisticLockCriticalSectionInterface
{
    public function execute(RequestContext $context, callable $callback): mixed;
}
