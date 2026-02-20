<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\RequestContext;

final class CallbackOptimisticLockVersionResolver implements OptimisticLockVersionResolverInterface
{
    /** @var callable(RequestContext): (string|int|null) */
    private $resolver;

    /**
     * @param callable(RequestContext): (string|int|null) $resolver
     */
    public function __construct(callable $resolver)
    {
        $this->resolver = $resolver;
    }

    public function resolve(RequestContext $context): string|int|null
    {
        return ($this->resolver)($context);
    }
}
