<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\ConflictException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;
use BetterRoute\Support\Canonicalizer;
use BetterRoute\Support\RequestIdentity;
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
        private readonly bool $releaseOnThrowable = false,
        private readonly int $maxKeyLength = 200
    ) {
        if ($ttlSeconds < 1) {
            throw new \InvalidArgumentException('Idempotency TTL must be positive.');
        }
        if ($maxKeyLength < 1) {
            throw new \InvalidArgumentException('Idempotency key length must be positive.');
        }
        $this->methods = array_values(array_map(static fn (string $method): string => strtoupper($method), $methods));

        $this->keyResolver = $keyResolver ?? static fn (RequestContext $context, string $idempotencyKey): string => Canonicalizer::json([
            'route' => $context->routePath,
            'identity' => RequestIdentity::key($context),
            'key' => $idempotencyKey,
        ]);
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
                'reservationToken' => $reservation->reservationToken,
            ]));
        } catch (Throwable $throwable) {
            if ($this->releaseOnThrowable) {
                if (
                    $this->store instanceof LeaseAwareAtomicIdempotencyStoreInterface
                    && is_string($reservation->reservationToken)
                ) {
                    $this->store->releaseReservation($storeKey, $fingerprint, $reservation->reservationToken);
                } else {
                    $this->store->release($storeKey, $fingerprint);
                }
            }

            throw $throwable;
        }

        if ($this->isWpError($response)) {
            // Returned WP_Error values are normalized outside the middleware
            // pipeline. Keep the reservation uncertain, but let the original
            // WordPress error reach the normalizer instead of serializing it.
            return $response;
        }

        $storedResponse = StoredResponseCodec::normalizeForStorage($response);

        if (
            $this->store instanceof LeaseAwareAtomicIdempotencyStoreInterface
            && is_string($reservation->reservationToken)
        ) {
            $this->store->completeReservation(
                $storeKey,
                $fingerprint,
                $reservation->reservationToken,
                $storedResponse,
                max(1, $this->ttlSeconds)
            );
        } else {
            $this->store->complete($storeKey, $fingerprint, $storedResponse, max(1, $this->ttlSeconds));
        }

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
        if ($key === '') {
            return null;
        }
        if (strlen($key) > $this->maxKeyLength || preg_match('/^[\x21-\x7E]+$/D', $key) !== 1) {
            throw new ApiException('Idempotency key is invalid.', 400, 'idempotency_key_invalid');
        }

        return $key;
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

        return sha1(Canonicalizer::json([
            'route' => $context->routePath,
            'method' => $method,
            'identity' => RequestIdentity::key($context),
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
        if ($response instanceof Response) {
            $headers = array_merge($response->headers, ['Idempotency-Replayed' => 'true']);
            return new Response($response->body, $response->status, $headers);
        }

        if (is_object($response) && method_exists($response, 'header')) {
            $response->header('Idempotency-Replayed', 'true');
            return $response;
        }

        return new Response($response, 200, ['Idempotency-Replayed' => 'true']);
    }

    private function isWpError(mixed $response): bool
    {
        return class_exists('WP_Error') && $response instanceof \WP_Error;
    }
}
