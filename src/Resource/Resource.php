<?php

declare(strict_types=1);

namespace BetterRoute\Resource;

use BetterRoute\Http\ApiException;
use BetterRoute\Resource\Cpt\CptListQueryParser;
use BetterRoute\Resource\Cpt\CptRepositoryInterface;
use BetterRoute\Resource\Cpt\WordPressCptRepository;
use BetterRoute\Resource\Table\TableListQueryParser;
use BetterRoute\Resource\Table\TableRepositoryInterface;
use BetterRoute\Resource\Table\WordPressTableRepository;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\Router;
use InvalidArgumentException;

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
    private ?CptRepositoryInterface $cptRepository = null;
    private ?TableRepositoryInterface $tableRepository = null;

    /** @var list<array{
     *   namespace: string,
     *   method: string,
     *   path: string,
     *   args: array<string, mixed>,
     *   meta: array<string, mixed>
     * }>
     */
    private array $registeredContracts = [];

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

    public function usingCptRepository(CptRepositoryInterface $repository): self
    {
        $this->cptRepository = $repository;
        return $this;
    }

    public function usingTableRepository(TableRepositoryInterface $repository): self
    {
        $this->tableRepository = $repository;
        return $this;
    }

    public function register(?DispatcherInterface $dispatcher = null): void
    {
        if ($this->sourceCpt !== null) {
            $this->registerCpt($dispatcher);
            return;
        }

        if ($this->sourceTable !== null) {
            $this->registerTable($dispatcher);
            return;
        }

        throw new InvalidArgumentException('Resource source is required (sourceCpt or sourceTable).');
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

    /**
     * @return list<array{
     *   namespace: string,
     *   method: string,
     *   path: string,
     *   args: array<string, mixed>,
     *   meta: array<string, mixed>
     * }>
     */
    public function contracts(bool $openApiOnly = false): array
    {
        if (!$openApiOnly) {
            return $this->registeredContracts;
        }

        return array_values(array_filter(
            $this->registeredContracts,
            static fn (array $contract): bool => (bool) ($contract['meta']['openapi']['include'] ?? true)
        ));
    }

    public function name(): string
    {
        return $this->name;
    }

    private function registerCpt(?DispatcherInterface $dispatcher): void
    {
        $namespace = $this->parseRestNamespace($this->requireString($this->restNamespace, 'restNamespace is required.'));
        $router = Router::make($namespace['vendor'], $namespace['version']);
        $repository = $this->cptRepository ?? new WordPressCptRepository();
        $queryParser = new CptListQueryParser(
            allowedFields: $this->fields !== [] ? $this->fields : ['id', 'title', 'slug', 'excerpt', 'date', 'status'],
            allowedFilters: $this->filters,
            allowedSort: $this->sort !== [] ? $this->sort : ['date', 'id']
        );
        $postType = $this->requireString($this->sourceCpt, 'sourceCpt is required.');
        $allowed = $this->allowedActions !== [] ? $this->allowedActions : ['list', 'get'];

        if (in_array('list', $allowed, true)) {
            $router->get('/' . $this->name, function (mixed $request) use ($repository, $queryParser, $postType): array {
                $query = $queryParser->parse($request);
                $result = $repository->list($postType, $query);

                return [
                    'data' => $result['items'],
                    'meta' => [
                        'page' => $result['page'],
                        'perPage' => $result['perPage'],
                        'total' => $result['total'],
                    ],
                ];
            })->args($this->listRouteArgs())
                ->meta($this->resourceRouteMeta(
                    action: 'list',
                    parameters: $this->listQueryParameters(),
                    responseSchema: '#/components/schemas/' . $this->resourceSchemaBase() . 'ListResponse'
                ));
        }

        if (in_array('get', $allowed, true)) {
            $router->get('/' . $this->name . '/(?P<id>\d+)', function (mixed $request) use ($repository, $postType): array {
                $id = $this->readId($request);
                $fields = $this->fields !== [] ? $this->fields : ['id', 'title', 'slug', 'excerpt', 'date', 'status'];
                $item = $repository->get($postType, $id, $fields);

                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return $item;
            })->args([
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ])->meta($this->resourceRouteMeta(
                action: 'get',
                parameters: [[
                    'in' => 'path',
                    'name' => 'id',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ]],
                responseSchema: '#/components/schemas/' . $this->resourceSchemaBase()
            ));
        }

        $router->register($dispatcher);
        $this->registeredContracts = $router->contracts();
    }

    private function registerTable(?DispatcherInterface $dispatcher): void
    {
        $namespace = $this->parseRestNamespace($this->requireString($this->restNamespace, 'restNamespace is required.'));
        $router = Router::make($namespace['vendor'], $namespace['version']);
        $repository = $this->tableRepository ?? new WordPressTableRepository();
        $table = $this->requireString($this->sourceTable, 'sourceTable is required.');
        $primaryKey = $this->requireString($this->primaryKey, 'primaryKey is required.');
        $fields = $this->fields;
        if ($fields === []) {
            throw new InvalidArgumentException('fields are required for sourceTable resources.');
        }

        $queryParser = new TableListQueryParser(
            allowedFields: $fields,
            allowedFilters: $this->filters,
            allowedSort: $this->sort !== [] ? $this->sort : [$primaryKey]
        );

        $allowed = $this->allowedActions !== [] ? $this->allowedActions : ['list', 'get'];

        if (in_array('list', $allowed, true)) {
            $router->get('/' . $this->name, function (mixed $request) use ($repository, $queryParser, $table, $primaryKey): array {
                $query = $queryParser->parse($request);
                $result = $repository->list($table, $primaryKey, $query);

                return [
                    'data' => $result['items'],
                    'meta' => [
                        'page' => $result['page'],
                        'perPage' => $result['perPage'],
                        'total' => $result['total'],
                    ],
                ];
            })->args($this->listRouteArgs())
                ->meta($this->resourceRouteMeta(
                    action: 'list',
                    parameters: $this->listQueryParameters(),
                    responseSchema: '#/components/schemas/' . $this->resourceSchemaBase() . 'ListResponse'
                ));
        }

        if (in_array('get', $allowed, true)) {
            $router->get('/' . $this->name . '/(?P<id>\d+)', function (mixed $request) use ($repository, $table, $primaryKey, $fields): array {
                $id = $this->readId($request);
                $item = $repository->get($table, $primaryKey, $id, $fields);

                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return $item;
            })->args([
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                ],
            ])->meta($this->resourceRouteMeta(
                action: 'get',
                parameters: [[
                    'in' => 'path',
                    'name' => 'id',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ]],
                responseSchema: '#/components/schemas/' . $this->resourceSchemaBase()
            ));
        }

        $router->register($dispatcher);
        $this->registeredContracts = $router->contracts();
    }

    /**
     * @return array{vendor: string, version: string}
     */
    private function parseRestNamespace(string $restNamespace): array
    {
        $parts = array_values(array_filter(explode('/', trim($restNamespace, '/')), static fn (string $part): bool => $part !== ''));
        if (count($parts) < 2) {
            throw new InvalidArgumentException('restNamespace must include vendor and version, e.g. better-route/v1');
        }

        $version = (string) array_pop($parts);

        return [
            'vendor' => implode('/', $parts),
            'version' => $version,
        ];
    }

    private function requireString(?string $value, string $message): string
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException($message);
        }

        return $value;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function listRouteArgs(): array
    {
        $args = [
            'fields' => ['type' => 'string', 'required' => false],
            'sort' => ['type' => 'string', 'required' => false],
            'page' => ['type' => 'integer', 'required' => false],
            'per_page' => ['type' => 'integer', 'required' => false],
        ];

        foreach ($this->filters as $filter) {
            $args[$filter] = ['required' => false];
        }

        return $args;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listQueryParameters(): array
    {
        $parameters = [
            ['in' => 'query', 'name' => 'fields', 'required' => false, 'schema' => ['type' => 'string']],
            ['in' => 'query', 'name' => 'sort', 'required' => false, 'schema' => ['type' => 'string']],
            ['in' => 'query', 'name' => 'page', 'required' => false, 'schema' => ['type' => 'integer']],
            ['in' => 'query', 'name' => 'per_page', 'required' => false, 'schema' => ['type' => 'integer']],
        ];

        foreach ($this->filters as $filter) {
            $parameters[] = [
                'in' => 'query',
                'name' => $filter,
                'required' => false,
                'schema' => ['type' => 'string'],
            ];
        }

        return $parameters;
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @return array<string, mixed>
     */
    private function resourceRouteMeta(string $action, array $parameters, string $responseSchema): array
    {
        return [
            'resource' => $this->name,
            'action' => $action,
            'policy' => $this->policy,
            'operationId' => $this->resourceOperationId($action),
            'tags' => [$this->resourceTag()],
            'scopes' => $this->policyScopes(),
            'parameters' => $parameters,
            'responseSchema' => $responseSchema,
        ];
    }

    private function resourceOperationId(string $action): string
    {
        return lcfirst($this->resourceSchemaBase()) . ucfirst($action);
    }

    private function resourceTag(): string
    {
        return $this->resourceSchemaBase();
    }

    private function resourceSchemaBase(): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $this->name) ?? $this->name;
        return str_replace(' ', '', ucwords(strtolower(trim($normalized))));
    }

    /**
     * @return list<string>
     */
    private function policyScopes(): array
    {
        $scopes = $this->policy['scopes'] ?? [];
        if (!is_array($scopes)) {
            return [];
        }

        $result = [];
        foreach ($scopes as $scope) {
            if (is_string($scope) && $scope !== '') {
                $result[] = $scope;
            }
        }

        return array_values($result);
    }

    private function readId(mixed $request): int
    {
        $raw = null;

        if (is_object($request) && method_exists($request, 'get_param')) {
            $raw = $request->get_param('id');
        } elseif (is_array($request) && isset($request['id'])) {
            $raw = $request['id'];
        }

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        throw new ApiException(
            message: 'Invalid request.',
            status: 400,
            errorCode: 'validation_failed',
            details: ['fieldErrors' => ['id' => ['must be a positive integer']]]
        );
    }
}
