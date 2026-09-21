<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

/** @internal Validate quantities without silently truncating fractional stock. */
final class StockQuantity
{
    public static function parse(mixed $value, string $field, bool $positive = false): int|float
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value)) || !is_numeric($value)) {
            throw self::invalid($field, 'must be a finite number');
        }

        $number = $value + 0;
        if (!is_finite((float) $number) || ($positive && $number <= 0)) {
            throw self::invalid($field, $positive ? 'must be a positive finite number' : 'must be a finite number');
        }

        // Woo stores control fractional support through woocommerce_stock_amount.
        // Do not let their normalizer silently change the requested quantity.
        if (function_exists('wc_stock_amount')) {
            $normalized = wc_stock_amount($number);
            if (!is_numeric($normalized) || (float) $normalized !== (float) $number) {
                throw self::invalid($field, 'quantity is not supported by the store stock configuration');
            }
        }

        return $number;
    }

    private static function invalid(string $field, string $message): ApiException
    {
        return new ApiException('Invalid request.', 400, 'validation_failed', [
            'fieldErrors' => [$field => [$message]],
        ]);
    }
}
