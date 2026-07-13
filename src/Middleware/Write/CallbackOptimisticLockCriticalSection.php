<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\RequestContext;

final class CallbackOptimisticLockCriticalSection implements OptimisticLockCriticalSectionInterface
{
    /** @var callable(RequestContext, callable): mixed */
    private $executor;

    /** @param callable(RequestContext, callable): mixed $executor */
    public function __construct(callable $executor)
    {
        $this->executor = $executor;
    }

    public function execute(RequestContext $context, callable $callback): mixed
    {
        return ($this->executor)($context, $callback);
    }
}
