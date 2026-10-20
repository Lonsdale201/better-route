<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

/** Fixed upstream paths, query contracts and response fields; extension fields stay private. */
final class AnalyticsReports
{
    public const REPORTS = [
        'revenue/stats', 'orders/stats', 'products', 'products/stats', 'categories',
        'customers', 'customers/stats', 'coupons', 'coupons/stats', 'taxes', 'stock', 'variations',
    ];

    /**
     * @return array{stats: bool, dates: bool, paginated: bool, interval: bool, sort: list<string>, segments: list<string>, fields: array<string, string>}
     */
    public static function definition(string $report): array
    {
        if (!in_array($report, self::REPORTS, true)) {
            throw new ApiException('Unsupported analytics report.', 400, 'validation_failed');
        }
        $stats = str_ends_with($report, '/stats');
        $sort = match ($report) {
            'revenue/stats' => ['date', 'total_sales', 'coupons', 'refunds', 'shipping', 'taxes', 'net_revenue', 'orders_count', 'items_sold', 'gross_sales'],
            'orders/stats' => ['date', 'net_revenue', 'orders_count', 'avg_order_value'],
            'products' => ['date', 'net_revenue', 'orders_count', 'items_sold', 'product_name', 'variations', 'sku'],
            'products/stats' => ['date', 'net_revenue', 'coupons', 'refunds', 'shipping', 'taxes', 'orders_count', 'items_sold'],
            'categories' => ['category_id', 'items_sold', 'net_revenue', 'orders_count', 'products_count', 'category'],
            'customers' => ['date_registered', 'date_last_active', 'orders_count', 'total_spend', 'avg_order_value', 'username', 'name', 'first_name', 'last_name', 'email', 'location', 'country', 'city', 'state', 'postcode'],
            'coupons' => ['coupon_id', 'code', 'amount', 'orders_count'],
            'coupons/stats' => ['date', 'amount', 'coupons_count', 'orders_count'],
            'taxes' => ['tax_rate_id', 'name', 'tax_code', 'rate', 'order_tax', 'total_tax', 'shipping_tax', 'orders_count'],
            'stock' => ['stock_status', 'stock_quantity', 'date', 'id', 'title', 'sku'],
            'variations' => ['date', 'net_revenue', 'orders_count', 'items_sold', 'sku'],
            default => [],
        };
        $segments = match ($report) {
            'revenue/stats', 'orders/stats' => ['product', 'category', 'variation', 'coupon', 'customer_type'],
            'products/stats' => ['product', 'category', 'variation'],
            'coupons/stats' => ['product', 'variation', 'category', 'coupon'],
            default => [],
        };
        $fields = match ($report) {
            'revenue/stats' => self::fields('orders_count,num_items_sold,coupons_count,products', 'total_sales,net_revenue,coupons,shipping,taxes,refunds,gross_sales'),
            'orders/stats' => self::fields('orders_count,num_items_sold,coupons_count,total_customers,products', 'net_revenue,avg_order_value,coupons', 'avg_items_per_order'),
            'products/stats' => self::fields('items_sold,orders_count', 'net_revenue'),
            'coupons/stats' => self::fields('coupons_count,orders_count', 'amount'),
            'customers/stats' => self::fields('customers_count', 'avg_total_spend,avg_avg_order_value', 'avg_orders_count'),
            'products' => self::fields('product_id,items_sold,orders_count', 'net_revenue'),
            'categories' => self::fields('category_id,items_sold,orders_count,products_count', 'net_revenue'),
            'coupons' => self::fields('coupon_id,orders_count', 'amount', '', 'code'),
            'customers' => self::fields('id,user_id,orders_count', 'total_spend,avg_order_value', '', 'name,username,date_registered,date_last_active'),
            'taxes' => self::fields('tax_rate_id,orders_count', 'total_tax,order_tax,shipping_tax', 'rate', 'name,tax_code'),
            'stock' => self::fields('id,parent_id', '', 'stock_quantity', 'name,sku,stock_status'),
            'variations' => self::fields('product_id,variation_id,items_sold,orders_count', 'net_revenue'),
        };
        return [
            'stats' => $stats, 'dates' => $report !== 'stock', 'paginated' => $report !== 'customers/stats',
            'interval' => ($stats && $report !== 'customers/stats') || $report === 'categories',
            'sort' => $sort, 'segments' => $segments, 'fields' => $fields,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function fields(string $counts, string $money, string $numbers = '', string $strings = ''): array
    {
        $fields = [];
        foreach (['count' => $counts, 'money' => $money, 'number' => $numbers, 'string' => $strings] as $type => $names) {
            foreach (array_filter(explode(',', $names)) as $name) {
                $fields[$name] = $type;
            }
        }
        return $fields;
    }
}
