<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

final class WooCouponService
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'id',
        'code',
        'amount',
        'discount_type',
        'description',
        'date_created',
        'date_modified',
        'date_expires',
        'usage_count',
        'usage_limit',
        'usage_limit_per_user',
        'limit_usage_to_x_items',
        'individual_use',
        'product_ids',
        'excluded_product_ids',
        'free_shipping',
        'minimum_amount',
        'maximum_amount',
        'email_restrictions',
        'exclude_sale_items',
        'meta_data',
    ];

    /** @var list<string> */
    private const LIST_DEFAULT_FIELDS = [
        'id',
        'code',
        'amount',
        'discount_type',
        'date_created',
        'date_expires',
        'usage_count',
        'usage_limit',
    ];

    /** @var list<string> */
    private const GET_DEFAULT_FIELDS = [
        'id',
        'code',
        'amount',
        'discount_type',
        'description',
        'date_created',
        'date_modified',
        'date_expires',
        'usage_count',
        'usage_limit',
        'usage_limit_per_user',
        'limit_usage_to_x_items',
        'individual_use',
        'product_ids',
        'excluded_product_ids',
        'free_shipping',
        'minimum_amount',
        'maximum_amount',
        'email_restrictions',
        'exclude_sale_items',
        'meta_data',
    ];

    /** @var list<string> */
    private const WRITABLE_FIELDS = [
        'code',
        'amount',
        'discount_type',
        'description',
        'date_expires',
        'usage_limit',
        'usage_limit_per_user',
        'limit_usage_to_x_items',
        'individual_use',
        'product_ids',
        'excluded_product_ids',
        'free_shipping',
        'minimum_amount',
        'maximum_amount',
        'email_restrictions',
        'exclude_sale_items',
        'meta_data',
    ];

    /**
     * @return list<string>
     */
    public function allowedFields(): array
    {
        return self::ALLOWED_FIELDS;
    }

    /**
     * @return list<string>
     */
    public function listDefaultFields(): array
    {
        return self::LIST_DEFAULT_FIELDS;
    }

    /**
     * @return list<string>
     */
    public function getDefaultFields(): array
    {
        return self::GET_DEFAULT_FIELDS;
    }

    /**
     * @return array{
     *   items: list<array<string, mixed>>,
     *   total: int,
     *   page: int,
     *   perPage: int
     * }
     */
    public function list(CouponListQuery $query): array
    {
        $this->assertWooFunctions();

        $args = [
            'posts_per_page' => $query->perPage,
            'paged' => $query->page,
            'orderby' => $this->stableSort($query->sortField),
            'order' => $query->sortDirection,
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
        ];

        if ($query->code !== null && $query->code !== '') {
            // Resolve through the coupon lookup so code normalization
            // (wc_format_coupon_code) and the coupon cache apply, instead of a
            // collation-dependent post-title match.
            $couponId = function_exists('wc_get_coupon_id_by_code')
                ? (int) wc_get_coupon_id_by_code($query->code)
                : 0;
            $args['post__in'] = [$couponId > 0 ? $couponId : 0];
        }

        if ($query->search !== null && $query->search !== '') {
            $args['s'] = $query->search;
        }

        $wpQuery = new \WP_Query($args);
        $posts = $wpQuery->posts;
        $total = (int) $wpQuery->found_posts;

        $items = [];
        if (is_array($posts)) {
            foreach ($posts as $post) {
                $postId = ($post instanceof \WP_Post) ? $post->ID : 0;
                if ($postId < 1) {
                    continue;
                }

                $coupon = $this->loadCoupon($postId);
                if ($coupon !== null) {
                    $items[] = $this->mapCoupon($coupon, $query->fields);
                }
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $query->page,
            'perPage' => $query->perPage,
        ];
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function get(int $id, array $fields): ?array
    {
        $this->assertWooFunctions();

        $coupon = $this->loadCoupon($id);
        if ($coupon === null) {
            return null;
        }

        return $this->mapCoupon($coupon, $fields);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    public function create(array $payload, array $fields): array
    {
        $this->assertWooFunctions();
        $this->assertPayloadKeys($payload);

        $code = $payload['code'] ?? null;
        if (!is_string($code) || $code === '') {
            throw $this->validationError(['code' => ['coupon code is required']]);
        }

        $coupon = new \WC_Coupon();
        $this->persistCoupon($coupon, $payload);

        $id = $coupon->get_id();
        if ($id < 1) {
            throw new ApiException('Coupon creation failed.', 500, 'woo_coupon_create_failed');
        }

        return $this->mapCoupon($coupon, $fields);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $payload, array $fields): ?array
    {
        $this->assertWooFunctions();

        $coupon = $this->loadCoupon($id);
        if ($coupon === null) {
            return null;
        }

        $this->assertPayloadKeys($payload);
        $this->persistCoupon($coupon, $payload);

        return $this->mapCoupon($coupon, $fields);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistCoupon(object $coupon, array $payload): void
    {
        try {
            $this->validatePayload($payload);
            $persist = function () use ($coupon, $payload): void {
                $this->assertCouponCodeAvailable($coupon, $payload);
                $this->applyPayload($coupon, $payload);
                if (method_exists($coupon, 'save')) {
                    $coupon->save();
                }
            };

            if (array_key_exists('code', $payload) && is_string($payload['code'])) {
                $this->withCouponCodeLock($payload['code'], $persist);
            } else {
                $persist();
            }
        } catch (\WC_Data_Exception $exception) {
            $code = $exception->getErrorCode();
            throw new ApiException(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Invalid request.',
                400,
                $code !== '' ? $code : 'validation_failed'
            );
        }
    }

    /** @param array<string, mixed> $payload */
    private function validatePayload(array $payload): void
    {
        foreach (['code', 'discount_type', 'description'] as $field) {
            if (array_key_exists($field, $payload) && !is_string($payload[$field])) {
                throw $this->validationError([$field => ['must be a string']]);
            }
        }

        if (array_key_exists('code', $payload) && trim((string) $payload['code']) === '') {
            throw $this->validationError(['code' => ['must be a non-empty string']]);
        }

        if (array_key_exists('date_expires', $payload)
            && $payload['date_expires'] !== null
            && !is_string($payload['date_expires'])
        ) {
            throw $this->validationError(['date_expires' => ['must be a string or null']]);
        }

        if (array_key_exists('amount', $payload)) {
            $this->nonNegativeNumber($payload['amount'], 'amount');
        }
        foreach (['usage_limit', 'usage_limit_per_user'] as $field) {
            if (array_key_exists($field, $payload)) {
                $this->nonNegativeInteger($payload[$field], $field);
            }
        }
        if (array_key_exists('limit_usage_to_x_items', $payload) && $payload['limit_usage_to_x_items'] !== null) {
            $this->nonNegativeInteger($payload['limit_usage_to_x_items'], 'limit_usage_to_x_items');
        }
        foreach (['individual_use', 'free_shipping', 'exclude_sale_items'] as $field) {
            if (array_key_exists($field, $payload)) {
                $this->boolFromMixed($payload[$field], $field);
            }
        }
        foreach (['minimum_amount', 'maximum_amount'] as $field) {
            if (array_key_exists($field, $payload)) {
                $this->nonNegativeNumber($payload[$field], $field);
            }
        }
        if (array_key_exists('product_ids', $payload)) {
            $this->parseIntArray($payload['product_ids'], 'product_ids');
        }
        if (array_key_exists('excluded_product_ids', $payload)) {
            $this->parseIntArray($payload['excluded_product_ids'], 'excluded_product_ids');
        }
        if (array_key_exists('email_restrictions', $payload)) {
            $this->parseStringArray($payload['email_restrictions'], 'email_restrictions');
        }
        if (array_key_exists('meta_data', $payload)) {
            MetaDataHelper::normalizeIncoming($payload['meta_data']);
        }
    }

    public function delete(int $id, bool $force = true): bool
    {
        $this->assertWooFunctions();

        $coupon = $this->loadCoupon($id);
        if ($coupon === null || !method_exists($coupon, 'delete')) {
            return false;
        }

        $deleted = $coupon->delete($force);
        return $deleted !== false;
    }

    /** @param array<string, mixed> $payload */
    private function assertCouponCodeAvailable(object $coupon, array $payload): void
    {
        if (!array_key_exists('code', $payload) || !is_string($payload['code'])) {
            return;
        }

        $currentId = method_exists($coupon, 'get_id') ? (int) $coupon->get_id() : 0;
        $existingId = function_exists('wc_get_coupon_id_by_code')
            ? (int) wc_get_coupon_id_by_code($payload['code'], $currentId)
            : 0;
        if ($existingId > 0) {
            throw new ApiException('A coupon with this code already exists.', 409, 'coupon_exists');
        }
    }

    private function withCouponCodeLock(string $code, callable $callback): mixed
    {
        if (!isset($GLOBALS['wpdb'])
            || !is_object($GLOBALS['wpdb'])
            || !method_exists($GLOBALS['wpdb'], 'prepare')
            || !method_exists($GLOBALS['wpdb'], 'get_var')
        ) {
            return $callback();
        }

        $normalized = function_exists('wc_format_coupon_code')
            ? (string) wc_format_coupon_code($code)
            : strtolower(trim($code));
        $wpdb = $GLOBALS['wpdb'];
        $lockName = 'better_route_coupon_' . sha1($normalized);
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $lockName, 2));
        if ((int) $acquired !== 1) {
            throw new ApiException('Coupon code is being modified.', 409, 'coupon_write_in_progress');
        }

        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function mapCoupon(object $coupon, array $fields): array
    {
        $row = [];

        foreach ($fields as $field) {
            $row[$field] = match ($field) {
                'id' => method_exists($coupon, 'get_id') ? (int) $coupon->get_id() : 0,
                'code' => method_exists($coupon, 'get_code') ? (string) $coupon->get_code() : '',
                'amount' => method_exists($coupon, 'get_amount') ? (string) $coupon->get_amount() : '0',
                'discount_type' => method_exists($coupon, 'get_discount_type') ? (string) $coupon->get_discount_type() : '',
                'description' => method_exists($coupon, 'get_description') ? (string) $coupon->get_description() : '',
                'date_created' => method_exists($coupon, 'get_date_created') ? $this->dateToAtom($coupon->get_date_created()) : null,
                'date_modified' => method_exists($coupon, 'get_date_modified') ? $this->dateToAtom($coupon->get_date_modified()) : null,
                'date_expires' => method_exists($coupon, 'get_date_expires') ? $this->dateToAtom($coupon->get_date_expires()) : null,
                'usage_count' => method_exists($coupon, 'get_usage_count') ? (int) $coupon->get_usage_count() : 0,
                'usage_limit' => method_exists($coupon, 'get_usage_limit') ? (int) $coupon->get_usage_limit() : 0,
                'usage_limit_per_user' => method_exists($coupon, 'get_usage_limit_per_user') ? (int) $coupon->get_usage_limit_per_user() : 0,
                'limit_usage_to_x_items' => method_exists($coupon, 'get_limit_usage_to_x_items') ? $coupon->get_limit_usage_to_x_items() : null,
                'individual_use' => method_exists($coupon, 'get_individual_use') ? (bool) $coupon->get_individual_use() : false,
                'product_ids' => method_exists($coupon, 'get_product_ids') ? $this->intList($coupon->get_product_ids()) : [],
                'excluded_product_ids' => method_exists($coupon, 'get_excluded_product_ids') ? $this->intList($coupon->get_excluded_product_ids()) : [],
                'free_shipping' => method_exists($coupon, 'get_free_shipping') ? (bool) $coupon->get_free_shipping() : false,
                'minimum_amount' => method_exists($coupon, 'get_minimum_amount') ? (string) $coupon->get_minimum_amount() : '0',
                'maximum_amount' => method_exists($coupon, 'get_maximum_amount') ? (string) $coupon->get_maximum_amount() : '0',
                'email_restrictions' => method_exists($coupon, 'get_email_restrictions') ? $this->stringListFromMixed($coupon->get_email_restrictions()) : [],
                'exclude_sale_items' => method_exists($coupon, 'get_exclude_sale_items') ? (bool) $coupon->get_exclude_sale_items() : false,
                'meta_data' => method_exists($coupon, 'get_meta_data') ? MetaDataHelper::serialize($coupon->get_meta_data()) : [],
                default => null,
            };
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayload(object $coupon, array $payload): void
    {
        if (array_key_exists('code', $payload) && method_exists($coupon, 'set_code')) {
            $coupon->set_code((string) $payload['code']);
        }

        if (array_key_exists('amount', $payload) && method_exists($coupon, 'set_amount')) {
            $coupon->set_amount($this->nonNegativeNumber($payload['amount'], 'amount'));
        }

        if (array_key_exists('discount_type', $payload) && method_exists($coupon, 'set_discount_type')) {
            $coupon->set_discount_type((string) $payload['discount_type']);
        }

        if (array_key_exists('description', $payload) && method_exists($coupon, 'set_description')) {
            $coupon->set_description((string) $payload['description']);
        }

        if (array_key_exists('date_expires', $payload) && method_exists($coupon, 'set_date_expires')) {
            $value = $payload['date_expires'];
            $coupon->set_date_expires(is_string($value) && $value !== '' ? $value : null);
        }

        if (array_key_exists('usage_limit', $payload) && method_exists($coupon, 'set_usage_limit')) {
            $coupon->set_usage_limit($this->nonNegativeInteger($payload['usage_limit'], 'usage_limit'));
        }

        if (array_key_exists('usage_limit_per_user', $payload) && method_exists($coupon, 'set_usage_limit_per_user')) {
            $coupon->set_usage_limit_per_user($this->nonNegativeInteger(
                $payload['usage_limit_per_user'],
                'usage_limit_per_user'
            ));
        }

        if (array_key_exists('limit_usage_to_x_items', $payload) && method_exists($coupon, 'set_limit_usage_to_x_items')) {
            $value = $payload['limit_usage_to_x_items'];
            $coupon->set_limit_usage_to_x_items(
                $value === null ? null : $this->nonNegativeInteger($value, 'limit_usage_to_x_items')
            );
        }

        if (array_key_exists('individual_use', $payload) && method_exists($coupon, 'set_individual_use')) {
            $coupon->set_individual_use($this->boolFromMixed($payload['individual_use'], 'individual_use'));
        }

        if (array_key_exists('product_ids', $payload) && method_exists($coupon, 'set_product_ids')) {
            $coupon->set_product_ids($this->parseIntArray($payload['product_ids'], 'product_ids'));
        }

        if (array_key_exists('excluded_product_ids', $payload) && method_exists($coupon, 'set_excluded_product_ids')) {
            $coupon->set_excluded_product_ids($this->parseIntArray($payload['excluded_product_ids'], 'excluded_product_ids'));
        }

        if (array_key_exists('free_shipping', $payload) && method_exists($coupon, 'set_free_shipping')) {
            $coupon->set_free_shipping($this->boolFromMixed($payload['free_shipping'], 'free_shipping'));
        }

        if (array_key_exists('minimum_amount', $payload) && method_exists($coupon, 'set_minimum_amount')) {
            $coupon->set_minimum_amount($this->nonNegativeNumber($payload['minimum_amount'], 'minimum_amount'));
        }

        if (array_key_exists('maximum_amount', $payload) && method_exists($coupon, 'set_maximum_amount')) {
            $coupon->set_maximum_amount($this->nonNegativeNumber($payload['maximum_amount'], 'maximum_amount'));
        }

        if (array_key_exists('email_restrictions', $payload) && method_exists($coupon, 'set_email_restrictions')) {
            $coupon->set_email_restrictions($this->parseStringArray($payload['email_restrictions'], 'email_restrictions'));
        }

        if (array_key_exists('exclude_sale_items', $payload) && method_exists($coupon, 'set_exclude_sale_items')) {
            $coupon->set_exclude_sale_items($this->boolFromMixed($payload['exclude_sale_items'], 'exclude_sale_items'));
        }

        if (array_key_exists('meta_data', $payload)) {
            $metaData = MetaDataHelper::normalizeIncoming($payload['meta_data']);
            MetaDataHelper::applyToTarget($coupon, $metaData);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function assertPayloadKeys(array $payload): void
    {
        if ($payload === []) {
            throw $this->validationError(['payload' => ['at least one field is required']]);
        }

        $unknown = array_values(array_filter(
            array_keys($payload),
            static fn (string $key): bool => !in_array($key, self::WRITABLE_FIELDS, true)
        ));

        if ($unknown === []) {
            return;
        }

        $fieldErrors = [];
        foreach ($unknown as $key) {
            $fieldErrors[$key] = ['field not allowed'];
        }

        throw $this->validationError($fieldErrors);
    }

    /**
     * @return list<int>
     */
    private function parseIntArray(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw $this->validationError([$field => ['must be an array']]);
        }

        $result = [];
        foreach ($value as $index => $item) {
            $result[] = $this->positiveInteger($item, $field . '.' . $index);
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function parseStringArray(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw $this->validationError([$field => ['must be an array']]);
        }

        $result = [];
        foreach ($value as $index => $item) {
            if (!is_string($item) || trim($item) === '') {
                throw $this->validationError([$field . '.' . $index => ['must be a non-empty string']]);
            }

            $result[] = trim($item);
        }

        return $result;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        $integer = $this->integer($value, $field);
        if ($integer < 1) {
            throw $this->validationError([$field => ['must be greater than zero']]);
        }

        return $integer;
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        $integer = $this->integer($value, $field);
        if ($integer < 0) {
            throw $this->validationError([$field => ['must be zero or greater']]);
        }

        return $integer;
    }

    private function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/D', $value) === 1) {
            return (int) $value;
        }

        throw $this->validationError([$field => ['must be an integer']]);
    }

    private function nonNegativeNumber(mixed $value, string $field): string
    {
        if ((!is_int($value) && !is_float($value) && !is_string($value))
            || !is_numeric($value)
            || !is_finite((float) $value)
            || (float) $value < 0
        ) {
            throw $this->validationError([$field => ['must be a non-negative number']]);
        }

        return (string) $value;
    }

    private function boolFromMixed(mixed $value, string $field): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) && ($value === 0 || $value === 1)) {
            return $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['1', 'true', 'yes'], true)) {
                return true;
            }

            if (in_array($normalized, ['0', 'false', 'no'], true)) {
                return false;
            }
        }

        throw $this->validationError([$field => ['must be boolean']]);
    }

    /**
     * @return list<int>
     */
    private function intList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_numeric($item)) {
                $result[] = (int) $item;
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function stringListFromMixed(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function loadCoupon(int $id): ?object
    {
        if (!class_exists('WC_Coupon')) {
            return null;
        }

        try {
            $coupon = new \WC_Coupon($id);
        } catch (\Exception) {
            return null;
        }

        if (!method_exists($coupon, 'get_id') || (int) $coupon->get_id() < 1) {
            return null;
        }

        return $coupon;
    }

    private function mapSortField(string $field): string
    {
        return match ($field) {
            'date_created' => 'date',
            'date_modified' => 'modified',
            'id' => 'ID',
            'code' => 'title',
            default => 'date',
        };
    }

    private function stableSort(string $field): string
    {
        $mapped = $this->mapSortField($field);
        return $mapped === 'ID' ? $mapped : $mapped . ' ID';
    }

    private function dateToAtom(mixed $value): ?string
    {
        if (is_object($value) && method_exists($value, 'date')) {
            return (string) $value->date(DATE_ATOM);
        }

        if (is_object($value) && method_exists($value, 'format')) {
            return (string) $value->format(DATE_ATOM);
        }

        return null;
    }

    private function assertWooFunctions(): void
    {
        if (!class_exists('WC_Coupon')) {
            throw new ApiException('WooCommerce coupon functions are unavailable.', 503, 'woo_unavailable');
        }
    }

    /**
     * @param array<string, list<string>> $fieldErrors
     */
    private function validationError(array $fieldErrors): ApiException
    {
        return new ApiException(
            message: 'Invalid request.',
            status: 400,
            errorCode: 'validation_failed',
            details: ['fieldErrors' => $fieldErrors]
        );
    }
}
