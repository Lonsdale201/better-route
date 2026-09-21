<?php

/**
 * Plugin Name: Better Route HTTP Smoke
 * Description: Temporary token-gated synthetic HTTP probes; requires an external private token file.
 * Version: 1.1.1
 */

declare(strict_types=1);

namespace BetterRouteHttpSmoke;

use BetterRoute\Http\Response;
use BetterRoute\Middleware\Cache\CachingMiddleware;
use BetterRoute\Middleware\Cache\TransientCacheStore;
use BetterRoute\Middleware\Write\AtomicIdempotencyMiddleware;
use BetterRoute\Middleware\Write\WpdbAtomicIdempotencyStore;
use BetterRoute\Router\Router;

if (defined('BETTER_ROUTE_SMOKE_TOKEN')) {
    $token = BETTER_ROUTE_SMOKE_TOKEN;
} elseif (defined('BETTER_ROUTE_SMOKE_TOKEN_FILE') && is_readable(BETTER_ROUTE_SMOKE_TOKEN_FILE)) {
    $token = trim((string) file_get_contents(BETTER_ROUTE_SMOKE_TOKEN_FILE));
} else {
    return;
}
$provided = $_SERVER['HTTP_X_BETTER_ROUTE_SMOKE'] ?? '';
if (!is_string($token) || strlen($token) < 32 || !is_string($provided) || !hash_equals($token, $provided)) {
    return;
}
$run = 'br_http_' . substr(hash('sha256', $token), 0, 16);
unset($token, $provided);

spl_autoload_register(static function (string $class): void {
    if (str_starts_with($class, 'BetterRoute\\')) {
        $path = dirname(__DIR__, 2) . '/src/' . str_replace('\\', '/', substr($class, 12)) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    }
});

add_action('rest_api_init', static function () use ($run): void {
    $store = new WpdbAtomicIdempotencyStore($run . '_replays');
    $control = Router::make('better-route-smoke', 'v1');
    $control->post('/setup', static function () use ($store, $run): array {
        $store->installSchema();
        add_option($run . '_writes', 0, '', false);
        return ['run' => $run, 'version' => \BetterRoute\Support\Version::VERSION];
    })->publicRoute();
    $control->get('/count', static fn (): array => ['writes' => (int) get_option($run . '_writes', 0)])->publicRoute();
    $control->delete('/cleanup', static function () use ($run): array {
        global $wpdb;
        // All names derive from the private random token, never request params.
        $names = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $wpdb->esc_like('_transient_' . $run . '_') . '%',
            $wpdb->esc_like('_transient_timeout_' . $run . '_') . '%'
        ));
        foreach ($names as $name) {
            delete_option($name);
        }
        $cacheKeys = get_option($run . '_cache_keys', []);
        foreach ($cacheKeys as $key) {
            delete_transient($key);
        }
        delete_option($run . '_cache_keys');
        delete_option($run . '_writes');
        $wpdb->query('DROP TABLE IF EXISTS `' . $wpdb->prefix . $run . '_replays`');
        return ['cleaned' => true];
    })->publicRoute();
    $control->register();
    $cache = new TransientCacheStore(
        getTransient: static fn ($key) => get_transient($run . '_' . $key),
        setTransient: static function ($key, $value, $ttl) use ($run): bool {
            $key = $run . '_' . $key;
            $keys = get_option($run . '_cache_keys', []);
            $keys[] = $key;
            update_option($run . '_cache_keys', array_unique($keys), false);
            return set_transient($key, $value, $ttl);
        }
    );
    foreach (['v1', 'v2'] as $version) {
        $router = Router::make($run, $version);
        $result = static fn ($request): array => ['version' => $version, 'id' => (int) $request->get_url_params()['id']];
        $router->get('/items/(?P<id>\d+)', $result)->publicRoute()->middleware([new CachingMiddleware($cache)]);
        $router->post('/items/(?P<id>\d+)', $result)->publicRoute()->middleware([new AtomicIdempotencyMiddleware($store)]);
        $router->post('/race', static function () use ($run): Response {
            global $wpdb;
            usleep(1500000);
            $wpdb->query($wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = CAST(option_value AS UNSIGNED) + 1 WHERE option_name = %s",
                $run . '_writes'
            ));
            wp_cache_delete($run . '_writes', 'options');
            return new Response(['written' => true], 201);
        })->publicRoute()->middleware([new AtomicIdempotencyMiddleware($store)]);
        $router->register();
    }
});
