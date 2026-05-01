<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Audit;

use BetterRoute\Http\ClientIpResolver;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;
use BetterRoute\Middleware\Network\ClientIpResolverInterface;

final class AuditEnricherMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $staticFields
     */
    public function __construct(
        private readonly array $staticFields = [],
        private readonly ClientIpResolver|ClientIpResolverInterface|null $clientIpResolver = null,
        private readonly bool $includeClientIp = false
    ) {
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $extra = array_merge($this->fromAuth($context), $this->staticFields);
        $idempotencyKey = $this->header($context->request, 'idempotency-key');
        if ($idempotencyKey !== null) {
            $extra['idempotencyKey'] = sha1($idempotencyKey);
        }

        if ($this->includeClientIp) {
            $clientIp = $this->resolveClientIp($context);
            if ($clientIp !== null) {
                $extra['clientIp'] = $clientIp;
            }
        }

        $existing = $context->attributes['audit'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        return $next($context->withAttribute('audit', array_merge($existing, $extra)));
    }

    /**
     * @return array<string, mixed>
     */
    private function fromAuth(RequestContext $context): array
    {
        $auth = $context->attributes['auth'] ?? null;
        if (!is_array($auth)) {
            return [];
        }

        $extra = [];
        foreach (['provider', 'userId', 'subject'] as $field) {
            if (array_key_exists($field, $auth)) {
                $extra['auth' . ucfirst($field)] = $auth[$field];
            }
        }

        return $extra;
    }

    private function header(mixed $request, string $name): ?string
    {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            return null;
        }

        $value = $request->get_header($name);
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value !== '' ? $value : null;
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
