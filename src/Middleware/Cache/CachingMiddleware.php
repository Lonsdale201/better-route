<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Cache;

use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;
use BetterRoute\Support\Canonicalizer;
use BetterRoute\Support\RequestIdentity;

/**
 * Identity-aware GET response cache.
 *
 * ORDERING: place this middleware AFTER any authentication middleware in the
 * pipeline. The cache key derives from the `auth` request attribute; if caching
 * runs before auth populates it, every user shares the "guest" key and a
 * private response could be served to another user.
 */
final class CachingMiddleware implements MiddlewareInterface
{
    /** @var callable(RequestContext): string */
    private $keyResolver;

    /**
     * @param null|callable(RequestContext): string $keyResolver
     */
    public function __construct(
        private readonly CacheStoreInterface $store,
        private readonly int $ttlSeconds = 60,
        ?callable $keyResolver = null
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Cache TTL must be positive.');
        }
        $this->keyResolver = $keyResolver ?? fn (RequestContext $context): string => $this->defaultKey($context);
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        if (!$this->isGetRequest($context->request)) {
            return $next($context);
        }

        $key = ($this->keyResolver)($context);
        $cached = $this->store->get($key);
        if ($cached !== null && $cached !== false) {
            return $cached;
        }

        $response = $next($context);
        if ($this->isCacheable($response)) {
            $this->store->set($key, $response, $this->ttlSeconds);
        }

        return $response;
    }

    private function isCacheable(mixed $response): bool
    {
        if (class_exists('WP_Error') && $response instanceof \WP_Error) {
            return false;
        }

        // Never cache error responses that were returned (not thrown) — e.g. a
        // 403/404/500 Response would otherwise be replayed for the whole TTL.
        $status = $response instanceof Response ? $response->status : 200;
        if (is_object($response) && method_exists($response, 'get_status')) {
            $status = (int) $response->get_status();
        }

        return $status >= 200 && $status < 300;
    }

    private function isGetRequest(mixed $request): bool
    {
        if (is_object($request) && method_exists($request, 'get_method')) {
            $method = $request->get_method();
            return is_string($method) && strtoupper($method) === 'GET';
        }

        return true;
    }

    private function defaultKey(RequestContext $context): string
    {
        $params = [];
        if (is_object($context->request) && method_exists($context->request, 'get_params')) {
            $raw = $context->request->get_params();
            if (is_array($raw)) {
                $params = $raw;
            }
        }

        return sha1(Canonicalizer::json([
            'route' => $context->routePath,
            'identity' => RequestIdentity::key($context),
            'params' => $params,
        ]));
    }
}
