<?php

declare(strict_types=1);

namespace BetterRoute\Middleware;

use BetterRoute\Http\RequestContext;

interface MiddlewareInterface
{
    public function handle(RequestContext $context, callable $next): mixed;
}
