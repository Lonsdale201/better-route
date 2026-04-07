<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

final class WooCustomerService
{
    /** @var list<string> */
    private const ALLOWED_FIELDS = [
        'id',
        'email',
        'first_name',
        'last_name',
        'display_name',
        'role',
        'username',
        'date_created',
        'date_modified',
        'billing',
        'shipping',
        'is_paying_customer',
        'avatar_url',
        'orders_count',
        'total_spent',
        'meta_data',
    ];

    /** @var list<string> */
    private const LIST_DEFAULT_FIELDS = [
        'id',
        'email',
        'first_name',
        'last_name',
        'display_name',
        'role',
        'date_created',
        'orders_count',
        'total_spent',
    ];

    /** @var list<string> */
    private const GET_DEFAULT_FIELDS = [
        'id',
        'email',
        'first_name',
        'last_name',
        'display_name',
        'role',
        'username',
        'date_created',
        'date_modified',
        'billing',
        'shipping',
        'is_paying_customer',
        'avatar_url',
        'orders_count',
        'total_spent',
        'meta_data',
    ];

    /** @var list<string> */
    private const WRITABLE_FIELDS = [
        'email',
        'first_name',
        'last_name',
        'username',
        'password',
        'billing',
        'shipping',
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
    public function list(CustomerListQuery $query): array
    {
        $this->assertWooFunctions();

        $args = [
            'number' => $query->perPage,
            'paged' => $query->page,
            'orderby' => $this->mapSortField($query->sortField),
            'order' => $query->sortDirection,
        ];

        if ($query->role !== []) {
            $args['role__in'] = $query->role;
        }

        if ($query->email !== null && $query->email !== '') {
            $args['search'] = $query->email;
            $args['search_columns'] = ['user_email'];
        } elseif ($query->search !== null && $query->search !== '') {
            $args['search'] = '*' . $query->search . '*';
        }

        $userQuery = new \WP_User_Query($args);
        $users = $userQuery->get_results();
        $total = $userQuery->get_total();

        $items = [];
        if (is_array($users)) {
            foreach ($users as $user) {
                if (is_object($user)) {
                    $customer = $this->loadCustomer((int) $user->ID);
                    if ($customer !== null) {
                        $items[] = $this->mapCustomer($customer, $query->fields);
                    }
                }
            }
        }

        return [
            'items' => $items,
            'total' => (int) $total,
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

        $customer = $this->loadCustomer($id);
        if ($customer === null) {
            return null;
        }

        return $this->mapCustomer($customer, $fields);
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

        $email = $payload['email'] ?? null;
        if (!is_string($email) || $email === '') {
            throw $this->validationError(['email' => ['email is required']]);
        }

        if (email_exists($email)) {
            throw new ApiException('A customer with this email already exists.', 409, 'customer_exists');
        }

        $customer = new \WC_Customer();
        $this->applyPayload($customer, $payload, true);
        $customer->save();

        $id = $customer->get_id();
        if ($id < 1) {
            throw new ApiException('Customer creation failed.', 500, 'woo_customer_create_failed');
        }

        return $this->mapCustomer($customer, $fields);
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    public function update(int $id, array $payload, array $fields): ?array
    {
        $this->assertWooFunctions();

        $customer = $this->loadCustomer($id);
        if ($customer === null) {
            return null;
        }

        $this->assertPayloadKeys($payload);
        $this->applyPayload($customer, $payload, false);
        $customer->save();

        return $this->mapCustomer($customer, $fields);
    }

    public function delete(int $id): bool
    {
        $this->assertWooFunctions();

        if (!function_exists('get_userdata') || !function_exists('wp_delete_user')) {
            return false;
        }

        $user = get_userdata($id);
        if ($user === false) {
            return false;
        }

        return wp_delete_user($id) !== false;
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function mapCustomer(object $customer, array $fields): array
    {
        $row = [];

        foreach ($fields as $field) {
            $row[$field] = match ($field) {
                'id' => method_exists($customer, 'get_id') ? (int) $customer->get_id() : 0,
                'email' => method_exists($customer, 'get_email') ? (string) $customer->get_email() : '',
                'first_name' => method_exists($customer, 'get_first_name') ? (string) $customer->get_first_name() : '',
                'last_name' => method_exists($customer, 'get_last_name') ? (string) $customer->get_last_name() : '',
                'display_name' => method_exists($customer, 'get_display_name') ? (string) $customer->get_display_name() : '',
                'role' => method_exists($customer, 'get_role') ? (string) $customer->get_role() : '',
                'username' => method_exists($customer, 'get_username') ? (string) $customer->get_username() : '',
                'date_created' => method_exists($customer, 'get_date_created') ? $this->dateToAtom($customer->get_date_created()) : null,
                'date_modified' => method_exists($customer, 'get_date_modified') ? $this->dateToAtom($customer->get_date_modified()) : null,
                'billing' => method_exists($customer, 'get_billing') ? $this->extractAddress($customer, 'billing') : [],
                'shipping' => method_exists($customer, 'get_shipping') ? $this->extractAddress($customer, 'shipping') : [],
                'is_paying_customer' => method_exists($customer, 'get_is_paying_customer') ? (bool) $customer->get_is_paying_customer() : false,
                'avatar_url' => $this->getAvatarUrl($customer),
                'orders_count' => method_exists($customer, 'get_order_count') ? (int) $customer->get_order_count() : 0,
                'total_spent' => method_exists($customer, 'get_total_spent') ? (float) $customer->get_total_spent() : 0.0,
                'meta_data' => method_exists($customer, 'get_meta_data') ? MetaDataHelper::serialize($customer->get_meta_data()) : [],
                default => null,
            };
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function applyPayload(object $customer, array $payload, bool $isCreate): void
    {
        if (array_key_exists('email', $payload) && method_exists($customer, 'set_email')) {
            $customer->set_email((string) $payload['email']);
        }

        if (array_key_exists('first_name', $payload) && method_exists($customer, 'set_first_name')) {
            $customer->set_first_name((string) $payload['first_name']);
        }

        if (array_key_exists('last_name', $payload) && method_exists($customer, 'set_last_name')) {
            $customer->set_last_name((string) $payload['last_name']);
        }

        if (array_key_exists('username', $payload) && $isCreate && method_exists($customer, 'set_username')) {
            $customer->set_username((string) $payload['username']);
        }

        if (array_key_exists('password', $payload) && method_exists($customer, 'set_password')) {
            $customer->set_password((string) $payload['password']);
        }

        if (array_key_exists('billing', $payload)) {
            $this->applyAddress($customer, $payload['billing'], 'billing');
        }

        if (array_key_exists('shipping', $payload)) {
            $this->applyAddress($customer, $payload['shipping'], 'shipping');
        }

        if (array_key_exists('meta_data', $payload)) {
            $metaData = MetaDataHelper::normalizeIncoming($payload['meta_data']);
            MetaDataHelper::applyToTarget($customer, $metaData);
        }
    }

    /**
     * @return array<string, string>
     */
    private function extractAddress(object $customer, string $type): array
    {
        $fields = [
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'email', 'phone',
        ];

        $address = [];
        foreach ($fields as $field) {
            $getter = "get_{$type}_{$field}";
            if (method_exists($customer, $getter)) {
                $address[$field] = (string) $customer->$getter();
            }
        }

        return $address;
    }

    private function applyAddress(object $customer, mixed $value, string $type): void
    {
        if (!is_array($value)) {
            throw $this->validationError([$type => ['must be an object']]);
        }

        $fields = [
            'first_name', 'last_name', 'company', 'address_1', 'address_2',
            'city', 'state', 'postcode', 'country', 'email', 'phone',
        ];

        foreach ($fields as $field) {
            if (array_key_exists($field, $value)) {
                $setter = "set_{$type}_{$field}";
                if (method_exists($customer, $setter)) {
                    $customer->$setter((string) $value[$field]);
                }
            }
        }
    }

    private function getAvatarUrl(object $customer): string
    {
        if (!method_exists($customer, 'get_email')) {
            return '';
        }

        if (!function_exists('get_avatar_url')) {
            return '';
        }

        $url = get_avatar_url((string) $customer->get_email());
        return is_string($url) ? $url : '';
    }

    private function loadCustomer(int $id): ?object
    {
        if (!class_exists('WC_Customer')) {
            return null;
        }

        try {
            $customer = new \WC_Customer($id);
        } catch (\Exception) {
            return null;
        }

        if (!method_exists($customer, 'get_id') || (int) $customer->get_id() < 1) {
            return null;
        }

        return $customer;
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

    private function mapSortField(string $field): string
    {
        return match ($field) {
            'registered_date' => 'registered',
            'id' => 'ID',
            'email' => 'user_email',
            'display_name' => 'display_name',
            default => 'registered',
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
        if (!class_exists('WC_Customer')) {
            throw new ApiException('WooCommerce customer functions are unavailable.', 503, 'woo_unavailable');
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
