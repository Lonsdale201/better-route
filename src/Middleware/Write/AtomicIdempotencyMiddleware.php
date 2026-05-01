<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\ConflictException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;
use Throwable;

final class AtomicIdempotencyMiddleware implements MiddlewareInterface
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
        private readonly AtomicIdempotencyStoreInterface $store,
        private readonly int $ttlSeconds = 300,
        private readonly bool $requireKey = true,
        array $methods = ['POST', 'PUT', 'PATCH', 'DELETE'],
        ?callable $keyResolver = null,
        ?callable $fingerprintResolver = null,
        private readonly bool $releaseOnThrowable = true
    ) {
        $this->methods = array_values(array_map(static fn (string $method): string => strtoupper($method), $methods));

        $this->keyResolver = $keyResolver ?? fn (RequestContext $context, string $idempotencyKey): string => $context->routePath . '|' . $this->identityKey($context) . '|' . $idempotencyKey;
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
        $reservation = $this->store->reserve($storeKey, $fingerprint, max(1, $this->ttlSeconds));

        if ($reservation->isConflict()) {
            throw new ConflictException(
                message: 'Idempotency key conflict.',
                errorCode: 'idempotency_conflict',
                details: ['key' => $idempotencyKey]
            );
        }

        if ($reservation->isReplay()) {
            return $this->withReplayHeader($reservation->response);
        }

        if ($reservation->isInProgress()) {
            throw new ConflictException(
                message: 'Idempotency request is already in progress.',
                errorCode: 'idempotency_in_progress',
                details: ['key' => $idempotencyKey]
            );
        }

        try {
            $response = $next($context->withAttribute('idempotency', [
                'mode' => 'atomic',
                'key' => $idempotencyKey,
                'fingerprint' => $fingerprint,
            ]));
        } catch (Throwable $throwable) {
            if ($this->releaseOnThrowable) {
                $this->store->release($storeKey, $fingerprint);
            }

            throw $throwable;
        }

        $this->store->complete($storeKey, $fingerprint, $response, max(1, $this->ttlSeconds));

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
            'identity' => $this->identityKey($context),
            'params' => $params,
        ]));
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
