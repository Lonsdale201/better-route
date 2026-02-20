<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\RateLimit;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;

final class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var callable(RequestContext): string */
    private $keyResolver;

    /**
     * @param null|callable(RequestContext): string $keyResolver
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60,
        ?callable $keyResolver = null
    ) {
        $this->keyResolver = $keyResolver ?? static fn (RequestContext $context): string => $context->routePath;
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $key = ($this->keyResolver)($context);
        $result = $this->limiter->hit($key, $this->limit, $this->windowSeconds);

        if (!$result->allowed) {
            throw new ApiException(
                message: 'Rate limit exceeded.',
                status: 429,
                errorCode: 'rate_limited',
                details: [
                    'limit' => $this->limit,
                    'remaining' => $result->remaining,
                    'resetAt' => $result->resetAt,
                ]
            );
        }

        $response = $next($context->withAttribute('rateLimit', $result));

        if ($response instanceof Response) {
            $headers = array_merge($response->headers, [
                'X-RateLimit-Limit' => (string) $this->limit,
                'X-RateLimit-Remaining' => (string) $result->remaining,
                'X-RateLimit-Reset' => (string) $result->resetAt,
            ]);

            return new Response($response->body, $response->status, $headers);
        }

        return $response;
    }
}
