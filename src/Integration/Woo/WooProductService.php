<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

final class WooProductService
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'id',
        'name',
        'slug',
        'status',
        'type',
        'sku',
        'price',
        'regular_price',
        'sale_price',
        'date_created',
        'date_modified',
        'catalog_visibility',
        'description',
        'short_description',
        'stock_status',
        'stock_quantity',
        'manage_stock',
        'virtual',
        'downloadable',
        'meta_data',
    ];

    /** @var list<string> */
    private const LIST_DEFAULT_FIELDS = [
        'id',
        'name',
        'slug',
        'status',
        'type',
        'sku',
        'price',
        'stock_status',
        'date_created',
        'date_modified',
    ];

    /** @var list<string> */
    private const GET_DEFAULT_FIELDS = [
        'id',
        'name',
        'slug',
        'status',
        'type',
        'sku',
        'price',
        'regular_price',
        'sale_price',
        'date_created',
        'date_modified',
        'catalog_visibility',
        'description',
        'short_description',
        'stock_status',
        'stock_quantity',
        'manage_stock',
        'virtual',
        'downloadable',
        'meta_data',
    ];

    /** @var list<string> */
    private const WRITABLE_FIELDS = [
        'name',
        'slug',
        'status',
        'type',
        'sku',
        'price',
        'regular_price',
        'sale_price',
        'catalog_visibility',
        'description',
        'short_description',
        'stock_status',
        'stock_quantity',
        'manage_stock',
        'virtual',
        'downloadable',
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
    public function list(ProductListQuery $query): array
    {
        $this->assertWooFunctions();

        $args = [
            'paginate' => true,
            'limit' => $query->perPage,
            'page' => $query->page,
            'orderby' => $this->mapSortField($query->sortField),
            'order' => $query->sortDirection,
            'return' => 'objects',
        ];

        if ($query->status !== []) {
            $args['status'] = $query->status;
        }

        if ($query->type !== null && $query->type !== '') {
            $args['type'] = $query->type;
        }

        if ($query->sku !== null && $query->sku !== '') {
            $args['sku'] = $query->sku;
        }

        if ($query->search !== null && $query->search !== '') {
            $args['search'] = $query->search;
        }

        if ($query->stockStatus !== null && $query->stockStatus !== '') {
            $args['stock_status'] = $query->stockStatus;
        }

        $result = wc_get_products($args);

        $products = [];
        $total = 0;

        if (is_object($result) && isset($result->products) && is_array($result->products)) {
            $products = $result->products;
            $total = isset($result->total) && is_numeric($result->total) ? (int) $result->total : count($products);
        } elseif (is_array($result)) {
            $products = $result;
            $total = count($products);
        }

        $items = [];
        foreach ($products as $product) {
            if (is_object($product)) {
                $items[] = $this->mapProduct($product, $query->fields);
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

        $product = wc_get_product($id);
        if (!is_object($product)) {
            return null;
        }

        return $this->mapProduct($product, $fields);
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

        $type = isset($payload['type']) && is_string($payload['type']) && $payload['type'] !== ''
            ? $payload['type']
            : 'simple';

        if (!function_exists('wc_get_product_object')) {
            throw new ApiException('WooCommerce product factory is unavailable.', 503, 'woo_unavailable');
        }

        $product = wc_get_product_object($type);
        if (!is_object($product)) {
            throw $this->validationError(['type' => ['unsupported product type']]);
        }

        $this->applyPayload($product, $payload, true);
        if (method_exists($product, 'save')) {
            $product->save();
        }

        return $this->mapProduct($product, $fields);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $payload, array $fields): ?array
    {
        $this->assertWooFunctions();

        $product = wc_get_product($id);
        if (!is_object($product)) {
            return null;
        }

        $this->assertPayloadKeys($payload);

        if (isset($payload['type']) && is_string($payload['type']) && method_exists($product, 'get_type')) {
            $currentType = (string) $product->get_type();
            if ($payload['type'] !== '' && $payload['type'] !== $currentType) {
                throw $this->validationError(['type' => ['changing product type is not supported in update']]);
            }
        }

        $this->applyPayload($product, $payload, false);
        if (method_exists($product, 'save')) {
            $product->save();
        }

        return $this->mapProduct($product, $fields);
    }

    public function delete(int $id): bool
    {
        $this->assertWooFunctions();

        $product = wc_get_product($id);
        if (!is_object($product) || !method_exists($product, 'delete')) {
            return false;
        }

        $deleted = $product->delete(true);
        return $deleted !== false;
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function mapProduct(object $product, array $fields): array
    {
        $row = [];

        foreach ($fields as $field) {
            $row[$field] = match ($field) {
                'id' => method_exists($product, 'get_id') ? (int) $product->get_id() : 0,
                'name' => method_exists($product, 'get_name') ? (string) $product->get_name() : '',
                'slug' => method_exists($product, 'get_slug') ? (string) $product->get_slug() : '',
                'status' => method_exists($product, 'get_status') ? (string) $product->get_status() : '',
                'type' => method_exists($product, 'get_type') ? (string) $product->get_type() : '',
                'sku' => method_exists($product, 'get_sku') ? (string) $product->get_sku() : '',
                'price' => method_exists($product, 'get_price') ? (string) $product->get_price() : '',
                'regular_price' => method_exists($product, 'get_regular_price') ? (string) $product->get_regular_price() : '',
                'sale_price' => method_exists($product, 'get_sale_price') ? (string) $product->get_sale_price() : '',
                'date_created' => method_exists($product, 'get_date_created') ? $this->dateToAtom($product->get_date_created()) : null,
                'date_modified' => method_exists($product, 'get_date_modified') ? $this->dateToAtom($product->get_date_modified()) : null,
                'catalog_visibility' => method_exists($product, 'get_catalog_visibility') ? (string) $product->get_catalog_visibility() : '',
                'description' => method_exists($product, 'get_description') ? (string) $product->get_description() : '',
                'short_description' => method_exists($product, 'get_short_description') ? (string) $product->get_short_description() : '',
                'stock_status' => method_exists($product, 'get_stock_status') ? (string) $product->get_stock_status() : '',
                'stock_quantity' => method_exists($product, 'get_stock_quantity') ? $product->get_stock_quantity() : null,
                'manage_stock' => method_exists($product, 'get_manage_stock') ? (bool) $product->get_manage_stock() : false,
                'virtual' => method_exists($product, 'get_virtual') ? (bool) $product->get_virtual() : false,
                'downloadable' => method_exists($product, 'get_downloadable') ? (bool) $product->get_downloadable() : false,
                'meta_data' => method_exists($product, 'get_meta_data') ? MetaDataHelper::serialize($product->get_meta_data()) : [],
                default => null,
            };
        }

        return $row;
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
     * @param array<string, mixed> $payload
     */
    private function applyPayload(object $product, array $payload, bool $isCreate): void
    {
        if (array_key_exists('name', $payload) && method_exists($product, 'set_name')) {
            $product->set_name((string) $payload['name']);
        }

        if (array_key_exists('slug', $payload) && method_exists($product, 'set_slug')) {
            $product->set_slug((string) $payload['slug']);
        }

        if (array_key_exists('status', $payload) && method_exists($product, 'set_status')) {
            $product->set_status((string) $payload['status']);
        }

        if ($isCreate && array_key_exists('type', $payload)) {
            // Product type is selected by factory on create; no setter required here.
        }

        if (array_key_exists('sku', $payload) && method_exists($product, 'set_sku')) {
            $product->set_sku((string) $payload['sku']);
        }

        if (array_key_exists('price', $payload) && method_exists($product, 'set_price')) {
            $product->set_price((string) $payload['price']);
        }

        if (array_key_exists('regular_price', $payload) && method_exists($product, 'set_regular_price')) {
            $product->set_regular_price((string) $payload['regular_price']);
        }

        if (array_key_exists('sale_price', $payload) && method_exists($product, 'set_sale_price')) {
            $product->set_sale_price((string) $payload['sale_price']);
        }

        if (array_key_exists('catalog_visibility', $payload) && method_exists($product, 'set_catalog_visibility')) {
            $product->set_catalog_visibility((string) $payload['catalog_visibility']);
        }

        if (array_key_exists('description', $payload) && method_exists($product, 'set_description')) {
            $product->set_description((string) $payload['description']);
        }

        if (array_key_exists('short_description', $payload) && method_exists($product, 'set_short_description')) {
            $product->set_short_description((string) $payload['short_description']);
        }

        if (array_key_exists('stock_status', $payload) && method_exists($product, 'set_stock_status')) {
            $product->set_stock_status((string) $payload['stock_status']);
        }

        if (array_key_exists('stock_quantity', $payload) && method_exists($product, 'set_stock_quantity')) {
            $quantity = is_numeric($payload['stock_quantity']) ? (int) $payload['stock_quantity'] : null;
            $product->set_stock_quantity($quantity);
        }

        if (array_key_exists('manage_stock', $payload) && method_exists($product, 'set_manage_stock')) {
            $product->set_manage_stock($this->boolFromMixed($payload['manage_stock'], 'manage_stock'));
        }

        if (array_key_exists('virtual', $payload) && method_exists($product, 'set_virtual')) {
            $product->set_virtual($this->boolFromMixed($payload['virtual'], 'virtual'));
        }

        if (array_key_exists('downloadable', $payload) && method_exists($product, 'set_downloadable')) {
            $product->set_downloadable($this->boolFromMixed($payload['downloadable'], 'downloadable'));
        }

        if (array_key_exists('meta_data', $payload)) {
            $metaData = MetaDataHelper::normalizeIncoming($payload['meta_data']);
            MetaDataHelper::applyToTarget($product, $metaData);
        }
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

    private function mapSortField(string $field): string
    {
        return match ($field) {
            'date_created' => 'date',
            'date_modified' => 'modified',
            'id' => 'ID',
            'title' => 'title',
            'price' => 'price',
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

    private function assertWooFunctions(): void
    {
        if (function_exists('wc_get_product') && function_exists('wc_get_products')) {
            return;
        }

        throw new ApiException(
            message: 'WooCommerce product functions are unavailable.',
            status: 503,
            errorCode: 'woo_unavailable'
        );
    }
}
