<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;
use BetterRoute\Support\Crypto;
use RuntimeException;

final class HmacSignatureMiddleware implements MiddlewareInterface
{
    /** @var callable(): int */
    private $now;

    /**
     * @param null|callable(): int $now
     */
    public function __construct(
        private readonly HmacSecretProviderInterface $secrets,
        private readonly string $signatureHeader = 'X-Signature',
        private readonly string $timestampHeader = 'X-Timestamp',
        private readonly string $keyIdHeader = 'X-Key-Id',
        private readonly int $replayWindowSeconds = 300,
        private readonly string $algorithm = 'sha256',
        ?callable $now = null
    ) {
        if ($replayWindowSeconds < 1) {
            throw new RuntimeException('HMAC replay window must be positive.');
        }
        if (!in_array($algorithm, hash_hmac_algos(), true)) {
            throw new RuntimeException('Unsupported HMAC algorithm.');
        }

        $this->now = $now ?? static fn (): int => time();
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $signature = $this->requiredHeader($context->request, $this->signatureHeader);
        $timestamp = $this->parseTimestamp($this->requiredHeader($context->request, $this->timestampHeader));
        $keyId = $this->requiredHeader($context->request, $this->keyIdHeader);
        $secret = $this->secrets->secretFor($keyId);
        if ($secret === null || $secret === '') {
            throw new ApiException('Invalid signature.', 401, 'invalid_signature');
        }

        $now = ($this->now)();
        if ($timestamp < $now - $this->replayWindowSeconds || $timestamp > $now + $this->replayWindowSeconds) {
            throw new ApiException('Signature timestamp is outside the replay window.', 401, 'stale_signature');
        }

        $canonical = implode("\n", [
            (string) $timestamp,
            $this->requestMethod($context->request),
            $this->requestPath($context),
            hash('sha256', $this->requestBody($context->request)),
        ]);

        if (!$this->matchesSignature($signature, $canonical, $secret)) {
            throw new ApiException('Invalid signature.', 401, 'invalid_signature');
        }

        return $next($context->withAttribute('hmac', [
            'keyId' => $keyId,
            'algorithm' => $this->algorithm,
        ]));
    }

    private function matchesSignature(string $signature, string $canonical, string $secret): bool
    {
        $raw = hash_hmac($this->algorithm, $canonical, $secret, true);
        $hex = hash_hmac($this->algorithm, $canonical, $secret, false);
        $candidates = [
            $hex,
            strtoupper($hex),
            base64_encode($raw),
            Crypto::base64UrlEncode($raw),
            $this->algorithm . '=' . $hex,
            $this->algorithm . '=' . strtoupper($hex),
            $this->algorithm . '=' . base64_encode($raw),
            $this->algorithm . '=' . Crypto::base64UrlEncode($raw),
        ];

        foreach ($candidates as $candidate) {
            if (Crypto::equals($candidate, $signature)) {
                return true;
            }
        }

        return false;
    }

    private function requiredHeader(mixed $request, string $name): string
    {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            throw new ApiException('Signature header is required.', 401, 'signature_required');
        }

        $value = $request->get_header($name);
        if (!is_string($value)) {
            throw new ApiException('Signature header is required.', 401, 'signature_required');
        }

        $value = trim($value);
        if ($value === '') {
            throw new ApiException('Signature header is required.', 401, 'signature_required');
        }

        return $value;
    }

    private function parseTimestamp(string $timestamp): int
    {
        if (preg_match('/^\d+$/', $timestamp) !== 1) {
            throw new ApiException('Signature timestamp is invalid.', 401, 'invalid_signature_timestamp');
        }

        return (int) $timestamp;
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

    private function requestPath(RequestContext $context): string
    {
        $request = $context->request;
        foreach (['get_route', 'get_uri'] as $method) {
            if (is_object($request) && method_exists($request, $method)) {
                $value = $request->{$method}();
                if (is_string($value) && $value !== '') {
                    return parse_url($value, PHP_URL_PATH) ?: $value;
                }
            }
        }

        return $context->routePath;
    }

    private function requestBody(mixed $request): string
    {
        if (is_object($request) && method_exists($request, 'get_body')) {
            $body = $request->get_body();
            if (is_string($body)) {
                return $body;
            }
        }

        return '';
    }
}
