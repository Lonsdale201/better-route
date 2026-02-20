<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Table;

use BetterRoute\Http\ApiException;

final class TableListQueryParser
{
    /**
     * @param list<string> $allowedFields
     * @param list<string> $allowedFilters
     * @param list<string> $allowedSort
     */
    public function __construct(
        private readonly array $allowedFields,
        private readonly array $allowedFilters,
        private readonly array $allowedSort,
        private readonly int $defaultPerPage = 20,
        private readonly int $maxPerPage = 100
    ) {
    }

    public function parse(mixed $request): TableListQuery
    {
        $params = $this->resolveParams($request);
        $this->assertUnknownParams($params);

        $fields = $this->parseFields($params['fields'] ?? null);
        [$sortField, $sortDirection] = $this->parseSort($params['sort'] ?? null);
        $page = $this->parsePositiveInt($params['page'] ?? 1, 'page');
        $perPage = $this->parsePositiveInt($params['per_page'] ?? $this->defaultPerPage, 'per_page');

        if ($perPage > $this->maxPerPage) {
            throw $this->validationError([
                'per_page' => [sprintf('max %d', $this->maxPerPage)],
            ]);
        }

        $filters = [];
        foreach ($this->allowedFilters as $filter) {
            if (array_key_exists($filter, $params)) {
                $filters[$filter] = $params[$filter];
            }
        }

        return new TableListQuery(
            fields: $fields,
            filters: $filters,
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
        $allowed = array_merge($this->allowedFilters, ['fields', 'sort', 'page', 'per_page']);
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
            return $this->allowedFields;
        }

        if (!is_string($rawFields)) {
            throw $this->validationError(['fields' => ['must be a comma separated string']]);
        }

        $fields = array_values(array_filter(array_map('trim', explode(',', $rawFields)), static fn (string $f): bool => $f !== ''));
        if ($fields === []) {
            return $this->allowedFields;
        }

        $invalid = array_values(array_filter($fields, fn (string $field): bool => !in_array($field, $this->allowedFields, true)));
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
     * @return array{0: string|null, 1: string}
     */
    private function parseSort(mixed $rawSort): array
    {
        if ($rawSort === null || $rawSort === '') {
            return [null, 'ASC'];
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
