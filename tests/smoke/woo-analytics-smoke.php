<?php

declare(strict_types=1);

namespace BetterRouteAnalyticsSmoke;

use Automattic\WooCommerce\Admin\API\Reports\Customers\DataStore as CustomersDataStore;
use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\WooAnalyticsService;
use RuntimeException;
use Throwable;
use WP_CLI;
use WP_REST_Request;
use WP_User;

// Only readiness scheduling is controlled; reports read real native lookup rows.
final class ControlledOrdersScheduler extends OrdersScheduler
{
    public static bool $importing = false;

    public static function is_importing(): bool
    {
        return self::$importing;
    }
}

if (!defined('WP_CLI') || !WP_CLI || !class_exists('WC_Order')) {
    throw new RuntimeException('Requires disposable WP-CLI + WooCommerce.');
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/OpenApiSamples.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
$prefix = 'branalytics_' . bin2hex(random_bytes(5));
$originalUser = get_current_user_id();
$users = [];
$objects = [];
$stats = ['imported_from' => -1, 'orders' => ['total' => 1, 'imported' => 1], 'customers' => ['total' => 1, 'imported' => 1]];
$statsFilter = static function () use (&$stats): array {
    return $stats;
};
$schedulerFilter = static fn (): array => [new ControlledOrdersScheduler()];
$mailGuard = static fn (): bool => true;
$httpGuard = static fn (): \WP_Error => new \WP_Error('no_egress', 'Fixture blocks HTTP.');
add_filter('pre_option_woocommerce_admin_import_stats', $statsFilter);
add_filter('woocommerce_analytics_report_schedulers', $schedulerFilter);
add_filter('pre_wp_mail', $mailGuard, PHP_INT_MAX);
add_filter('pre_http_request', $httpGuard, PHP_INT_MAX);
$assertions = 0;
$check = static function (bool $condition, string $label) use (&$assertions): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$assertions;
};
$denied = static function (callable $operation, int $status, string $code) use ($check): void {
    try {
        $operation();
    } catch (ApiException $e) {
        $check($e->status() === $status && $e->errorCode() === $code, 'Expected ' . $code . ', received ' . $e->errorCode());
        return;
    }
    throw new RuntimeException('Expected ' . $code);
};
$failure = null;
$samples = [];
try {
    foreach (['administrator', 'subscriber', 'customer'] as $index => $role) {
        $id = wp_insert_user(['user_login' => $prefix . $index, 'user_email' => $prefix . $index . '@example.invalid', 'user_pass' => wp_generate_password(), 'role' => $role]);
        if (is_wp_error($id)) {
            throw new RuntimeException($id->get_error_message());
        }
        $users[] = $id;
    }
    [$admin, $viewer, $customer] = $users;
    (new WP_User($viewer))->add_cap('view_woocommerce_reports');
    wp_set_current_user($admin);
    $product = new \WC_Product_Simple();
    $product->set_name($prefix);
    $product->set_regular_price('10.00');
    $product->set_status('publish');
    $product->save();
    $objects[] = $product;
    $coupon = new \WC_Coupon();
    $coupon->set_code($prefix);
    $coupon->set_discount_type('fixed_cart');
    $coupon->set_amount('2.00');
    $coupon->save();
    $objects[] = $coupon;
    $order = wc_create_order(['customer_id' => $customer]);
    if (is_wp_error($order)) {
        throw new RuntimeException($order->get_error_message());
    }
    $objects[] = $order;
    $order->set_date_created(time() - DAY_IN_SECONDS);
    $order->add_product($product, 2);
    $order->apply_coupon($prefix);
    $order->calculate_totals(false);
    $order->set_status('completed');
    $order->save();
    // Populate genuine Woo lookup tables synchronously. Import readiness itself
    // is controlled by a request-local option filter to exercise each state.
    CustomersDataStore::update_registered_customer($customer);
    OrdersScheduler::import($order->get_id());
    do_action('rest_api_init');
    $service = new WooAnalyticsService();
    $window = ['after' => gmdate('Y-m-d\TH:i:s', time() - 3 * DAY_IN_SECONDS) . 'Z', 'before' => gmdate('Y-m-d\TH:i:s', time() + DAY_IN_SECONDS) . 'Z'];
    wp_set_current_user(0);
    $denied(static fn () => $service->report('orders/stats', $window), 403, 'forbidden');
    wp_set_current_user($customer);
    $denied(static fn () => $service->report('orders/stats', $window), 403, 'forbidden');
    wp_set_current_user($viewer);
    $check(!current_user_can('manage_woocommerce'), 'Viewer has reports-only permission');
    $result = $service->report('orders/stats', $window);
    $check($result['data']['totals']['orders_count'] >= 1, 'Actual order included');
    $check(is_string($result['data']['totals']['net_revenue']), 'Revenue decimal string');
    $sum = array_sum(array_column(array_column($result['data']['intervals'], 'subtotals'), 'orders_count'));
    $check($sum >= 1, 'Native object interval metrics preserved');
    $check(get_current_user_id() === $viewer, 'Native user unchanged');
    foreach ($service->reports() as $report) {
        if ($report === 'stock') {
            continue;
        }
        $data = $service->report($report, $window);
        $schema = substr(\BetterRoute\Integration\Woo\WooAnalyticsOpenApi::meta($report)['responseSchema'], strlen('#/components/schemas/'));
        $samples[] = ['schema' => $schema, 'value' => $data];
        $check($data['report'] === $report, 'Report available: ' . $report);
        $check(!isset($data['data']['_links']), 'Links omitted');
    }
    $products = $service->report('products', $window);
    $productRows = array_column($products['data']['items'], null, 'product_id');
    $check(isset($productRows[$product->get_id()]), 'Actual product sales lookup');
    $check($productRows[$product->get_id()]['items_sold'] === 2, 'Native product quantity');
    $couponRows = array_column($service->report('coupons', $window)['data']['items'], null, 'coupon_id');
    $check(isset($couponRows[$coupon->get_id()]), 'Actual coupon lookup');
    $check((float) $couponRows[$coupon->get_id()]['amount'] === 2.0, 'Native coupon amount');
    $compare = $service->report('orders/stats', array_merge($window, ['compare_after' => $window['after'], 'compare_before' => $window['before']]));
    $samples[] = ['schema' => 'WooAnalyticsOrdersStatsResponse', 'value' => $compare];
    $check($compare['data']['totals'] === $compare['comparison']['data']['totals'], 'Comparison same bounds and DTO');
    $denied(static fn () => $service->report('../users', []), 400, 'validation_failed');
    $denied(static fn () => $service->report('orders/stats', ['_fields' => 'secret']), 400, 'validation_failed');
    $stats = [];
    $denied(static fn () => $service->report('orders/stats', $window), 503, 'analytics_data_not_ready');
    $stats = ['imported_from' => -1];
    ControlledOrdersScheduler::$importing = true;
    $denied(static fn () => $service->report('orders/stats', $window), 503, 'analytics_data_not_ready');
    ControlledOrdersScheduler::$importing = false;
    $stats = ['imported_from' => -1, 'orders' => ['total' => 2, 'imported' => 1]];
    $denied(static fn () => $service->report('orders/stats', $window), 503, 'analytics_data_not_ready');
    $stats = ['imported_from' => gmdate('Y-m-d H:i:s')];
    $denied(static fn () => $service->report('orders/stats', $window), 503, 'analytics_data_not_ready');
    $stats = ['imported_from' => gmdate('Y-m-d H:i:s', time() - 4 * DAY_IN_SECONDS)];
    $denied(static fn () => $service->report('orders/stats', array_merge($window, [
        'compare_after' => gmdate('Y-m-d\TH:i:s', time() - 7 * DAY_IN_SECONDS) . 'Z', 'compare_before' => $window['before'],
    ])), 503, 'analytics_data_not_ready');
    $stats = ['imported_from' => -1];
    $disabled = static fn (): bool => true;
    add_filter('woocommerce_admin_disabled', $disabled);
    try {
        $denied(static fn () => $service->report('orders/stats', $window), 503, 'analytics_unavailable');
    } finally {
        remove_filter('woocommerce_admin_disabled', $disabled);
    }
    $injected = false;
    $secretFilter = static function ($response, $handler, WP_REST_Request $request) use (&$injected) {
        if ($request->get_route() === '/wc-analytics/reports/orders/stats') {
            $injected = true;
            $data = $response->get_data();
            $data['secret'] = 'must-not-leak';
            $data['totals']['secret'] = 'must-not-leak';
            $response->set_data($data);
        }
        return $response;
    };
    add_filter('rest_request_after_callbacks', $secretFilter, 10, 3);
    try {
        $check(!str_contains(json_encode($service->report('orders/stats', $window), JSON_THROW_ON_ERROR), 'must-not-leak'), 'Upstream extension fields stripped');
        $check($injected, 'Actual upstream extension filter executed');
    } finally {
        remove_filter('rest_request_after_callbacks', $secretFilter, 10);
    }
    wp_set_current_user($admin);
    $check($service->report('stock')['report'] === 'stock', 'Stock report uses native extra permissions');
    $samples[] = ['schema' => 'WooAnalyticsStockResponse', 'value' => $service->report('stock')];
    \BetterRouteSmoke\writeOpenApiSamples('analytics', $samples);
    wp_set_current_user($customer);
    $denied(static fn () => $service->report('orders/stats', $window), 403, 'forbidden');
    WP_CLI::log('PASS Analytics: native lookup data, all report paths, reports-only viewer, comparison, readiness, DTOs, user isolation');
} catch (Throwable $e) {
    $failure = $e;
} finally {
    wp_set_current_user($originalUser);
    foreach (array_reverse($objects) as $object) {
        $object->delete(true);
    }
    foreach ($users as $id) {
        wp_delete_user($id);
    }
    remove_filter('pre_option_woocommerce_admin_import_stats', $statsFilter);
    remove_filter('woocommerce_analytics_report_schedulers', $schedulerFilter);
    remove_filter('pre_wp_mail', $mailGuard, PHP_INT_MAX);
    remove_filter('pre_http_request', $httpGuard, PHP_INT_MAX);
}
if ($failure !== null) {
    WP_CLI::error(get_class($failure) . ': ' . $failure->getMessage());
}
WP_CLI::success(sprintf('Analytics smoke: %d assertions; fixtures removed.', $assertions));
