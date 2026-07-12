<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

final class HposGuard
{
    public function assertReady(bool $requireHpos = true): void
    {
        $this->assertWooAvailable();

        if ($requireHpos) {
            $this->assertHposEnabled();
        }
    }

    public function assertWooAvailable(): void
    {
        if (!function_exists('wc_get_orders') || !function_exists('wc_get_products')) {
            throw new ApiException(
                message: 'WooCommerce is unavailable.',
                status: 503,
                errorCode: 'woo_unavailable'
            );
        }
    }

    public function assertHposEnabled(): void
    {
        if (!$this->isHposEnabled()) {
            throw new ApiException(
                message: 'HPOS is required for this endpoint.',
                status: 503,
                errorCode: 'hpos_required'
            );
        }
    }

    /**
     * Declare HPOS (custom order tables) compatibility for the HOST plugin.
     *
     * A library cannot declare on behalf of the plugin that embeds it, so call
     * this from the host plugin's main file with its __FILE__:
     *
     *   \BetterRoute\Integration\Woo\HposGuard::declareCompatibility(__FILE__);
     *
     * Any plugin exposing these order routes touches orders and must declare on
     * `before_woocommerce_init`, or WooCommerce flags it incompatible and blocks
     * HPOS enablement. The runtime HposGuard check does not remove this obligation.
     */
    public static function declareCompatibility(string $pluginFile): void
    {
        if (!function_exists('add_action')) {
            return;
        }

        add_action('before_woocommerce_init', static function () use ($pluginFile): void {
            if (class_exists('Automattic\\WooCommerce\\Utilities\\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                    'custom_order_tables',
                    $pluginFile,
                    true
                );
            }
        });
    }

    public function isHposEnabled(): bool
    {
        if (!class_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil')) {
            return false;
        }

        if (!method_exists('Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled')) {
            return false;
        }

        return \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
    }
}
