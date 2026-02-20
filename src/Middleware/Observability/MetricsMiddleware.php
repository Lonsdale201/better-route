<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Observability;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;
use BetterRoute\Observability\MetricSinkInterface;
use Throwable;

final class MetricsMiddleware implements MiddlewareInterface
{
    /** @var callable(): float */
    private $clock;

    /**
     * @param null|callable(): float $clock
     */
    public function __construct(
        private readonly MetricSinkInterface $metrics,
        ?callable $clock = null,
        private readonly string $metricPrefix = 'better_route_'
    ) {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $method = $this->requestMethod($context->request);
        $startedAt = ($this->clock)();

        try {
            $result = $next($context);
            $statusCode = $this->responseStatus($result);

            $labels = [
                'route' => $context->routePath,
                'method' => $method,
                'status_class' => $this->statusClass($statusCode),
            ];

            $this->metrics->increment($this->metricPrefix . 'requests_total', 1, $labels);
            $this->metrics->observe($this->metricPrefix . 'request_duration_seconds', ($this->clock)() - $startedAt, $labels);

            if ($statusCode >= 400) {
                $this->metrics->increment($this->metricPrefix . 'errors_total', 1, $labels + [
                    'error_code' => $this->responseErrorCode($result),
                ]);
            }

            return $result;
        } catch (Throwable $throwable) {
            $statusCode = $throwable instanceof ApiException ? $throwable->status() : 500;
            $errorCode = $throwable instanceof ApiException ? $throwable->errorCode() : 'internal_error';

            $labels = [
                'route' => $context->routePath,
                'method' => $method,
                'status_class' => $this->statusClass($statusCode),
            ];

            $this->metrics->increment($this->metricPrefix . 'requests_total', 1, $labels);
            $this->metrics->increment($this->metricPrefix . 'errors_total', 1, $labels + ['error_code' => $errorCode]);
            $this->metrics->observe($this->metricPrefix . 'request_duration_seconds', ($this->clock)() - $startedAt, $labels);

            throw $throwable;
        }
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

    private function responseStatus(mixed $result): int
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

    private function responseErrorCode(mixed $result): string
    {
        if ($result instanceof Response && is_array($result->body)) {
            return $this->extractErrorCodeFromArray($result->body);
        }

        if (is_array($result)) {
            return $this->extractErrorCodeFromArray($result);
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractErrorCodeFromArray(array $payload): string
    {
        if (isset($payload['error']) && is_array($payload['error']) && isset($payload['error']['code']) && is_string($payload['error']['code'])) {
            return $payload['error']['code'];
        }

        return 'unknown';
    }

    private function statusClass(int $statusCode): string
    {
        if ($statusCode < 100 || $statusCode > 599) {
            return 'unknown';
        }

        return (int) floor($statusCode / 100) . 'xx';
    }
}
