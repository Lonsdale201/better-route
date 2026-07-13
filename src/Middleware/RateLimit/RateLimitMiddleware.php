<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\RateLimit;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\ClientIpResolver;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;
use BetterRoute\Middleware\Network\ClientIpResolverInterface;
use BetterRoute\Support\Canonicalizer;
use BetterRoute\Support\RequestIdentity;
use InvalidArgumentException;

final class RateLimitMiddleware implements MiddlewareInterface
{
    /** @var callable(RequestContext): string */
    private $keyResolver;

    /** @var callable(): int */
    private $now;

    /**
     * @param null|callable(RequestContext): string $keyResolver
     */
    public function __construct(
        private readonly RateLimiterInterface $limiter,
        private readonly int $limit = 60,
        private readonly int $windowSeconds = 60,
        ?callable $keyResolver = null,
        private readonly ClientIpResolver|ClientIpResolverInterface|null $clientIpResolver = null,
        ?callable $now = null
    ) {
        if ($limit < 1) {
            throw new InvalidArgumentException('Rate limit must be greater than 0.');
        }
        if ($windowSeconds < 1) {
            throw new InvalidArgumentException('Rate-limit window must be greater than 0.');
        }

        $this->keyResolver = $keyResolver ?? fn (RequestContext $context): string => Canonicalizer::json([
            'route' => $context->routePath,
            'identity' => $this->identityKey($context),
        ]);
        $this->now = $now ?? static fn (): int => time();
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $key = ($this->keyResolver)($context);
        $result = $this->limiter->hit($key, $this->limit, $this->windowSeconds);

        if (!$result->allowed) {
            $retryAfter = max(1, $result->resetAt - ($this->now)());
            throw new ApiException(
                message: 'Rate limit exceeded.',
                status: 429,
                errorCode: 'rate_limited',
                details: [
                    'limit' => $this->limit,
                    'remaining' => $result->remaining,
                    'resetAt' => $result->resetAt,
                ],
                headers: [
                    'Retry-After' => (string) $retryAfter,
                    'X-RateLimit-Limit' => (string) $this->limit,
                    'X-RateLimit-Remaining' => (string) $result->remaining,
                    'X-RateLimit-Reset' => (string) $result->resetAt,
                ]
            );
        }

        $response = $next($context->withAttribute('rateLimit', $result));

        $headers = [
            'X-RateLimit-Limit' => (string) $this->limit,
            'X-RateLimit-Remaining' => (string) $result->remaining,
            'X-RateLimit-Reset' => (string) $result->resetAt,
        ];

        if ($response instanceof Response) {
            return new Response($response->body, $response->status, array_merge($response->headers, $headers));
        }

        if (is_object($response) && method_exists($response, 'header')) {
            foreach ($headers as $name => $value) {
                $response->header($name, $value);
            }

            return $response;
        }

        return new Response($response, 200, $headers);
    }

    private function identityKey(RequestContext $context): string
    {
        $identity = RequestIdentity::key($context);
        if ($identity !== 'guest') {
            return $identity;
        }

        $clientIp = $this->resolveClientIp($context);
        if ($clientIp !== null) {
            return 'ip:' . $clientIp;
        }

        return 'guest';
    }

    private function resolveClientIp(RequestContext $context): ?string
    {
        $resolver = $this->clientIpResolver ?? new ClientIpResolver();
        if ($resolver instanceof ClientIpResolverInterface) {
            return $resolver->resolve($context->request);
        }

        return $resolver->resolve();
    }
}
