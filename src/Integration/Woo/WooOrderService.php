<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;
use RuntimeException;
use Throwable;

final class WooOrderService
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'id',
        'number',
        'status',
        'currency',
        'total',
        'total_tax',
        'customer_id',
        'billing_email',
        'payment_method',
        'payment_method_title',
        'date_created',
        'date_modified',
        'billing',
        'shipping',
        'customer_note',
        'meta_data',
        'line_items',
    ];

    /** @var list<string> */
    private const LIST_DEFAULT_FIELDS = [
        'id',
        'number',
        'status',
        'currency',
        'total',
        'customer_id',
        'date_created',
        'date_modified',
    ];

    /** @var list<string> */
    private const GET_DEFAULT_FIELDS = [
        'id',
        'number',
        'status',
        'currency',
        'total',
        'total_tax',
        'customer_id',
        'billing_email',
        'payment_method',
        'payment_method_title',
        'date_created',
        'date_modified',
        'billing',
        'shipping',
        'customer_note',
        'meta_data',
        'line_items',
    ];

    /** @var list<string> */
    private const WRITABLE_FIELDS = [
        'status',
        'customer_id',
        'currency',
        'payment_method',
        'payment_method_title',
        'billing',
        'shipping',
        'customer_note',
        'meta_data',
        'line_items',
        'set_paid',
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
    public function list(OrderListQuery $query): array
    {
        $this->assertWooFunctions();

        $args = [
            'paginate' => true,
            'limit' => $query->perPage,
            'page' => $query->page,
            'return' => 'objects',
            'orderby' => $this->stableSort($query->sortField),
            'order' => $query->sortDirection,
        ];

        if ($query->status !== []) {
            $args['status'] = $query->status;
        }

        if ($query->customerId !== null) {
            $args['customer_id'] = $query->customerId;
        }

        if ($query->search !== null && $query->search !== '') {
            // WC_Order_Query / HPOS OrdersTableQuery has no "search" var; "s" is
            // the supported search key (the "*term*" wildcard form is WP_User_Query-only).
            $args['s'] = $query->search;
        }

        $result = wc_get_orders($args);

        $orders = [];
        $total = 0;

        if (is_object($result) && isset($result->orders) && is_array($result->orders)) {
            $orders = $result->orders;
            $total = isset($result->total) && is_numeric($result->total) ? (int) $result->total : count($orders);
        } elseif (is_array($result)) {
            $orders = $result;
            $total = count($orders);
        }

        $items = [];
        foreach ($orders as $order) {
            if (is_object($order)) {
                $items[] = $this->mapOrder($order, $query->fields);
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

        $order = wc_get_order($id);
        if (!is_object($order)) {
            return null;
        }

        return $this->mapOrder($order, $fields);
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
        $this->validatePayload($payload, true, null);

        $order = $this->transactional(function () use ($payload): object {
            $order = wc_create_order();
            if ($this->isWpError($order)) {
                throw new ApiException((string) $order->get_error_message(), 400, 'woo_order_create_failed');
            }

            if (!is_object($order)) {
                throw new ApiException('Order creation failed.', 500, 'woo_order_create_failed');
            }

            $this->persistPayload($order, $payload, true);
            return $order;
        });

        return $this->mapOrder($order, $fields);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $payload, array $fields): ?array
    {
        $this->assertWooFunctions();

        $order = wc_get_order($id);
        if (!is_object($order)) {
            return null;
        }

        $this->assertPayloadKeys($payload);
        $this->validatePayload($payload, false, $order);
        $this->transactional(function () use ($order, $payload): void {
            $this->persistPayload($order, $payload, false);
        });

        return $this->mapOrder($order, $fields);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function persistPayload(object $order, array $payload, bool $isCreate): void
    {
        try {
            $this->applyPayload($order, $payload, $isCreate);
            if (method_exists($order, 'save')) {
                $order->save();
            }
        } catch (\WC_Data_Exception $exception) {
            // WooCommerce CRUD setters signal invalid input via WC_Data_Exception;
            // surface it as a 400 rather than letting it fall through to a 500.
            $code = $exception->getErrorCode();
            throw new ApiException(
                $exception->getMessage() !== '' ? $exception->getMessage() : 'Invalid request.',
                400,
                $code !== '' ? $code : 'validation_failed'
            );
        }
    }

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    private function transactional(callable $operation): mixed
    {
        if (!function_exists('wc_transaction_query')) {
            throw new RuntimeException('WooCommerce transaction API is unavailable.');
        }

        wc_transaction_query('start');

        try {
            $result = $operation();
            wc_transaction_query('commit');
            return $result;
        } catch (Throwable $throwable) {
            wc_transaction_query('rollback');
            throw $throwable;
        }
    }

    public function delete(int $id, bool $force = true): bool
    {
        $this->assertWooFunctions();

        $order = wc_get_order($id);
        if (!is_object($order) || !method_exists($order, 'delete')) {
            return false;
        }

        $deleted = $order->delete($force);
        return $deleted !== false;
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function mapOrder(object $order, array $fields): array
    {
        $row = [];

        foreach ($fields as $field) {
            $row[$field] = match ($field) {
                'id' => method_exists($order, 'get_id') ? (int) $order->get_id() : 0,
                'number' => method_exists($order, 'get_order_number') ? (string) $order->get_order_number() : '',
                'status' => method_exists($order, 'get_status') ? (string) $order->get_status() : '',
                'currency' => method_exists($order, 'get_currency') ? (string) $order->get_currency() : '',
                'total' => method_exists($order, 'get_total') ? (string) $order->get_total() : '0',
                'total_tax' => method_exists($order, 'get_total_tax') ? (string) $order->get_total_tax() : '0',
                'customer_id' => method_exists($order, 'get_customer_id') ? (int) $order->get_customer_id() : 0,
                'billing_email' => method_exists($order, 'get_billing_email') ? (string) $order->get_billing_email() : '',
                'payment_method' => method_exists($order, 'get_payment_method') ? (string) $order->get_payment_method() : '',
                'payment_method_title' => method_exists($order, 'get_payment_method_title') ? (string) $order->get_payment_method_title() : '',
                'date_created' => method_exists($order, 'get_date_created') ? $this->dateToAtom($order->get_date_created()) : null,
                'date_modified' => method_exists($order, 'get_date_modified') ? $this->dateToAtom($order->get_date_modified()) : null,
                'billing' => method_exists($order, 'get_address') ? $order->get_address('billing') : [],
                'shipping' => method_exists($order, 'get_address') ? $order->get_address('shipping') : [],
                'customer_note' => method_exists($order, 'get_customer_note') ? (string) $order->get_customer_note() : '',
                'meta_data' => method_exists($order, 'get_meta_data') ? MetaDataHelper::serialize($order->get_meta_data()) : [],
                'line_items' => $this->mapLineItems($order),
                default => null,
            };
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayload(object $order, array $payload, bool $isCreate): void
    {
        if (array_key_exists('status', $payload) && method_exists($order, 'set_status')) {
            $order->set_status((string) $payload['status']);
        }

        if (array_key_exists('customer_id', $payload) && method_exists($order, 'set_customer_id')) {
            $customerId = is_numeric($payload['customer_id']) ? (int) $payload['customer_id'] : 0;
            if ($customerId < 0) {
                throw $this->validationError(['customer_id' => ['must be greater than or equal to 0']]);
            }

            $order->set_customer_id($customerId);
        }

        if (array_key_exists('currency', $payload) && method_exists($order, 'set_currency')) {
            $order->set_currency((string) $payload['currency']);
        }

        if (array_key_exists('payment_method', $payload) && method_exists($order, 'set_payment_method')) {
            $order->set_payment_method((string) $payload['payment_method']);
        }

        if (array_key_exists('payment_method_title', $payload) && method_exists($order, 'set_payment_method_title')) {
            $order->set_payment_method_title((string) $payload['payment_method_title']);
        }

        if (array_key_exists('billing', $payload) && method_exists($order, 'set_address')) {
            $order->set_address($this->normalizeAddress($payload['billing'], 'billing'), 'billing');
        }

        if (array_key_exists('shipping', $payload) && method_exists($order, 'set_address')) {
            $order->set_address($this->normalizeAddress($payload['shipping'], 'shipping'), 'shipping');
        }

        if (array_key_exists('customer_note', $payload) && method_exists($order, 'set_customer_note')) {
            $order->set_customer_note((string) $payload['customer_note']);
        }

        if (array_key_exists('meta_data', $payload)) {
            $metaData = MetaDataHelper::normalizeIncoming($payload['meta_data']);
            MetaDataHelper::applyToTarget($order, $metaData);
        }

        if (array_key_exists('line_items', $payload)) {
            $this->applyLineItems($order, $payload['line_items'], !$isCreate);

            if (method_exists($order, 'calculate_totals')) {
                $order->calculate_totals(true);
            }
        }

        if (($payload['set_paid'] ?? false) === true && method_exists($order, 'payment_complete')) {
            $order->payment_complete();
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
     * @return array<string, string>
     */
    private function normalizeAddress(mixed $value, string $field): array
    {
        if (!is_array($value)) {
            throw $this->validationError([$field => ['must be an object']]);
        }

        $allowed = [
            'first_name',
            'last_name',
            'company',
            'address_1',
            'address_2',
            'city',
            'state',
            'postcode',
            'country',
            'email',
            'phone',
        ];

        $unknown = array_values(array_diff(array_keys($value), $allowed));
        if ($unknown !== []) {
            $errors = [];
            foreach ($unknown as $key) {
                $errors[$field . '.' . $key] = ['field not allowed'];
            }
            throw $this->validationError($errors);
        }

        $result = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $value)) {
                if (!is_string($value[$key])) {
                    throw $this->validationError([$field . '.' . $key => ['must be a string']]);
                }
                $result[$key] = $value[$key];
            }
        }

        return $result;
    }

    private function applyLineItems(object $order, mixed $value, bool $replaceExisting): void
    {
        if (!is_array($value)) {
            throw $this->validationError(['line_items' => ['must be an array']]);
        }

        if ($replaceExisting && $this->orderStockWasReduced($order)) {
            // Removing/re-adding line items on a stock-reduced order would drop
            // the _reduced_stock markers without restoring inventory (and new
            // items would never reduce), silently corrupting stock in both
            // directions. Refuse — edit items only before stock reduction, or
            // adjust the order through status transitions.
            throw new ApiException(
                'Line items cannot be modified on an order that has already reduced stock.',
                409,
                'woo_line_items_locked'
            );
        }

        if ($replaceExisting && method_exists($order, 'get_items') && method_exists($order, 'remove_item')) {
            $existing = $order->get_items('line_item');
            if (is_array($existing)) {
                foreach ($existing as $itemId => $item) {
                    $resolvedId = is_numeric($itemId) ? (int) $itemId : 0;
                    if ($resolvedId < 1 && is_object($item) && method_exists($item, 'get_id')) {
                        $resolvedId = (int) $item->get_id();
                    }

                    if ($resolvedId > 0) {
                        $order->remove_item($resolvedId);
                    }
                }
            }
        }

        foreach ($value as $index => $itemData) {
            if (!is_array($itemData)) {
                throw $this->validationError(['line_items.' . $index => ['must be an object']]);
            }

            $productId = isset($itemData['product_id']) && is_numeric($itemData['product_id'])
                ? (int) $itemData['product_id']
                : 0;
            if ($productId < 1) {
                throw $this->validationError(['line_items.' . $index . '.product_id' => ['must be a positive integer']]);
            }

            $product = wc_get_product($productId);
            if (!is_object($product)) {
                throw $this->validationError(['line_items.' . $index . '.product_id' => ['product not found']]);
            }

            $quantity = isset($itemData['quantity']) && is_numeric($itemData['quantity'])
                ? (int) $itemData['quantity']
                : 1;
            if ($quantity < 1) {
                throw $this->validationError(['line_items.' . $index . '.quantity' => ['must be greater than 0']]);
            }

            // For a variation, add the actual variation product so add_product()
            // derives price, name, tax class and attributes from the variation —
            // not the parent (which would record the parent's price/name).
            $productToAdd = $product;
            if (isset($itemData['variation_id']) && is_numeric($itemData['variation_id'])) {
                $variationId = (int) $itemData['variation_id'];
                if ($variationId > 0) {
                    $variation = wc_get_product($variationId);
                    if (
                        !is_object($variation)
                        || !method_exists($variation, 'get_parent_id')
                        || (int) $variation->get_parent_id() !== $productId
                    ) {
                        throw $this->validationError([
                            'line_items.' . $index . '.variation_id' => ['must be a variation of the given product'],
                        ]);
                    }

                    $productToAdd = $variation;
                }
            }

            $itemId = method_exists($order, 'add_product') ? $order->add_product($productToAdd, $quantity) : false;
            if (!is_numeric($itemId) || (int) $itemId < 1) {
                throw new ApiException('Unable to add line item.', 409, 'woo_line_item_add_failed');
            }

            $item = method_exists($order, 'get_item') ? $order->get_item((int) $itemId) : null;
            if (!is_object($item)) {
                continue;
            }

            if (isset($itemData['subtotal']) && is_numeric($itemData['subtotal']) && method_exists($item, 'set_subtotal')) {
                $item->set_subtotal($this->nonNegativeNumber(
                    $itemData['subtotal'],
                    'line_items.' . $index . '.subtotal'
                ));
            }

            if (isset($itemData['total']) && is_numeric($itemData['total']) && method_exists($item, 'set_total')) {
                $item->set_total($this->nonNegativeNumber(
                    $itemData['total'],
                    'line_items.' . $index . '.total'
                ));
            }

            if (array_key_exists('meta_data', $itemData)) {
                $metaData = MetaDataHelper::normalizeIncoming($itemData['meta_data'], 'line_items.' . $index . '.meta_data');
                MetaDataHelper::applyToTarget($item, $metaData);
            }

            if (method_exists($item, 'save')) {
                $item->save();
            }
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validatePayload(array $payload, bool $isCreate, ?object $order): void
    {
        foreach (['status', 'currency', 'payment_method', 'payment_method_title', 'customer_note'] as $field) {
            if (array_key_exists($field, $payload) && !is_string($payload[$field])) {
                throw $this->validationError([$field => ['must be a string']]);
            }
        }

        if (array_key_exists('customer_id', $payload)) {
            $customerId = $this->nonNegativeInt($payload['customer_id'], 'customer_id');
            if ($customerId > 0 && function_exists('get_userdata') && get_userdata($customerId) === false) {
                throw $this->validationError(['customer_id' => ['customer not found']]);
            }
        }

        foreach (['billing', 'shipping'] as $addressField) {
            if (array_key_exists($addressField, $payload)) {
                $this->normalizeAddress($payload[$addressField], $addressField);
            }
        }

        if (array_key_exists('meta_data', $payload)) {
            MetaDataHelper::normalizeIncoming($payload['meta_data']);
        }

        if (array_key_exists('set_paid', $payload) && !is_bool($payload['set_paid'])) {
            throw $this->validationError(['set_paid' => ['must be boolean']]);
        }

        if (array_key_exists('line_items', $payload)) {
            if (!$isCreate && $order !== null && $this->orderStockWasReduced($order)) {
                throw new ApiException(
                    'Line items cannot be modified on an order that has already reduced stock.',
                    409,
                    'woo_line_items_locked'
                );
            }

            $this->validateLineItems($payload['line_items']);
        }
    }

    private function validateLineItems(mixed $value): void
    {
        if (!is_array($value)) {
            throw $this->validationError(['line_items' => ['must be an array']]);
        }

        $allowedKeys = ['product_id', 'variation_id', 'quantity', 'subtotal', 'total', 'meta_data'];

        foreach ($value as $index => $itemData) {
            $prefix = 'line_items.' . $index;
            if (!is_array($itemData) || array_is_list($itemData)) {
                throw $this->validationError([$prefix => ['must be an object']]);
            }

            $unknown = array_values(array_diff(array_keys($itemData), $allowedKeys));
            if ($unknown !== []) {
                $errors = [];
                foreach ($unknown as $field) {
                    $errors[$prefix . '.' . $field] = ['field not allowed'];
                }
                throw $this->validationError($errors);
            }

            $productId = $this->positiveInt($itemData['product_id'] ?? null, $prefix . '.product_id');
            $product = wc_get_product($productId);
            if (!is_object($product)) {
                throw $this->validationError([$prefix . '.product_id' => ['product not found']]);
            }

            if (array_key_exists('quantity', $itemData)) {
                $this->positiveInt($itemData['quantity'], $prefix . '.quantity');
            }

            if (array_key_exists('variation_id', $itemData)) {
                $variationId = $this->nonNegativeInt($itemData['variation_id'], $prefix . '.variation_id');
                if ($variationId > 0) {
                    $variation = wc_get_product($variationId);
                    if (
                        !is_object($variation)
                        || !method_exists($variation, 'get_parent_id')
                        || (int) $variation->get_parent_id() !== $productId
                    ) {
                        throw $this->validationError([
                            $prefix . '.variation_id' => ['must be a variation of the given product'],
                        ]);
                    }
                }
            }

            foreach (['subtotal', 'total'] as $amountField) {
                if (array_key_exists($amountField, $itemData)) {
                    $this->nonNegativeNumber($itemData[$amountField], $prefix . '.' . $amountField);
                }
            }

            if (array_key_exists('meta_data', $itemData)) {
                MetaDataHelper::normalizeIncoming($itemData['meta_data'], $prefix . '.meta_data');
            }
        }
    }

    private function positiveInt(mixed $value, string $field): int
    {
        $integer = $this->integer($value, $field);
        if ($integer < 1) {
            throw $this->validationError([$field => ['must be a positive integer']]);
        }

        return $integer;
    }

    private function nonNegativeInt(mixed $value, string $field): int
    {
        $integer = $this->integer($value, $field);
        if ($integer < 0) {
            throw $this->validationError([$field => ['must be greater than or equal to 0']]);
        }

        return $integer;
    }

    private function integer(mixed $value, string $field): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
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

    private function orderStockWasReduced(object $order): bool
    {
        if (!method_exists($order, 'get_meta')) {
            return false;
        }

        $reduced = $order->get_meta('_order_stock_reduced');

        return $reduced === true
            || $reduced === 1
            || (is_string($reduced) && in_array(strtolower(trim($reduced)), ['1', 'yes', 'true'], true));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function mapLineItems(object $order): array
    {
        if (!method_exists($order, 'get_items')) {
            return [];
        }

        $items = $order->get_items('line_item');
        if (!is_array($items)) {
            return [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            $result[] = [
                'id' => method_exists($item, 'get_id') ? (int) $item->get_id() : 0,
                'product_id' => method_exists($item, 'get_product_id') ? (int) $item->get_product_id() : 0,
                'variation_id' => method_exists($item, 'get_variation_id') ? (int) $item->get_variation_id() : 0,
                'name' => method_exists($item, 'get_name') ? (string) $item->get_name() : '',
                'quantity' => method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 0,
                'subtotal' => method_exists($item, 'get_subtotal') ? (string) $item->get_subtotal() : '0',
                'total' => method_exists($item, 'get_total') ? (string) $item->get_total() : '0',
                'meta_data' => method_exists($item, 'get_meta_data') ? MetaDataHelper::serialize($item->get_meta_data()) : [],
            ];
        }

        return $result;
    }

    private function mapSortField(string $field): string
    {
        return match ($field) {
            'date_created' => 'date',
            'date_modified' => 'modified',
            'id' => 'ID',
            'total' => 'total',
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

    private function isWpError(mixed $value): bool
    {
        return class_exists('WP_Error') && $value instanceof \WP_Error;
    }

    private function assertWooFunctions(): void
    {
        if (!function_exists('wc_get_order') || !function_exists('wc_get_orders') || !function_exists('wc_create_order')) {
            throw new ApiException('WooCommerce is unavailable.', 503, 'woo_unavailable');
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
