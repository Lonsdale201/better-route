<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Cpt;

use BetterRoute\Http\ApiException;
use DateTimeImmutable;
use InvalidArgumentException;

final class CptListQueryParser
{
    /**
     * @param list<string> $allowedFields
     * @param list<string> $allowedFilters
     * @param list<string> $allowedSort
     * @param array<string, array<string, mixed>|string> $filterSchema
     */
    public function __construct(
        private readonly array $allowedFields,
        private readonly array $allowedFilters,
        private readonly array $allowedSort,
        private readonly int $defaultPerPage = 20,
        private readonly int $maxPerPage = 100,
        private readonly array $filterSchema = [],
        private readonly int $maxOffset = 10000
    ) {
        if ($this->maxOffset < 0) {
            throw new InvalidArgumentException('maxOffset must be greater than or equal to 0.');
        }
    }

    public function parse(mixed $request): CptListQuery
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

        $offset = ($page - 1) * $perPage;
        if ($offset > $this->maxOffset) {
            throw $this->validationError([
                'page' => [sprintf('offset exceeds max %d', $this->maxOffset)],
            ]);
        }

        $filters = [];
        foreach ($this->allowedFilters as $filter) {
            if (array_key_exists($filter, $params)) {
                $filters[$filter] = $this->parseFilterValue($filter, $params[$filter]);
            }
        }

        return new CptListQuery(
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

    private function parseFilterValue(string $filter, mixed $value): mixed
    {
        $rule = $this->normalizeFilterRule($this->filterSchema[$filter] ?? null);
        if ($rule === null) {
            return $value;
        }

        $type = (string) ($rule['type'] ?? 'string');

        return match ($type) {
            'string' => $this->parseStringFilter($filter, $value),
            'int' => $this->parseIntFilter($filter, $value),
            'float' => $this->parseFloatFilter($filter, $value),
            'bool' => $this->parseBoolFilter($filter, $value),
            'date' => $this->parseDateFilter($filter, $value),
            'enum' => $this->parseEnumFilter($filter, $value, $rule['values'] ?? []),
            default => throw $this->validationError([$filter => [sprintf('unsupported filter type %s', $type)]]),
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeFilterRule(mixed $rule): ?array
    {
        if ($rule === null) {
            return null;
        }

        if (is_string($rule) && $rule !== '') {
            return ['type' => $rule];
        }

        return is_array($rule) ? $rule : null;
    }

    private function parseStringFilter(string $filter, mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value) || is_bool($value)) {
            return (string) $value;
        }

        throw $this->validationError([$filter => ['must be a string']]);
    }

    private function parseIntFilter(string $filter, mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw $this->validationError([$filter => ['must be an integer']]);
    }

    private function parseFloatFilter(string $filter, mixed $value): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw $this->validationError([$filter => ['must be numeric']]);
    }

    private function parseBoolFilter(string $filter, mixed $value): bool
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

        throw $this->validationError([$filter => ['must be boolean']]);
    }

    private function parseDateFilter(string $filter, mixed $value): string
    {
        if (!is_string($value) || trim($value) === '') {
            throw $this->validationError([$filter => ['must be a valid date-time string']]);
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (\Exception) {
            throw $this->validationError([$filter => ['must be a valid date-time string']]);
        }

        return $date->format(DATE_ATOM);
    }

    /**
     * @param mixed $rawValues
     */
    private function parseEnumFilter(string $filter, mixed $value, mixed $rawValues): string
    {
        if (!is_array($rawValues)) {
            throw new InvalidArgumentException(sprintf('Enum filter %s requires a values list.', $filter));
        }

        $values = [];
        foreach ($rawValues as $enumValue) {
            if (is_string($enumValue) && $enumValue !== '') {
                $values[] = $enumValue;
            }
        }

        if ($values === []) {
            throw new InvalidArgumentException(sprintf('Enum filter %s requires a non-empty values list.', $filter));
        }

        $candidate = $this->parseStringFilter($filter, $value);
        if (!in_array($candidate, $values, true)) {
            throw $this->validationError([$filter => ['unsupported value']]);
        }

        return $candidate;
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
