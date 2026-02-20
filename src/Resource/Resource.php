<?php

declare(strict_types=1);

namespace BetterRoute\Resource;

final class Resource
{
    /** @var list<string> */
    private array $allowedActions = [];

    /** @var list<string> */
    private array $fields = [];

    /** @var list<string> */
    private array $filters = [];

    /** @var list<string> */
    private array $sort = [];

    /** @var array<string, mixed> */
    private array $policy = [];

    private ?string $restNamespace = null;
    private ?string $sourceCpt = null;
    private ?string $sourceTable = null;
    private ?string $primaryKey = null;

    private function __construct(
        private readonly string $name
    ) {
    }

    public static function make(string $name): self
    {
        return new self($name);
    }

    public function restNamespace(string $restNamespace): self
    {
        $this->restNamespace = trim($restNamespace, '/');
        return $this;
    }

    public function sourceCpt(string $postType): self
    {
        $this->sourceCpt = $postType;
        return $this;
    }

    public function sourceTable(string $table, string $primaryKey = 'id'): self
    {
        $this->sourceTable = $table;
        $this->primaryKey = $primaryKey;
        return $this;
    }

    /**
     * @param list<string> $actions
     */
    public function allow(array $actions): self
    {
        $this->allowedActions = array_values($actions);
        return $this;
    }

    /**
     * @param list<string> $fields
     */
    public function fields(array $fields): self
    {
        $this->fields = array_values($fields);
        return $this;
    }

    /**
     * @param list<string> $filters
     */
    public function filters(array $filters): self
    {
        $this->filters = array_values($filters);
        return $this;
    }

    /**
     * @param list<string> $sort
     */
    public function sort(array $sort): self
    {
        $this->sort = array_values($sort);
        return $this;
    }

    /**
     * @param array<string, mixed> $policy
     */
    public function policy(array $policy): self
    {
        $this->policy = $policy;
        return $this;
    }

    public function register(): void
    {
        // Implemented in M2/M3.
    }

    /**
     * @return array{
     *   name: string,
     *   restNamespace: string|null,
     *   sourceCpt: string|null,
     *   sourceTable: string|null,
     *   primaryKey: string|null,
     *   allow: list<string>,
     *   fields: list<string>,
     *   filters: list<string>,
     *   sort: list<string>,
     *   policy: array<string, mixed>
     * }
     */
    public function descriptor(): array
    {
        return [
            'name' => $this->name,
            'restNamespace' => $this->restNamespace,
            'sourceCpt' => $this->sourceCpt,
            'sourceTable' => $this->sourceTable,
            'primaryKey' => $this->primaryKey,
            'allow' => $this->allowedActions,
            'fields' => $this->fields,
            'filters' => $this->filters,
            'sort' => $this->sort,
            'policy' => $this->policy,
        ];
    }

    public function name(): string
    {
        return $this->name;
    }
}
