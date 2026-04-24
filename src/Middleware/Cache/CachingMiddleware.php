<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Cache;

use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;

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
        $this->store->set($key, $response, $this->ttlSeconds);

        return $response;
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

        ksort($params);
        return sha1($context->routePath . '|' . $this->identityKey($context) . '|' . json_encode($params));
    }

    private function identityKey(RequestContext $context): string
    {
        $auth = $context->attributes['auth'] ?? null;
        if (is_array($auth)) {
            $provider = is_string($auth['provider'] ?? null) ? $auth['provider'] : 'auth';
            $userId = $auth['userId'] ?? null;
            if (is_int($userId) && $userId > 0) {
                return $provider . ':user:' . $userId;
            }

            $subject = $auth['subject'] ?? null;
            if (is_string($subject) && $subject !== '') {
                return $provider . ':sub:' . $subject;
            }
        }

        return 'guest';
    }
}
