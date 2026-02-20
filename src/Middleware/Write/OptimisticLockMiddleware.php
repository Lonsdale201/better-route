<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\ConflictException;
use BetterRoute\Http\PreconditionFailedException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;

final class OptimisticLockMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly OptimisticLockVersionResolverInterface $versionResolver,
        private readonly bool $required = true,
        private readonly string $headerName = 'if-match',
        private readonly string $paramName = 'version'
    ) {
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $expected = $this->extractExpectedVersion($context->request);
        if ($expected === null) {
            if ($this->required) {
                throw new PreconditionFailedException('Precondition required.', 'precondition_required');
            }

            return $next($context);
        }

        $current = $this->versionResolver->resolve($context);
        if ($current === null || $current === '') {
            throw new ConflictException('Version is unavailable.', 'version_unavailable');
        }

        $currentNormalized = $this->normalizeVersion($current);

        if ($expected !== '*' && $currentNormalized !== $expected) {
            throw new PreconditionFailedException(
                message: 'Optimistic lock failed.',
                errorCode: 'optimistic_lock_failed',
                details: [
                    'expected' => $expected,
                    'current' => $currentNormalized,
                ]
            );
        }

        $ctx = $context->withAttribute('optimisticLock', [
            'expected' => $expected,
            'current' => $currentNormalized,
        ]);

        return $next($ctx);
    }

    private function extractExpectedVersion(mixed $request): ?string
    {
        if (is_object($request) && method_exists($request, 'get_header')) {
            $headerValue = $request->get_header($this->headerName);
            if (is_string($headerValue) && trim($headerValue) !== '') {
                return $this->normalizeVersion(trim($headerValue));
            }
        }

        if (is_object($request) && method_exists($request, 'get_param')) {
            $paramValue = $request->get_param($this->paramName);
            $normalized = $this->normalizeVersion($paramValue);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        if (is_array($request) && array_key_exists($this->paramName, $request)) {
            return $this->normalizeVersion($request[$this->paramName]);
        }

        return null;
    }

    private function normalizeVersion(mixed $value): ?string
    {
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        if ($normalized === '*') {
            return $normalized;
        }

        if (str_starts_with($normalized, 'W/')) {
            $normalized = trim(substr($normalized, 2));
        }

        if (str_starts_with($normalized, '"') && str_ends_with($normalized, '"') && strlen($normalized) >= 2) {
            $normalized = substr($normalized, 1, -1);
        }

        return $normalized;
    }
}
