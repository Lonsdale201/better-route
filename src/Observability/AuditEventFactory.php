<?php

declare(strict_types=1);

namespace BetterRoute\Observability;

use BetterRoute\Http\RequestContext;

final class AuditEventFactory
{
    /** @var callable(): string */
    private $clock;

    /**
     * @param null|callable(): string $clock
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): string => gmdate(DATE_ATOM);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function success(RequestContext $context, string $method, int $statusCode, int $durationMs, array $extra = []): array
    {
        return $this->baseEvent(
            context: $context,
            method: $method,
            outcome: 'success',
            statusCode: $statusCode,
            errorCode: null,
            errorMessage: null,
            durationMs: $durationMs,
            extra: $extra
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public function error(
        RequestContext $context,
        string $method,
        int $statusCode,
        string $errorCode,
        string $errorMessage,
        int $durationMs,
        array $extra = []
    ): array {
        return $this->baseEvent(
            context: $context,
            method: $method,
            outcome: 'error',
            statusCode: $statusCode,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            durationMs: $durationMs,
            extra: $extra
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function baseEvent(
        RequestContext $context,
        string $method,
        string $outcome,
        int $statusCode,
        ?string $errorCode,
        ?string $errorMessage,
        int $durationMs,
        array $extra
    ): array {
        $event = [
            'event' => 'http_request',
            'timestamp' => ($this->clock)(),
            'requestId' => $context->requestId,
            'traceId' => $context->requestId,
            'route' => $context->routePath,
            'method' => strtoupper($method),
            'outcome' => $outcome,
            'statusCode' => $statusCode,
            'errorCode' => $errorCode,
            'error' => $errorMessage,
            'durationMs' => $durationMs,
            // Legacy alias kept for compatibility with existing consumers/tests.
            'status' => $outcome === 'success' ? 'ok' : 'error',
        ];

        return array_merge($event, $extra);
    }
}
