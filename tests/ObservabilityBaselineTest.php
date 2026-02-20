<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\Observability\MetricsMiddleware;
use BetterRoute\Observability\AuditEventFactory;
use BetterRoute\Observability\InMemoryMetricSink;
use BetterRoute\Observability\PrometheusMetricSink;
use PHPUnit\Framework\TestCase;

final class ObservabilityBaselineTest extends TestCase
{
    public function testMetricsMiddlewareTracksRequestsAndErrors(): void
    {
        $sink = new InMemoryMetricSink();
        $middleware = new MetricsMiddleware($sink, clock: $this->clockSequence([1000.0, 1000.5, 1001.0, 1001.5]));

        $okContext = new RequestContext('req_obs_ok', '/metrics', new ObservabilityRequest('GET'));
        $middleware->handle($okContext, static fn (): Response => new Response(['ok' => true], 200));

        $errorContext = new RequestContext('req_obs_err', '/metrics', new ObservabilityRequest('GET'));

        try {
            $middleware->handle($errorContext, static function (): never {
                throw new ApiException('boom', 400, 'validation_failed');
            });
        } catch (ApiException) {
            // expected
        }

        $counterKeys = array_keys($sink->counters());
        $observationKeys = array_keys($sink->observations());

        self::assertNotEmpty($counterKeys);
        self::assertNotEmpty($observationKeys);

        $joinedCounters = implode("\n", $counterKeys);
        self::assertStringContainsString('better_route_requests_total', $joinedCounters);
        self::assertStringContainsString('better_route_errors_total', $joinedCounters);
    }

    public function testPrometheusSinkRendersMetrics(): void
    {
        $sink = new PrometheusMetricSink();
        $sink->increment('better_route_requests_total', 1, ['route' => '/ping', 'method' => 'GET', 'status_class' => '2xx']);
        $sink->observe('better_route_request_duration_seconds', 0.250, ['route' => '/ping', 'method' => 'GET', 'status_class' => '2xx']);

        $render = $sink->render();

        self::assertStringContainsString('# TYPE better_route_requests_total counter', $render);
        self::assertStringContainsString('better_route_requests_total{method="GET",route="/ping",status_class="2xx"} 1', $render);
        self::assertStringContainsString('# TYPE better_route_request_duration_seconds summary', $render);
    }

    public function testAuditEventFactoryProvidesStandardSchema(): void
    {
        $factory = new AuditEventFactory(static fn (): string => '2026-02-20T10:00:00+00:00');
        $context = new RequestContext('req_obs_audit', '/orders', new ObservabilityRequest('POST'));

        $event = $factory->error(
            context: $context,
            method: 'POST',
            statusCode: 412,
            errorCode: 'optimistic_lock_failed',
            errorMessage: 'Version mismatch',
            durationMs: 37
        );

        self::assertSame('http_request', $event['event']);
        self::assertSame('2026-02-20T10:00:00+00:00', $event['timestamp']);
        self::assertSame('req_obs_audit', $event['requestId']);
        self::assertSame('POST', $event['method']);
        self::assertSame('error', $event['outcome']);
        self::assertSame('optimistic_lock_failed', $event['errorCode']);
        self::assertSame('error', $event['status']);
    }

    /**
     * @param list<float> $values
     * @return callable(): float
     */
    private function clockSequence(array $values): callable
    {
        return static function () use (&$values): float {
            if ($values === []) {
                return 0.0;
            }

            return (float) array_shift($values);
        };
    }
}

final class ObservabilityRequest
{
    public function __construct(private readonly string $method)
    {
    }

    public function get_method(): string
    {
        return $this->method;
    }
}
