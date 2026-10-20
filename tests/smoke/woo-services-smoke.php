<?php

declare(strict_types=1);

// Run only against a disposable WordPress + WooCommerce database through WP-CLI eval-file.

namespace BetterRouteWooServicesSmoke;

use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\CouponListQueryParser;
use BetterRoute\Integration\Woo\CouponQueryFilters;
use BetterRoute\Integration\Woo\CustomerListQuery;
use BetterRoute\Integration\Woo\CustomerListQueryParser;
use BetterRoute\Integration\Woo\CustomerRolePolicy;
use BetterRoute\Integration\Woo\WooCouponService;
use BetterRoute\Integration\Woo\WooCustomerService;
use RuntimeException;
use Throwable;
use WC_Coupon;
use WP_CLI;
use WP_Query;
use WP_User;

if (!defined('WP_CLI') || !WP_CLI || !class_exists(WC_Coupon::class)) {
    throw new RuntimeException('Requires disposable WP-CLI + WooCommerce.');
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once __DIR__ . '/OpenApiSamples.php';
require_once ABSPATH . 'wp-admin/includes/user.php';
$prefix = 'brwoo_' . bin2hex(random_bytes(5)) . '_';
$originalUser = get_current_user_id();
$users = $posts = [];
$orders = [];
$role = $prefix . 'privileged';
$count = 0;
$check = static function (bool $condition, string $label) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException($label);
    }
    ++$count;
};
$same = static fn (mixed $expected, mixed $actual, string $label) => $check($expected === $actual, $label . ': ' . json_encode([$expected, $actual]));
$denied = static function (callable $operation, int $status) use ($same): void {
    try {
        $operation();
    } catch (ApiException $e) {
        $same($status, $e->status(), 'Expected error');
        return;
    }
    throw new RuntimeException('Expected denial.');
};
$mailGuard = static fn (): bool => true;
$httpGuard = static fn (): \WP_Error => new \WP_Error('no_egress', 'Fixture blocks HTTP.');
$timezoneGuard = static fn (): string => 'Europe/Budapest';
add_filter('pre_wp_mail', $mailGuard, PHP_INT_MAX);
add_filter('pre_http_request', $httpGuard, PHP_INT_MAX);
add_filter('pre_option_timezone_string', $timezoneGuard, PHP_INT_MAX);
$newUser = static function (string $name, array $roles) use ($prefix, &$users): int {
    $id = wp_insert_user(['user_login' => $prefix . $name, 'user_email' => $prefix . $name . '@example.invalid', 'user_pass' => wp_generate_password(), 'role' => $roles[0]]);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $users[] = $id;
    foreach (array_slice($roles, 1) as $role) {
        (new WP_User($id))->add_role($role);
    }
    return $id;
};
$newCoupon = static function (string $name, int $usage = 0, int $limit = 0, ?int $expiry = null, string $status = 'publish') use ($prefix, &$posts): int {
    $c = new WC_Coupon();
    $c->set_code($prefix . $name);
    $c->set_amount('5');
    $c->set_usage_count($usage);
    $c->set_usage_limit($limit);
    $c->set_date_expires($expiry);
    if ($status === 'future') {
        $c->set_date_created(time() + 3 * DAY_IN_SECONDS);
    }
    $c->set_status($status);
    $posts[] = $id = $c->save();
    return $id;
};
$ids = static fn (array $result): array => array_map('intval', array_column($result['items'], 'id'));
$snapshot = static function (): array {
    $result = [];
    foreach (($GLOBALS['wp_filter']['posts_clauses']->callbacks ?? []) as $priority => $callbacks) {
        $result[$priority] = array_keys($callbacks);
    }
    return $result;
};
$failure = null;
try {
    add_role($role, 'Disposable test role', ['read' => true, 'update_plugins' => true]);
    $admin = $newUser('actor', ['administrator']);
    $a = $newUser('customer_a', ['customer']);
    $b = $newUser('customer_b', ['customer']);
    $subscriber = $newUser('subscriber', ['subscriber']);
    $mixed = $newUser('mixed', ['customer', 'administrator']);
    $direct = $newUser('direct', ['customer']);
    $denyCap = $newUser('deny_cap', ['customer']);
    $custom = $newUser('custom', ['customer', $role]);
    (new WP_User($direct))->add_cap('install_plugins', true);
    (new WP_User($denyCap))->add_cap('manage_options', false);
    wp_set_current_user($admin);
    $policy = new CustomerRolePolicy(['customer', 'subscriber', $role]);
    $service = new WooCustomerService($policy);
    $parser = new CustomerListQueryParser($service->allowedFields(), defaultFields: ['id'], rolePolicy: $policy);
    $list = static fn (array $params = []): array => $service->list($parser->parse(array_replace(['search' => $prefix, 'fields' => 'id', 'sort' => 'id'], $params)));
    $same(3, $list()['total'], 'Customer total excludes every privileged target');
    $same([$a, $b, $subscriber], $ids($list()), 'Customer visibility');
    $same(null, (new WooCustomerService())->get($subscriber, ['id']), 'Default excludes subscriber');
    foreach ([$mixed, $direct, $denyCap, $custom] as $id) {
        $same(null, $service->get($id, ['id']), 'Protected get');
        $same(null, $service->update($id, ['first_name' => 'denied'], ['id']), 'Protected update');
        $denied(static fn () => $service->delete($id), 404);
        $check(get_userdata($id) !== false, 'Protected user survives');
    }
    $denied(static fn () => $parser->parse(['role' => 'administrator']), 400);
    $denied(static fn () => $service->list(new CustomerListQuery(['id'], ['administrator'], null, $prefix, 'id', 'ASC', 1, 20)), 400);
    $same(3, $service->list(new CustomerListQuery(['id'], [], null, $prefix, 'id', 'ASC', 1, 20))['total'], 'Empty DTO role cannot widen');
    $pages = [];
    for ($page = 1; $page <= 3; ++$page) {
        $result = $list(['page' => $page, 'per_page' => 1]);
        $same(3, $result['total'], 'Total before pagination');
        array_push($pages, ...$ids($result));
    }
    $same([$a, $b, $subscriber], $pages, 'Full unique customer pages');
    $same([$subscriber], $ids($list(['role' => 'subscriber'])), 'Role filter narrows');
    wp_set_current_user($subscriber);
    $denied(static fn () => $service->create(['email' => $prefix . 'denied@example.invalid'], ['id']), 403);
    $denied(static fn () => $service->update($a, ['first_name' => 'denied'], ['id']), 403);
    $denied(static fn () => $service->delete($a), 403);
    wp_set_current_user($admin);
    $same('Accepted', $service->update($subscriber, ['first_name' => 'Accepted'], ['first_name'])['first_name'], 'Configured noncustomer updates');
    foreach (['wp_capabilities', 'wp_17_capabilities', 'session_tokens', '_application_passwords'] as $key) {
        $beforeMeta = get_user_meta($a, $key, true);
        $denied(static fn () => $service->update($a, ['meta_data' => [['key' => $key, 'value' => ['administrator' => true]]]], ['id']), 400);
        $same($beforeMeta, get_user_meta($a, $key, true), 'Security metadata not persisted');
    }
    $denied(static fn () => (new WooCustomerService(new CustomerRolePolicy(['subscriber'])))->create(['email' => $prefix . 'invisible@example.invalid'], ['id']), 403);
    $optIn = new WooCustomerService(new CustomerRolePolicy(['administrator'], allowPrivilegedRoles: true));
    $same($mixed, $optIn->get($mixed, ['id'])['id'], 'Privileged targets require explicit optin');
    $excluded = new WooCustomerService(new CustomerRolePolicy(['customer', 'subscriber'], excludedRoles: ['subscriber']));
    $same(null, $excluded->get($subscriber, ['id']), 'Explicit role exclusion shared by detail');
    $order = wc_create_order(['customer_id' => $subscriber]);
    if (is_wp_error($order)) {
        throw new RuntimeException($order->get_error_message());
    }
    $orders[] = $order;
    $order->set_total('12.34');
    $order->set_status('completed');
    $order->save();
    $aggregates = $service->get($subscriber, ['orders_count', 'total_spent']);
    $same(1, $aggregates['orders_count'], 'Order aggregate independent of customer role');
    $same(12.34, (float) $aggregates['total_spent'], 'Spent aggregate independent of role');
    $created = $service->create(['email' => $prefix . 'created@example.invalid', 'username' => $prefix . 'created'], ['id', 'role']);
    $users[] = $created['id'];
    $same('customer', $created['role'], 'Creation still assigns customer only');
    WP_CLI::log('PASS customer visibility, pagination, DTO boundary, native permissions, security metadata');

    $now = time();
    $missing = $newCoupon('missing');
    foreach (['date_expires', 'usage_count', 'usage_limit'] as $key) {
        delete_post_meta($missing, $key);
    }
    $zero = $newCoupon('zero');
    update_post_meta($zero, 'date_expires', '0');
    $exhausted = $newCoupon('exhausted', 3, 3);
    $soon = $newCoupon('soon', 1, 5, $now + 2 * DAY_IN_SECONDS);
    $expired = $newCoupon('expired', 0, 0, $now - 2 * DAY_IN_SECONDS);
    $expiredExhausted = $newCoupon('expired_exhausted', 5, 5, $now - DAY_IN_SECONDS);
    $legacy = $newCoupon('legacy');
    delete_post_meta($legacy, 'date_expires');
    update_post_meta($legacy, 'expiry_date', '2001-07-01 00:00:00');
    $modernEmpty = $newCoupon('modern_empty');
    update_post_meta($modernEmpty, 'expiry_date', '2001-07-01 00:00:00');
    update_post_meta($modernEmpty, 'date_expires', '');
    $duplicate = $newCoupon('duplicate', 1, 10, $now + 3 * DAY_IN_SECONDS);
    add_post_meta($duplicate, 'usage_count', '99');
    add_post_meta($duplicate, 'usage_limit', '1');
    add_post_meta($duplicate, 'date_expires', (string) ($now - DAY_IN_SECONDS));
    $draft = $newCoupon('draft', status: 'draft');
    $future = $newCoupon('future', status: 'future');
    $same('future', get_post_status($future), 'Actual future fixture');
    $coupons = new WooCouponService();
    $couponParser = new CouponListQueryParser($coupons->allowedFields(), defaultFields: $coupons->listDefaultFields());
    $listCoupons = static fn (array $params = []): array => $coupons->list($couponParser->parse(array_replace(['search' => $prefix, 'fields' => 'id,state', 'sort' => 'id', 'per_page' => 100], $params)));
    $states = ['active' => [$missing, $zero, $soon, $modernEmpty, $duplicate], 'expiring' => [$soon, $duplicate], 'expired' => [$expired, $expiredExhausted, $legacy], 'exhausted' => [$exhausted], 'draft' => [$draft], 'scheduled' => [$future]];
    foreach ($states as $state => $expected) {
        $result = $listCoupons(['state' => $state, 'expiring_days' => 7]);
        $same(count($expected), $result['total'], $state . ' total');
        $same($expected, $ids($result), $state . ' IDs');
        foreach ($result['items'] as $row) {
            $same($state === 'expiring' ? 'active' : $state, $row['state'], 'DTO state agrees');
        }
    }
    $same(9, $listCoupons()['total'], 'Duplicate metadata creates no duplicate rows');
    foreach (['draft' => $draft, 'future' => $future] as $status => $id) {
        $same([$id], $ids($listCoupons(['status' => $status, 'code' => strtoupper((new WC_Coupon($id))->get_code())])), 'Nonpublished code lookup');
    }
    $all = $ids($listCoupons());
    foreach (['usage_count', 'date_expires'] as $sort) {
        foreach (['ASC', 'DESC'] as $direction) {
            $expected = $all;
            usort($expected, static function (int $a, int $b) use ($sort, $direction): int {
                $av = $sort === 'usage_count' ? (new WC_Coupon($a))->get_usage_count('edit') : ((new WC_Coupon($a))->get_date_expires('edit')?->getTimestamp() ?? 0);
                $bv = $sort === 'usage_count' ? (new WC_Coupon($b))->get_usage_count('edit') : ((new WC_Coupon($b))->get_date_expires('edit')?->getTimestamp() ?? 0);
                if ($sort === 'date_expires' && ($av === 0) !== ($bv === 0)) {
                    return $av === 0 ? 1 : -1;
                }
                $cmp = [$av, $a] <=> [$bv, $b];
                return $direction === 'ASC' ? $cmp : -$cmp;
            });
            $same($expected, $ids($listCoupons(['sort' => ($direction === 'DESC' ? '-' : '') . $sort])), 'Numeric sort ' . $sort . ' ' . $direction);
        }
    }
    $pages = [];
    for ($page = 1; $page <= 5; ++$page) {
        $result = $listCoupons(['sort' => 'usage_count', 'page' => $page, 'per_page' => 2]);
        $same(9, $result['total'], 'Coupon page total');
        array_push($pages, ...$ids($result));
    }
    $same($ids($listCoupons(['sort' => 'usage_count'])), $pages, 'Complete unique coupon pages');
    $check(!array_key_exists('meta_data', $coupons->get($missing, $coupons->getDefaultFields())), 'Metadata optin detail');
    update_post_meta($missing, 'br_public_note', 'opt-in');
    update_post_meta($missing, '_br_secret', 'hidden');
    $metadata = array_column($coupons->get($missing, ['meta_data'])['meta_data'], 'value', 'key');
    $same('opt-in', $metadata['br_public_note'] ?? null, 'Explicit coupon metadata');
    $check(!array_key_exists('_br_secret', $metadata), 'Protected coupon metadata excluded');
    $denied(static fn () => $coupons->update($missing, ['state' => 'active'], ['id']), 400);
    $usageBefore = (new WC_Coupon($soon))->get_usage_count('edit');
    foreach ([['usage_count' => 999], [['key' => 'usage_count', 'value' => 999]], ['coupon_amount' => 999]] as $meta) {
        $denied(static fn () => $coupons->update($soon, ['meta_data' => $meta], ['id']), 400);
        $same($usageBefore, (new WC_Coupon($soon))->get_usage_count('edit'), 'Internal metadata cannot invoke coupon setter');
    }
    $coupons->update($soon, ['meta_data' => ['br_custom_note' => 'allowed']], ['id']);
    $same('allowed', (new WC_Coupon($soon))->get_meta('br_custom_note'), 'Public custom coupon metadata remains writable');
    do_action('rest_api_init');
    (new \BetterRoute\Integration\Woo\WooRouteRegistrar())->register('br-schema/v1', [
        'requireHpos' => false, 'defaultPerPage' => 1500, 'maxPerPage' => 2000,
    ]);
    $native = static function (string $path, array $params = []): \WP_REST_Response {
        $request = new \WP_REST_Request('GET', '/br-schema/v1/woo/' . $path);
        $request->set_query_params($params);
        return rest_do_request($request);
    };
    $samples = [];
    foreach (['customers' => 'WooCustomer', 'coupons' => 'WooCoupon', 'orders' => 'WooOrder', 'products' => 'WooProduct'] as $resource => $schema) {
        $response = $native($resource);
        $same(200, $response->get_status(), 'Native ' . $resource . ' with configured pagination above 1000');
        $samples[] = ['schema' => $schema . 'ListResponse', 'value' => $response->get_data()];
    }
    foreach (['customers' => [$a, 'WooCustomerResponse'], 'coupons' => [$soon, 'WooCouponResponse'], 'orders' => [$order->get_id(), 'WooOrderResponse']] as $resource => [$id, $schema]) {
        $response = $native($resource . '/' . $id);
        $same(200, $response->get_status(), 'Native detail ' . $resource);
        $samples[] = ['schema' => $schema, 'value' => $response->get_data()];
    }
    foreach (['customers', 'coupons'] as $resource) {
        $same($native($resource)->get_data(), $native($resource, ['sort' => ''])->get_data(), 'Empty sort preserves fallback');
    }
    $same($native('coupons')->get_data(), $native('coupons', ['state' => '', 'status' => ''])->get_data(), 'Empty state/status preserve fallback');
    foreach ([['sort' => 'invalid'], ['per_page' => 2001]] as $invalid) {
        $response = $native('coupons', $invalid);
        $same(400, $response->get_status(), 'Native validation error');
        $samples[] = ['response' => 'ErrorResponse', 'value' => $response->get_data()];
    }
    \BetterRouteSmoke\writeOpenApiSamples('services', $samples);
    $fixed = static function (int $id, string $state, int $clock) use ($couponParser): array {
        $dto = $couponParser->parse(['state' => $state]);
        $q = new WP_Query();
        $filter = static fn (array $clauses, WP_Query $candidate): array => $q === $candidate ? CouponQueryFilters::apply($clauses, $dto, $clock) : $clauses;
        add_filter('posts_clauses', $filter, 20, 2);
        try {
            $q->query(['post_type' => 'shop_coupon', 'post_status' => 'publish', 'post__in' => [$id], 'fields' => 'ids']);
            return array_map('intval', $q->posts);
        } finally {
            remove_filter('posts_clauses', $filter, 20);
        }
    };
    $clock = 2000000000;
    $equal = $newCoupon('equal', expiry: $clock);
    $same([$equal], $fixed($equal, 'active', $clock), 'Equality active');
    $same([], $fixed($equal, 'expired', $clock), 'Equality not expired');
    $same([$equal], $fixed($equal, 'expired', $clock + 1), 'Following second expired');
    $legacyTimestamp = (new \DateTimeImmutable('2001-07-01 00:00:00', new \DateTimeZone('Europe/Budapest')))->getTimestamp();
    $same([$legacy], $fixed($legacy, 'expired', $legacyTimestamp + 3600), 'Legacy SQL timezone');
    $before = $snapshot();
    $listCoupons(['state' => 'active']);
    $same($before, $snapshot(), 'Filter removed on success');
    $explode = static function (array $clauses): array {
        throw new RuntimeException('injected_failure');
    };
    add_filter('posts_clauses', $explode, 15);
    try {
        try {
            $listCoupons(['state' => 'active']);
        } catch (RuntimeException $e) {
            $same('injected_failure', $e->getMessage(), 'Injected failure');
        }
    } finally {
        remove_filter('posts_clauses', $explode, 15);
    }
    $same($before, $snapshot(), 'Filter removed on exception');
    $nested = false;
    $observer = static function (array $clauses, WP_Query $candidate) use (&$nested, $draft, $same): array {
        if (!$nested && $candidate->get('post_type') === 'shop_coupon') {
            $nested = true;
            $inner = new WP_Query(['post_type' => 'shop_coupon', 'post_status' => 'draft', 'post__in' => [$draft], 'fields' => 'ids']);
            $same([$draft], array_map('intval', $inner->posts), 'Nested coupon query not contaminated');
        }
        return $clauses;
    };
    add_filter('posts_clauses', $observer, 30, 2);
    try {
        $listCoupons(['state' => 'expired']);
    } finally {
        remove_filter('posts_clauses', $observer, 30);
    }
    $check($nested, 'Nested query executed');
    $same($before, $snapshot(), 'Nested query restores hooks');
    WP_CLI::log('PASS coupon states, legacy timezone, duplicate metadata, numeric sorts, pagination, query cleanup');
} catch (Throwable $e) {
    $failure = $e;
} finally {
    wp_set_current_user($originalUser);
    foreach ($orders as $order) {
        $order->delete(true);
    }
    foreach (array_unique($posts) as $id) {
        wp_clear_scheduled_hook('publish_future_post', [$id]);
        wp_delete_post($id, true);
    }
    foreach (array_unique($users) as $id) {
        wp_delete_user($id);
    }
    remove_role($role);
    remove_filter('pre_wp_mail', $mailGuard, PHP_INT_MAX);
    remove_filter('pre_http_request', $httpGuard, PHP_INT_MAX);
    remove_filter('pre_option_timezone_string', $timezoneGuard, PHP_INT_MAX);
}
if ($failure !== null) {
    WP_CLI::error(get_class($failure) . ': ' . $failure->getMessage());
}
WP_CLI::success(sprintf('Woo services smoke: %d assertions; fixtures removed.', $count));
