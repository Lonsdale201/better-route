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
            'orderby' => $this->mapSortField($query->sortField),
            'order' => $query->sortDirection,
            'post_type' => 'shop_coupon',
            'post_status' => 'publish',
        ];

        if ($query->code !== null && $query->code !== '') {
            $args['title'] = $query->code;
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
        $this->applyPayload($coupon, $payload);
        $coupon->save();

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
        $this->applyPayload($coupon, $payload);
        $coupon->save();

        return $this->mapCoupon($coupon, $fields);
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
                'amount' => method_exists($coupon, 'get_amount') ? (float) $coupon->get_amount() : 0.0,
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
                'minimum_amount' => method_exists($coupon, 'get_minimum_amount') ? (float) $coupon->get_minimum_amount() : 0.0,
                'maximum_amount' => method_exists($coupon, 'get_maximum_amount') ? (float) $coupon->get_maximum_amount() : 0.0,
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
            $coupon->set_amount((float) $payload['amount']);
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
            $coupon->set_usage_limit((int) $payload['usage_limit']);
        }

        if (array_key_exists('usage_limit_per_user', $payload) && method_exists($coupon, 'set_usage_limit_per_user')) {
            $coupon->set_usage_limit_per_user((int) $payload['usage_limit_per_user']);
        }

        if (array_key_exists('limit_usage_to_x_items', $payload) && method_exists($coupon, 'set_limit_usage_to_x_items')) {
            $value = $payload['limit_usage_to_x_items'];
            $coupon->set_limit_usage_to_x_items(is_numeric($value) ? (int) $value : null);
        }

        if (array_key_exists('individual_use', $payload) && method_exists($coupon, 'set_individual_use')) {
            $coupon->set_individual_use((bool) $payload['individual_use']);
        }

        if (array_key_exists('product_ids', $payload) && method_exists($coupon, 'set_product_ids')) {
            $coupon->set_product_ids($this->parseIntArray($payload['product_ids'], 'product_ids'));
        }

        if (array_key_exists('excluded_product_ids', $payload) && method_exists($coupon, 'set_excluded_product_ids')) {
            $coupon->set_excluded_product_ids($this->parseIntArray($payload['excluded_product_ids'], 'excluded_product_ids'));
        }

        if (array_key_exists('free_shipping', $payload) && method_exists($coupon, 'set_free_shipping')) {
            $coupon->set_free_shipping((bool) $payload['free_shipping']);
        }

        if (array_key_exists('minimum_amount', $payload) && method_exists($coupon, 'set_minimum_amount')) {
            $coupon->set_minimum_amount((float) $payload['minimum_amount']);
        }

        if (array_key_exists('maximum_amount', $payload) && method_exists($coupon, 'set_maximum_amount')) {
            $coupon->set_maximum_amount((float) $payload['maximum_amount']);
        }

        if (array_key_exists('email_restrictions', $payload) && method_exists($coupon, 'set_email_restrictions')) {
            $coupon->set_email_restrictions($this->parseStringArray($payload['email_restrictions'], 'email_restrictions'));
        }

        if (array_key_exists('exclude_sale_items', $payload) && method_exists($coupon, 'set_exclude_sale_items')) {
            $coupon->set_exclude_sale_items((bool) $payload['exclude_sale_items']);
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
    private function parseStringArray(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw $this->validationError([$field => ['must be an array']]);
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return $result;
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
