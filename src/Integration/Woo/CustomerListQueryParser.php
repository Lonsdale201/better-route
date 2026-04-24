<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

final class CustomerListQueryParser
{
    /** @var list<string> */
    private array $allowedFields;

    /** @var list<string> */
    private array $allowedSort;

    /** @var list<string> */
    private array $defaultFields;

    /**
     * @param list<string> $allowedFields
     * @param list<string> $allowedSort
     * @param list<string>|null $defaultFields
     */
    public function __construct(
        array $allowedFields,
        array $allowedSort = ['registered_date', 'id', 'email', 'display_name'],
        ?array $defaultFields = null,
        private readonly int $defaultPerPage = 20,
        private readonly int $maxPerPage = 100
    ) {
        $this->allowedFields = array_values($allowedFields);
        $this->allowedSort = array_values($allowedSort);
        $this->defaultFields = is_array($defaultFields) && $defaultFields !== []
            ? array_values($defaultFields)
            : $this->allowedFields;
    }

    public function parse(mixed $request): CustomerListQuery
    {
        $params = $this->resolveParams($request);
        $this->assertUnknownParams($params);

        $fields = $this->parseFields($params['fields'] ?? null);
        $role = $this->parseRole($params['role'] ?? null);
        $email = $this->parseOptionalString($params['email'] ?? null, 'email');
        $search = $this->parseOptionalString($params['search'] ?? null, 'search');
        [$sortField, $sortDirection] = $this->parseSort($params['sort'] ?? null);
        $page = $this->parsePositiveInt($params['page'] ?? 1, 'page');
        $perPage = $this->parsePositiveInt($params['per_page'] ?? $this->defaultPerPage, 'per_page');

        if ($perPage > $this->maxPerPage) {
            throw $this->validationError([
                'per_page' => [sprintf('max %d', $this->maxPerPage)],
            ]);
        }

        return new CustomerListQuery(
            fields: $fields,
            role: $role,
            email: $email,
            search: $search,
            sortField: $sortField,
            sortDirection: $sortDirection,
            page: $page,
            perPage: $perPage
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveParams(mixed $request): array
    {
        if (is_object($request) && method_exists($request, 'get_params')) {
            $params = $request->get_params();
            return is_array($params) ? $params : [];
        }

        if (is_array($request)) {
            return $request;
        }

        return [];
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assertUnknownParams(array $params): void
    {
        $allowed = ['fields', 'role', 'email', 'search', 'sort', 'page', 'per_page'];
        $unknown = array_values(array_filter(
            array_keys($params),
            static fn (string $key): bool => !in_array($key, $allowed, true)
        ));

        if ($unknown === []) {
            return;
        }

        $fieldErrors = [];
        foreach ($unknown as $key) {
            $fieldErrors[$key] = ['unknown parameter'];
        }

        throw $this->validationError($fieldErrors);
    }

    /**
     * @return list<string>
     */
    private function parseFields(mixed $rawFields): array
    {
        if ($rawFields === null || $rawFields === '') {
            return $this->defaultFields;
        }

        if (!is_string($rawFields)) {
            throw $this->validationError(['fields' => ['must be a comma separated string']]);
        }

        $fields = array_values(array_filter(
            array_map('trim', explode(',', $rawFields)),
            static fn (string $field): bool => $field !== ''
        ));

        if ($fields === []) {
            return $this->defaultFields;
        }

        $invalid = array_values(array_filter(
            $fields,
            fn (string $field): bool => !in_array($field, $this->allowedFields, true)
        ));

        if ($invalid !== []) {
            $fieldErrors = [];
            foreach ($invalid as $field) {
                $fieldErrors[$field] = ['field not allowed'];
            }

            throw $this->validationError($fieldErrors);
        }

        return $fields;
    }

    /**
     * @return list<string>
     */
    private function parseRole(mixed $rawRole): array
    {
        if ($rawRole === null || $rawRole === '') {
            return ['customer'];
        }

        if (is_string($rawRole)) {
            $roles = array_values(array_filter(
                array_map('trim', explode(',', $rawRole)),
                static fn (string $value): bool => $value !== ''
            ));
            return $this->assertCustomerRoles($roles);
        }

        if (is_array($rawRole)) {
            $values = [];
            foreach ($rawRole as $role) {
                if (is_string($role) && trim($role) !== '') {
                    $values[] = trim($role);
                }
            }

            return $this->assertCustomerRoles(array_values($values));
        }

        throw $this->validationError(['role' => ['must be string or array']]);
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    private function assertCustomerRoles(array $roles): array
    {
        if ($roles === []) {
            return ['customer'];
        }

        $invalid = array_values(array_filter(
            $roles,
            static fn (string $role): bool => $role !== 'customer'
        ));

        if ($invalid !== []) {
            throw $this->validationError([
                'role' => ['only customer role is allowed'],
            ]);
        }

        return $roles;
    }

    private function parseOptionalString(mixed $value, string $field): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        throw $this->validationError([$field => ['must be a string']]);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function parseSort(mixed $rawSort): array
    {
        if ($rawSort === null || $rawSort === '') {
            return ['registered_date', 'DESC'];
        }

        if (!is_string($rawSort)) {
            throw $this->validationError(['sort' => ['must be string']]);
        }

        $direction = str_starts_with($rawSort, '-') ? 'DESC' : 'ASC';
        $field = ltrim($rawSort, '-');

        if (!in_array($field, $this->allowedSort, true)) {
            throw $this->validationError([
                'sort' => ['unsupported sort field'],
            ]);
        }

        return [$field, $direction];
    }

    private function parsePositiveInt(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $int = $value;
        } elseif (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;
        } else {
            throw $this->validationError([$field => ['must be a positive integer']]);
        }

        if ($int < 1) {
            throw $this->validationError([$field => ['must be greater than 0']]);
        }

        return $int;
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
