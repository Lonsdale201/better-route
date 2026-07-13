<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Audit;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;
use BetterRoute\Observability\AuditEventFactory;
use Throwable;

final class AuditMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuditLoggerInterface $logger,
        private readonly AuditEventFactory $eventFactory = new AuditEventFactory()
    ) {
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $startedAt = microtime(true);

        try {
            $result = $next($context);
            $statusCode = $this->statusCode($result);

            $this->safeLog($this->eventFactory->success(
                context: $context,
                method: $this->requestMethod($context->request),
                statusCode: $statusCode,
                durationMs: $this->durationMs($startedAt),
                extra: $this->auditExtra($context)
            ));

            return $result;
        } catch (Throwable $throwable) {
            $this->safeLog($this->eventFactory->error(
                context: $context,
                method: $this->requestMethod($context->request),
                statusCode: $throwable instanceof ApiException ? $throwable->status() : 500,
                errorCode: $throwable instanceof ApiException ? $throwable->errorCode() : 'internal_error',
                errorMessage: $throwable instanceof ApiException
                    ? $throwable->getMessage()
                    : 'Unexpected error.',
                durationMs: $this->durationMs($startedAt),
                extra: $this->auditExtra($context)
            ));

            throw $throwable;
        }
    }

    private function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function requestMethod(mixed $request): string
    {
        if (is_object($request) && method_exists($request, 'get_method')) {
            $method = $request->get_method();
            if (is_string($method) && $method !== '') {
                return strtoupper($method);
            }
        }

        return 'UNKNOWN';
    }

    private function statusCode(mixed $result): int
    {
        if ($result instanceof Response) {
            return $result->status;
        }

        if (is_object($result) && method_exists($result, 'get_status')) {
            $status = $result->get_status();
            if (is_int($status)) {
                return $status;
            }
        }

        if (is_array($result) && isset($result['status']) && is_int($result['status'])) {
            return $result['status'];
        }

        return 200;
    }

    /**
     * @return array<string, mixed>
     */
    private function auditExtra(RequestContext $context): array
    {
        $extra = $context->attributes['audit'] ?? [];
        return is_array($extra) ? $extra : [];
    }

    /** @param array<string, mixed> $event */
    private function safeLog(array $event): void
    {
        try {
            $this->logger->log($event);
        } catch (Throwable) {
            // Telemetry is best-effort and must never alter the API result or
            // mask the application exception that is already in flight.
        }
    }
}
