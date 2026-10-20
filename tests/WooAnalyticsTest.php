<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\AnalyticsQueryParser;
use BetterRoute\Integration\Woo\AnalyticsReports;
use BetterRoute\Integration\Woo\AnalyticsResponseMapper;
use PHPUnit\Framework\TestCase;

final class WooAnalyticsTest extends TestCase
{
    public function testOnlyFixedReportPathsAndReportSpecificParametersAreAccepted(): void
    {
        $parser = new AnalyticsQueryParser(new \DateTimeZone('UTC'));
        foreach (AnalyticsReports::REPORTS as $report) {
            self::assertSame($report, $parser->parse($report, [])->report);
        }
        $bad = [
            ['../../users', []], ['orders', []],
            ['stock', ['after' => '2026-01-01T00:00:00']],
            ['customers/stats', ['page' => 1]], ['categories', ['orderby' => 'date']],
            ['coupons', ['orderby' => 'date']], ['products', ['interval' => 'day']],
            ['orders/stats', ['segmentby' => 'arbitrary']], ['orders/stats', ['context' => 'edit']],
            ['orders/stats', ['_fields' => 'secret']], ['orders/stats', ['force_cache_refresh' => true]],
            ['products', ['per_page' => '99999999999999999999999999999']],
        ];
        foreach ($bad as [$report, $params]) {
            try {
                $parser->parse($report, $params);
                self::fail('Unsafe report query accepted.');
            } catch (ApiException $exception) {
                self::assertSame(400, $exception->status());
            }
        }
    }

    public function testDatesUseShopTimezoneAndComparisonUsesTheSameConstraints(): void
    {
        $parser = new AnalyticsQueryParser(new \DateTimeZone('Europe/Budapest'));
        $query = $parser->parse('orders/stats', [
            'after' => '2026-06-01T00:00:00Z', 'before' => '2026-06-02T00:00:00Z',
            'compare_after' => '2026-05-01T00:00:00Z', 'compare_before' => '2026-05-02T00:00:00Z',
            'interval' => 'hour', 'segmentby' => 'product',
        ]);
        self::assertSame('2026-06-01T02:00:00', $query->params['after']);
        self::assertSame('2026-05-01T02:00:00', $query->comparison['after']);
        self::assertSame('product', $query->comparison['segmentby']);
        $customers = $parser->parse('customers/stats', ['after' => '2026-06-01T00:00:00', 'before' => '2026-06-02T00:00:00']);
        self::assertArrayHasKey('registered_after', $customers->params);
        self::assertArrayNotHasKey('after', $customers->params);
    }

    public function testInvalidOrExcessiveWindowsFailBeforeDispatch(): void
    {
        $parser = new AnalyticsQueryParser();
        foreach ([
            ['after' => '2026-02-30T00:00:00'],
            ['after' => 'yesterday'], ['after' => ['bad']],
            ['after' => '2026-01-02T00:00:00', 'before' => '2026-01-01T00:00:00'],
            ['after' => '2020-01-01T00:00:00', 'before' => '2026-01-01T00:00:00'],
            ['after' => '2026-01-01T00:00:00', 'before' => '2026-03-01T00:00:00', 'interval' => 'hour'],
            ['compare_after' => '2026-01-01T00:00:00'],
        ] as $params) {
            try {
                $parser->parse('orders/stats', $params);
                self::fail('Invalid date window accepted.');
            } catch (ApiException $exception) {
                self::assertSame('validation_failed', $exception->errorCode());
            }
        }
    }

    public function testDtosStripExtensionFieldsAndPreserveMoneyAndFractionalAverages(): void
    {
        $mapper = new AnalyticsResponseMapper();
        $data = $mapper->map('orders/stats', [
            'totals' => ['orders_count' => '2', 'net_revenue' => '123456789012345.67', 'avg_items_per_order' => 1.5, 'secret' => 'omit', 'segments' => [
                ['segment_id' => 4, 'secret' => 'omit', 'subtotals' => ['orders_count' => 1, 'secret' => 'omit']],
            ]],
            'intervals' => [['interval' => 'day', 'date_start' => '2026-01-01T00:00:00', 'subtotals' => ['net_revenue' => 12.5], '_links' => ['secret']]],
            '_links' => ['secret'],
        ]);
        self::assertSame('123456789012345.67', $data['totals']['net_revenue']);
        self::assertSame(1.5, $data['totals']['avg_items_per_order']);
        self::assertSame(2, $data['totals']['orders_count']);
        self::assertSame('12.50', $data['intervals'][0]['subtotals']['net_revenue']);
        self::assertStringNotContainsString('secret', json_encode($data, JSON_THROW_ON_ERROR));
        self::assertSame(['totals' => ['customers_count' => 2]], $mapper->map('customers/stats', ['totals' => ['customers_count' => 2], 'intervals' => [['secret' => 1]]]));
        self::assertSame(['totals' => ['customers_count' => 3]], $mapper->map('customers/stats', ['totals' => (object) ['customers_count' => 3]]));
        $native = $mapper->map('orders/stats', ['totals' => [], 'intervals' => [['subtotals' => (object) ['orders_count' => 4, 'net_revenue' => 20]]]]);
        self::assertSame(4, $native['intervals'][0]['subtotals']['orders_count']);
    }
}
