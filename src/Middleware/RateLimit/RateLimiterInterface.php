<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\RateLimit;

interface RateLimiterInterface
{
    public function hit(string $key, int $limit, int $windowSeconds): RateLimitResult;
}
