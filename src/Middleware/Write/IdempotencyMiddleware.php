<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\ConflictException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;

final class IdempotencyMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $methods;

    /** @var callable(RequestContext, string): string */
    private $keyResolver;

    /** @var callable(RequestContext): string */
    private $fingerprintResolver;

    /**
     * @param list<string> $methods
     * @param null|callable(RequestContext, string): string $keyResolver
     * @param null|callable(RequestContext): string $fingerprintResolver
     */
    public function __construct(
        private readonly IdempotencyStoreInterface $store,
        private readonly int $ttlSeconds = 300,
        private readonly bool $requireKey = false,
        array $methods = ['POST'],
        ?callable $keyResolver = null,
        ?callable $fingerprintResolver = null
    ) {
        $this->methods = array_values(array_map(static fn (string $method): string => strtoupper($method), $methods));

        $this->keyResolver = $keyResolver ?? static fn (RequestContext $context, string $idempotencyKey): string => $context->routePath . '|' . $idempotencyKey;
        $this->fingerprintResolver = $fingerprintResolver ?? fn (RequestContext $context): string => $this->defaultFingerprint($context);
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $method = $this->requestMethod($context->request);
        if (!in_array($method, $this->methods, true)) {
            return $next($context);
        }

        $idempotencyKey = $this->extractIdempotencyKey($context->request);
        if ($idempotencyKey === null) {
            if ($this->requireKey) {
                throw new ApiException('Idempotency key is required.', 400, 'idempotency_key_required');
            }

            return $next($context);
        }

        $storeKey = ($this->keyResolver)($context, $idempotencyKey);
        $fingerprint = ($this->fingerprintResolver)($context);

        $cached = $this->store->get($storeKey);
        if (is_array($cached) && array_key_exists('fingerprint', $cached) && array_key_exists('response', $cached)) {
            $cachedFingerprint = is_string($cached['fingerprint']) ? $cached['fingerprint'] : '';
            if ($cachedFingerprint !== $fingerprint) {
                throw new ConflictException(
                    message: 'Idempotency key conflict.',
                    errorCode: 'idempotency_conflict',
                    details: ['key' => $idempotencyKey]
                );
            }

            return $this->withReplayHeader($cached['response']);
        }

        $response = $next($context);

        $payload = [
            'fingerprint' => $fingerprint,
            'response' => $response,
        ];

        $this->store->set($storeKey, $payload, max(1, $this->ttlSeconds));

        return $response;
    }

    private function extractIdempotencyKey(mixed $request): ?string
    {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            return null;
        }

        $header = $request->get_header('idempotency-key');
        if (!is_string($header)) {
            return null;
        }

        $key = trim($header);
        return $key !== '' ? $key : null;
    }

    private function defaultFingerprint(RequestContext $context): string
    {
        $method = $this->requestMethod($context->request);
        $params = [];

        if (is_object($context->request) && method_exists($context->request, 'get_json_params')) {
            $json = $context->request->get_json_params();
            if (is_array($json)) {
                $params['json'] = $json;
            }
        }

        if (is_object($context->request) && method_exists($context->request, 'get_body_params')) {
            $body = $context->request->get_body_params();
            if (is_array($body)) {
                $params['body'] = $body;
            }
        }

        if (is_object($context->request) && method_exists($context->request, 'get_params')) {
            $query = $context->request->get_params();
            if (is_array($query)) {
                $params['params'] = $query;
            }
        }

        ksort($params);

        return sha1(json_encode([
            'route' => $context->routePath,
            'method' => $method,
            'params' => $params,
        ]));
    }

    private function requestMethod(mixed $request): string
    {
        if (is_object($request) && method_exists($request, 'get_method')) {
            $method = $request->get_method();
            if (is_string($method) && $method !== '') {
                return strtoupper($method);
            }
        }

        return 'GET';
    }

    private function withReplayHeader(mixed $response): mixed
    {
        if (!$response instanceof Response) {
            return $response;
        }

        $headers = array_merge($response->headers, ['Idempotency-Replayed' => 'true']);
        return new Response($response->body, $response->status, $headers);
    }
}
