<?php

/**
 * Plugin Name: Better Route Regression Smoke
 * Description: Disposable CLI fixtures for request isolation and Woo order integrity. Never auto-runs.
 * Version: 1.1.1
 */

declare(strict_types=1);

namespace BetterRouteSmoke;

use BetterRoute\Http\RequestContext;
use BetterRoute\Integration\Woo\WooRouteRegistrar;
use BetterRoute\Middleware\Auth\BearerTokenAuthMiddleware;
use BetterRoute\Middleware\Auth\BearerTokenVerifierInterface;
use BetterRoute\Middleware\Auth\ClaimsUserMapperInterface;
use BetterRoute\Middleware\Cache\CachingMiddleware;
use BetterRoute\Middleware\Cache\TransientCacheStore;
use BetterRoute\Middleware\Write\AtomicIdempotencyMiddleware;
use BetterRoute\Middleware\Write\WpdbAtomicIdempotencyStore;
use BetterRoute\Router\Router;
use WP_CLI;
use WP_REST_Request;

if (!defined('WP_CLI') || !WP_CLI) {
    return;
}

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'BetterRoute\\')) {
        $path = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

WP_CLI::add_command('better-route-smoke', static function (array $args, array $options): void {
    (new Smoke())->run($options['mode'] ?? 'hpos');
});

final class Smoke
{
    private string $run;
    private int $assertions = 0;
    private array $orders = [];
    private array $products = [];
    private array $users = [];
    private array $transients = [];
    private array $events = [];
    private array $diagnostics = [];
    private string $table;

    public function run(string $mode): void
    {
        if (!in_array($mode, ['hpos', 'cpt'], true) || !function_exists('WC')) {
            WP_CLI::error('Requires WooCommerce and --mode=hpos|cpt.');
        }
        $this->run = 'brsmoke_' . bin2hex(random_bytes(6));
        $this->table = $this->run . '_replays';
        $originalUser = get_current_user_id();
        // Request-local options: never change the site's storage mode or tax settings.
        add_filter('pre_option_woocommerce_custom_orders_table_enabled', static fn () => $mode === 'hpos' ? 'yes' : 'no');
        add_filter('pre_option_woocommerce_custom_orders_table_data_sync_enabled', static fn () => 'no');
        add_filter('pre_option_woocommerce_calc_taxes', static fn () => 'yes');
        add_filter('pre_option_woocommerce_prices_include_tax', static fn () => 'no');
        add_filter('pre_option_woocommerce_tax_based_on', static fn () => 'billing');
        add_filter('pre_wp_mail', '__return_true');
        add_action('doing_it_wrong_run', function ($function): void {
            $this->diagnostics[] = $function;
        });
        add_filter('pre_http_request', static fn () => new \WP_Error('smoke_no_egress', 'Smoke blocks outbound HTTP.'), PHP_INT_MAX);
        add_filter('woocommerce_find_rates', static function ($rates, $taxArgs) {
            if (($taxArgs['tax_class'] ?? '') !== '') {
                return $rates;
            }
            return [987654321 => [
                'rate' => ($taxArgs['country'] ?? '') === 'DE' ? '19' : '27',
                'label' => 'Smoke tax', 'shipping' => 'yes', 'compound' => 'no',
            ]];
        }, 10, 2);
        add_action('woocommerce_order_status_processing', function ($id, $order): void {
            if ($order->get_created_via() === $this->run) {
                $fresh = wc_get_order($id);
                $this->events[] = [(float) $order->get_total(), (float) $fresh->get_total()];
            }
        }, 1, 2);
        $failure = null;
        try {
            $this->same($mode === 'hpos', \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), 'storage mode');
            foreach (['outer', 'inner'] as $name) {
                $id = wp_insert_user([
                    'user_login' => $this->run . '_' . $name,
                    'user_pass' => wp_generate_password(48),
                    'user_email' => $this->run . '_' . $name . '@example.invalid',
                    'role' => 'subscriber',
                ]);
                if (is_wp_error($id)) {
                    throw new \RuntimeException('Could not create smoke identity.');
                }
                $this->users[] = (int) $id;
            }
            $store = new WpdbAtomicIdempotencyStore($this->table);
            $store->installSchema();
            $this->registerRoutes($store);
            $this->requestIsolation();
            $this->authIsolation();
            $this->wooIntegrity();
        } catch (\Throwable $error) {
            $failure = $error;
        } finally {
            wp_set_current_user($originalUser);
            $this->cleanup();
        }
        if ($failure !== null) {
            WP_CLI::error($failure->getMessage());
        }
        $this->same([], $this->diagnostics, 'no API misuse diagnostics');
        WP_CLI::success(wp_json_encode([
            'mode' => $mode, 'assertions' => $this->assertions, 'cleanup' => 'complete',
            'wp' => get_bloginfo('version'), 'woo' => WC_VERSION, 'php' => PHP_VERSION,
        ]));
    }

    private function registerRoutes(WpdbAtomicIdempotencyStore $store): void
    {
        $register = function () use ($store): void {
            $cache = new TransientCacheStore(
                setTransient: function ($key, $value, $ttl): bool {
                    $this->transients[] = $key;
                    return set_transient($key, $value, $ttl);
                }
            );
            foreach (['v1', 'v2'] as $version) {
                $router = Router::make($this->run, $version);
                $router->get('/items/(?P<id>\d+)', static fn ($request) => [
                    'version' => $version, 'id' => (int) $request->get_url_params()['id'],
                ])->publicRoute()->middleware([new CachingMiddleware($cache)]);
                $router->post('/items/(?P<id>\d+)', static fn ($request) => [
                    'version' => $version, 'id' => (int) $request->get_url_params()['id'],
                ])->publicRoute()->middleware([new AtomicIdempotencyMiddleware($store)]);
                $router->register();
            }
            $mapper = new class ($this->users[1]) implements ClaimsUserMapperInterface {
                public function __construct(private int $id)
                {
                }
                public function mapUserId(array $claims, RequestContext $context): ?int
                {
                    return $this->id;
                }
            };
            $verifier = new class () implements BearerTokenVerifierInterface {
                public function verify(string $token): array
                {
                    return ['sub' => 'smoke'];
                }
            };
            $router = Router::make($this->run, 'auth');
            $auth = new BearerTokenAuthMiddleware($verifier, userMapper: $mapper);
            $router->get('/me', function (): \WP_REST_Response {
                $response = new \WP_REST_Response(['user' => get_current_user_id()]);
                $response->add_link('smoke', rest_url($this->run . '/auth/ambient'), ['embeddable' => true]);
                return $response;
            })->protectedByMiddleware()->middleware([$auth]);
            $router->get('/ambient', static fn () => ['user' => get_current_user_id()])->publicRoute();
            $router->get('/throw', static function () {
                throw new \RuntimeException('smoke');
            })->protectedByMiddleware()->middleware([$auth]);
            $router->get('/unmapped', static fn () => ['user' => get_current_user_id()])->protectedByMiddleware()->middleware([new BearerTokenAuthMiddleware($verifier)]);
            $router->register();
            (new WooRouteRegistrar())->register($this->run . '/woo', [
                'requireHpos' => false, 'deleteMode' => 'force',
                'actions' => ['orders' => ['create', 'get', 'update'], 'products' => ['update'], 'customers' => [], 'coupons' => []],
                'permissions' => array_fill_keys(['orders.create', 'orders.get', 'orders.update', 'products.update'], fn () => get_current_user_id() === $this->users[0]),
                'idempotency' => ['enabled' => true, 'requireKey' => true, 'store' => $store],
            ]);
        };
        if (did_action('rest_api_init')) {
            $register();
        } else {
            add_action('rest_api_init', $register);
            rest_get_server();
        }
        if (!did_action('rest_api_init')) {
            throw new \RuntimeException('REST did not initialize.');
        }
    }

    private function requestIsolation(): void
    {
        foreach (['GET', 'POST'] as $method) {
            foreach ([['v1', 1], ['v2', 1], ['v1', 2], ['v1', 1]] as [$version, $id]) {
                $response = $this->request($method, '/' . $this->run . '/' . $version . '/items/' . $id, [], 'shared-key', ['id' => 2]);
                $this->same(200, $response->get_status(), 'isolation status');
                $this->same(['version' => $version, 'id' => $id], $response->get_data(), 'route identity');
            }
        }
        $replay = $this->request('POST', '/' . $this->run . '/v1/items/1', [], 'shared-key', ['id' => 2]);
        $this->same('true', $replay->get_headers()['Idempotency-Replayed'] ?? null, 'identical retry replay');
        $conflict = $this->request('POST', '/' . $this->run . '/v1/items/1', ['changed' => true], 'shared-key', ['id' => 2]);
        $this->same(409, $conflict->get_status(), 'changed payload conflict');
    }

    private function authIsolation(): void
    {
        wp_set_current_user($this->users[0]);
        $seen = null;
        $observe = static function ($response) use (&$seen) {
            $seen = get_current_user_id();
            return $response;
        };
        add_filter('rest_request_after_callbacks', $observe);
        $response = $this->request('GET', '/' . $this->run . '/auth/me');
        $this->same($this->users[1], $response->get_data()['user'], 'mapped handler identity');
        $this->same($this->users[0], get_current_user_id(), 'restore caller');
        $this->same($this->users[0], $seen, 'after-callback identity');
        $embedded = rest_get_server()->response_to_data($response, true);
        $this->same($this->users[0], $embedded['_embedded']['smoke'][0]['user'], 'embedding uses restored caller');
        $response = $this->request('GET', '/' . $this->run . '/auth/throw');
        $this->same(500, $response->get_status(), 'throw normalized');
        $this->same($this->users[0], get_current_user_id(), 'restore after throw');
        $response = $this->request('GET', '/' . $this->run . '/auth/unmapped');
        $this->same(0, $response->get_data()['user'], 'unmapped no ambient privilege');
        remove_filter('rest_request_after_callbacks', $observe);
    }

    private function wooIntegrity(): void
    {
        $product = new \WC_Product_Simple();
        $product->set_name($this->run);
        $product->set_regular_price('100');
        $product->set_tax_status('taxable');
        $product->set_tax_class('');
        $product->set_status('private');
        $product->update_meta_data('_better_route_smoke_run', $this->run);
        $this->products[] = $product->save();
        $base = '/' . $this->run . '/woo/woo/orders';
        $marker = ['better_route_smoke_run' => $this->run];
        $payload = ['meta_data' => $marker, 'billing' => ['country' => 'HU'], 'line_items' => [['product_id' => $product->get_id(), 'quantity' => 1]]];
        $response = $this->request('POST', $base, $payload, 'order-create');
        $this->same(201, $response->get_status(), 'order create');
        $id = $response->get_data()['data']['id'];
        $this->orders[] = $id;
        $order = wc_get_order($id);
        $order->set_created_via($this->run);
        $order->save();
        $this->same(127.0, (float) $order->get_total(), 'initial tax');
        $replay = $this->request('POST', $base, $payload, 'order-create');
        $this->same($id, $replay->get_data()['data']['id'], 'no duplicate order');
        $this->same('true', $replay->get_headers()['Idempotency-Replayed'] ?? null, 'order replay header');
        $response = $this->request('PATCH', $base . '/' . $id, ['billing' => ['country' => 'DE']], 'address-update');
        $this->same(200, $response->get_status(), 'address update');
        $this->same(119.0, (float) $response->get_data()['data']['total'], 'address-only new tax');
        $response = $this->request('PATCH', $base . '/' . $id, ['status' => 'processing', 'line_items' => [['product_id' => $product->get_id(), 'quantity' => 2]]], 'status-items');
        $this->same(200, $response->get_status(), 'status with items');
        $this->same([[238.0, 238.0]], $this->events, 'lifecycle sees final object and stored total');
        $fractional = ['meta_data' => $marker, 'line_items' => [['product_id' => $product->get_id(), 'quantity' => 0.5]]];
        $response = $this->request('POST', $base, $fractional, 'fraction-rejected');
        $this->same(400, $response->get_status(), 'default integer store rejects fractional write');
        remove_filter('woocommerce_stock_amount', 'intval');
        add_filter('woocommerce_stock_amount', 'floatval');
        try {
            $response = $this->request('POST', $base, $fractional, 'fraction-accepted');
            $this->same(201, $response->get_status(), 'fraction-enabled store create');
            $fractionId = $response->get_data()['data']['id'];
            $this->orders[] = $fractionId;
            $this->same(0.5, (float) $response->get_data()['data']['line_items'][0]['quantity'], 'fraction roundtrip');
            $response = $this->request('PATCH', '/' . $this->run . '/woo/woo/products/' . $product->get_id(), ['manage_stock' => true, 'stock_quantity' => -2.5], 'fraction-stock');
            $this->same(200, $response->get_status(), 'fractional inventory update');
            $this->same(-2.5, (float) $response->get_data()['data']['stock_quantity'], 'negative fractional stock');
            $response = $this->request('GET', $base . '/' . $fractionId);
            $this->same(0.5, (float) $response->get_data()['data']['line_items'][0]['quantity'], 'stored fraction fresh read');
            $paid = 0;
            $observe = static function ($orderId) use ($fractionId, &$paid): void {
                if ($orderId === $fractionId) {
                    $paid++;
                }
            };
            add_action('woocommerce_payment_complete', $observe);
            $this->same(200, $this->request('PATCH', $base . '/' . $fractionId, ['set_paid' => true], 'paid-once')->get_status(), 'mark paid');
            $this->same(200, $this->request('PATCH', $base . '/' . $fractionId, ['set_paid' => true], 'paid-again')->get_status(), 'repeat mark paid');
            $this->same(1, $paid, 'repeat set_paid fires once');
            remove_action('woocommerce_payment_complete', $observe);
        } finally {
            remove_filter('woocommerce_stock_amount', 'floatval');
            add_filter('woocommerce_stock_amount', 'intval');
        }
        wp_set_current_user($this->users[1]);
        $this->same(403, $this->request('GET', $base . '/' . $id)->get_status(), 'unauthorized identity denied');
    }

    private function request(string $method, string $path, array $body = [], string $key = '', array $query = []): \WP_REST_Response
    {
        $request = new WP_REST_Request($method, $path);
        $request->set_header('authorization', 'Bearer smoke');
        $request->set_header('idempotency-key', $key);
        $request->set_query_params($query);
        if ($body !== []) {
            $request->set_header('content-type', 'application/json');
            $request->set_body(wp_json_encode($body));
        }
        return rest_do_request($request);
    }

    private function same(mixed $expected, mixed $actual, string $label): void
    {
        $this->assertions++;
        if ($expected !== $actual) {
            throw new \RuntimeException($label . ': expected ' . wp_json_encode($expected) . ', got ' . wp_json_encode($actual));
        }
    }

    private function cleanup(): void
    {
        global $wpdb;
        // Recover only positively marked fixtures after partially completed work.
        // Never capture/delete every order produced by an integration's hooks.
        $markerQuery = [['key' => 'better_route_smoke_run', 'value' => $this->run]];
        $marked = \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
            ? wc_get_orders(['limit' => -1, 'return' => 'ids', 'meta_query' => $markerQuery])
            : get_posts([
                'post_type' => 'shop_order', 'post_status' => 'any',
                'numberposts' => -1, 'fields' => 'ids', 'meta_query' => $markerQuery,
            ]);
        $this->orders = array_merge($this->orders, $marked);
        foreach (array_unique($this->orders) as $id) {
            $order = wc_get_order($id);
            if ($order) {
                if ($order->get_meta('better_route_smoke_run') !== $this->run) {
                    throw new \RuntimeException('Unproven order ownership; refusing fixture deletion.');
                }
                $order->delete(true);
            }
            if (wc_get_order($id)) {
                throw new \RuntimeException('Order fixture cleanup failed.');
            }
        }
        foreach ($this->products as $id) {
            $product = wc_get_product($id);
            if ($product) {
                if ($product->get_meta('_better_route_smoke_run') !== $this->run) {
                    throw new \RuntimeException('Unproven product ownership; refusing fixture deletion.');
                }
                $product->delete(true);
            }
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($this->users as $id) {
            $user = get_userdata($id);
            if (!$user || !str_starts_with($user->user_login, $this->run . '_')) {
                throw new \RuntimeException('Unproven user ownership; refusing fixture deletion.');
            }
            wp_delete_user($id);
        }
        foreach (array_unique($this->transients) as $key) {
            delete_transient($key);
        }
        // This identifier is generated internally, never accepted from CLI input.
        $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . $this->table . '`');
    }
}
