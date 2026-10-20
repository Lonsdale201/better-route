<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

/** Applies management-state predicates before WP_Query computes totals and LIMIT. */
final class CouponQueryFilters
{
    /**
     * @param array<string, string> $clauses
     * @return array<string, string>
     */
    public static function apply(array $clauses, CouponListQuery $query, int $now): array
    {
        global $wpdb;
        $meta = static fn (string $key): string => $wpdb->prepare(
            "(SELECT brm.meta_value FROM {$wpdb->postmeta} brm WHERE brm.post_id = {$wpdb->posts}.ID AND brm.meta_key = %s ORDER BY brm.meta_id ASC LIMIT 1)",
            $key
        );
        // The first value matches get_post_meta(..., true), without duplicate JOIN rows.
        $usage = 'CAST(COALESCE(' . $meta('usage_count') . ", '0') AS UNSIGNED)";
        $limit = 'CAST(COALESCE(' . $meta('usage_limit') . ", '0') AS UNSIGNED)";
        $needsExpiry = $query->sortField === 'date_expires' || in_array($query->state, ['active', 'expiring', 'expired', 'exhausted'], true);
        $expiry = $needsExpiry ? self::expiryExpression($meta('date_expires'), $meta('expiry_date')) : '0';
        $expired = "($expiry <> 0 AND $expiry < $now)";
        $exhausted = "($limit > 0 AND $usage >= $limit)";
        $clauses['where'] .= match ($query->state) {
            'active' => " AND NOT $expired AND NOT $exhausted",
            'expiring' => " AND NOT $expired AND NOT $exhausted AND $expiry > 0 AND $expiry <= " . ($now + $query->expiringDays * 86400),
            'expired' => " AND $expired",
            'exhausted' => " AND NOT $expired AND $exhausted",
            default => '',
        };
        if ($query->code !== null && $query->code !== '') {
            $clauses['where'] .= $wpdb->prepare(" AND {$wpdb->posts}.post_title = %s", wc_format_coupon_code($query->code));
        }
        $direction = $query->sortDirection === 'ASC' ? 'ASC' : 'DESC';
        if ($query->sortField === 'usage_count') {
            $clauses['orderby'] = "$usage $direction, {$wpdb->posts}.ID $direction";
        } elseif ($query->sortField === 'date_expires') {
            // Non-expiring coupons sort last in either direction.
            $clauses['orderby'] = "($expiry = 0) ASC, $expiry $direction, {$wpdb->posts}.ID $direction";
        }
        return $clauses;
    }

    private static function expiryExpression(string $modern, string $legacy): string
    {
        global $wpdb;
        // Woo still supports pre-3.0 local-date metadata. Convert in PHP rather
        // than relying on the database server's timezone or installed tz tables.
        $values = $wpdb->get_col("SELECT DISTINCT pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'shop_coupon' AND pm.meta_key = 'expiry_date' AND pm.meta_id = (SELECT MIN(first_meta.meta_id) FROM {$wpdb->postmeta} first_meta WHERE first_meta.post_id = p.ID AND first_meta.meta_key = 'expiry_date') AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} modern WHERE modern.post_id = p.ID AND modern.meta_key = 'date_expires') LIMIT 1001");
        if (count($values) > 1000) {
            throw new ApiException('Migrate legacy coupon expiry metadata before using state filters or expiry sorting.', 503, 'coupon_expiry_migration_required');
        }
        $cases = '';
        foreach ($values as $value) {
            if ($value === '' || $value === null) {
                continue;
            }
            try {
                $coupon = new \WC_Coupon();
                $coupon->set_date_expires($value);
                $date = $coupon->get_date_expires('edit');
                $timestamp = $date !== null ? $date->getTimestamp() : 0;
            } catch (\Exception) {
                throw new ApiException('Invalid legacy coupon expiry metadata.', 503, 'coupon_expiry_migration_required');
            }
            $cases .= $wpdb->prepare(' WHEN %s THEN %d', $value, $timestamp);
        }
        $fallback = $cases !== '' ? "(CASE $legacy $cases ELSE 0 END)" : '0';
        // Empty modern metadata means unlimited and overrides legacy metadata.
        return "COALESCE(CAST($modern AS SIGNED), $fallback, 0)";
    }
}
