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
                status: 409,
                errorCode: 'hpos_required'
            );
        }
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
