<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\Write\OptimisticLockCriticalSectionInterface;

final class PassthroughOptimisticLockCriticalSection implements OptimisticLockCriticalSectionInterface
{
    public function execute(RequestContext $context, callable $callback): mixed
    {
        return $callback();
    }
}
