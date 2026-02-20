<?php

declare(strict_types=1);

namespace BetterRoute\Resource;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\Response;
use BetterRoute\Resource\Cpt\CptListQuery;
use BetterRoute\Resource\Cpt\CptListQueryParser;
use BetterRoute\Resource\Cpt\CptRepositoryInterface;
use BetterRoute\Resource\Cpt\WordPressCptRepository;
use BetterRoute\Resource\Table\TableListQueryParser;
use BetterRoute\Resource\Table\TableRepositoryInterface;
use BetterRoute\Resource\Table\WordPressTableRepository;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\Router;
use InvalidArgumentException;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

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
    private int $defaultPerPage = 20;
    private int $maxPerPage = 100;
    private int $maxOffset = 10000;
    private bool $uniformEnvelope = false;
    /** @var array<string, array<string, mixed>|string> */
    private array $filterSchema = [];
    /** @var list<string> */
    private array $cptVisibleStatuses = ['publish'];
    /** @var null|callable(array<string, mixed>, string): bool */
    private $cptVisibilityPolicy = null;

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

    public function defaultPerPage(int $defaultPerPage): self
    {
        $this->defaultPerPage = $defaultPerPage;
        $this->assertPaginationConfiguration();
        return $this;
    }

    public function maxPerPage(int $maxPerPage): self
    {
        $this->maxPerPage = $maxPerPage;
        $this->assertPaginationConfiguration();
        return $this;
    }

    public function maxOffset(int $maxOffset): self
    {
        $this->maxOffset = $maxOffset;
        $this->assertPaginationConfiguration();
        return $this;
    }

    public function uniformEnvelope(bool $enabled = true): self
    {
        $this->uniformEnvelope = $enabled;
        return $this;
    }

    /**
     * @param array<string, array<string, mixed>|string> $schema
     */
    public function filterSchema(array $schema): self
    {
        $this->filterSchema = $schema;
        return $this;
    }

    /**
     * @param list<string> $statuses
     */
    public function cptVisibleStatuses(array $statuses): self
    {
        $normalized = array_values(array_filter(
            array_map(static fn (string $status): string => trim($status), $statuses),
            static fn (string $status): bool => $status !== ''
        ));

        if ($normalized === []) {
            throw new InvalidArgumentException('cptVisibleStatuses requires at least one status.');
        }

        $this->cptVisibleStatuses = $normalized;
        return $this;
    }

    /**
     * @param callable(array<string, mixed>, string): bool $policy
     */
    public function cptVisibilityPolicy(callable $policy): self
    {
        $this->cptVisibilityPolicy = $policy;
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
     *   filterSchema: array<string, array<string, mixed>|string>,
     *   policy: array<string, mixed>,
     *   defaultPerPage: int,
     *   maxPerPage: int,
     *   maxOffset: int,
     *   cptVisibleStatuses: list<string>,
     *   uniformEnvelope: bool
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
            'filterSchema' => $this->filterSchema,
            'policy' => $this->policy,
            'defaultPerPage' => $this->defaultPerPage,
            'maxPerPage' => $this->maxPerPage,
            'maxOffset' => $this->maxOffset,
            'cptVisibleStatuses' => $this->cptVisibleStatuses,
            'uniformEnvelope' => $this->uniformEnvelope,
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
        $this->assertPaginationConfiguration();

        $namespace = $this->parseRestNamespace($this->requireString($this->restNamespace, 'restNamespace is required.'));
        $router = Router::make($namespace['vendor'], $namespace['version']);
        $repository = $this->cptRepository ?? new WordPressCptRepository();
        $fields = $this->fields !== [] ? $this->fields : ['id', 'title', 'slug', 'excerpt', 'date', 'status'];
        $writeFields = $this->writableFields($fields, 'id');
        $filterSchema = $this->effectiveFilterSchemaForCpt();
        $queryParser = new CptListQueryParser(
            allowedFields: $fields,
            allowedFilters: $this->filters,
            allowedSort: $this->sort !== [] ? $this->sort : ['date', 'id'],
            defaultPerPage: $this->defaultPerPage,
            maxPerPage: $this->maxPerPage,
            filterSchema: $filterSchema,
            maxOffset: $this->maxOffset
        );
        $postType = $this->requireString($this->sourceCpt, 'sourceCpt is required.');
        $allowed = $this->allowedActions !== [] ? $this->allowedActions : ['list', 'get', 'create', 'update', 'delete'];

        if (in_array('list', $allowed, true)) {
            $router->get('/' . $this->name, function (mixed $request) use ($repository, $queryParser, $postType): array {
                $query = $queryParser->parse($request);
                $query = $this->applyDefaultCptStatusFilter($query);
                $result = $repository->list($postType, $query);
                $items = $this->filterVisibleCptItems($result['items'], 'list');

                return [
                    'data' => $items,
                    'meta' => [
                        'page' => $result['page'],
                        'perPage' => $result['perPage'],
                        'total' => count($items),
                    ],
                ];
            })->args($this->listRouteArgs($filterSchema))
                ->meta($this->resourceRouteMeta(
                    action: 'list',
                    parameters: $this->listQueryParameters($filterSchema),
                    responseSchema: '#/components/schemas/' . $this->resourceSchemaBase() . 'ListResponse'
                ))
                ->permission($this->permissionForAction('list'));
        }

        if (in_array('get', $allowed, true)) {
            $router->get('/' . $this->name . '/(?P<id>\d+)', function (mixed $request) use ($repository, $postType, $fields): array {
                $id = $this->readId($request);
                $item = $repository->get($postType, $id, $fields);

                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                if (!$this->isCptItemVisible($item, 'get')) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                if ($this->uniformEnvelope) {
                    return ['data' => $item];
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
            ))
                ->permission($this->permissionForAction('get'));
        }

        if (in_array('create', $allowed, true)) {
            $router->post('/' . $this->name, function (mixed $request) use ($repository, $postType, $fields, $writeFields): Response {
                $payload = $this->readPayload($request, $writeFields);
                $item = $repository->create($postType, $payload, $fields);
                return new Response(['data' => $item], 201);
            })->meta($this->resourceRouteMeta(
                action: 'create',
                parameters: [],
                responseSchema: '#/components/schemas/' . $this->resourceSchemaBase(),
                requestSchema: '#/components/schemas/' . $this->resourceSchemaBase() . 'Input'
            ))
                ->permission($this->permissionForAction('create'));
        }

        if (in_array('update', $allowed, true)) {
            $updateHandler = function (mixed $request) use ($repository, $postType, $fields, $writeFields): array {
                $id = $this->readId($request);
                $payload = $this->readPayload($request, $writeFields);
                $item = $repository->update($postType, $id, $payload, $fields);
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            };

            $meta = $this->resourceRouteMeta(
                action: 'update',
                parameters: [[
                    'in' => 'path',
                    'name' => 'id',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ]],
                responseSchema: '#/components/schemas/' . $this->resourceSchemaBase(),
                requestSchema: '#/components/schemas/' . $this->resourceSchemaBase() . 'Input'
            );

            $router->put('/' . $this->name . '/(?P<id>\d+)', $updateHandler)
                ->meta($meta)
                ->permission($this->permissionForAction('update'));
            $router->patch('/' . $this->name . '/(?P<id>\d+)', $updateHandler)
                ->meta($meta)
                ->permission($this->permissionForAction('update'));
        }

        if (in_array('delete', $allowed, true)) {
            $router->delete('/' . $this->name . '/(?P<id>\d+)', function (mixed $request) use ($repository, $postType): array {
                $id = $this->readId($request);
                $deleted = $repository->delete($postType, $id);
                if (!$deleted) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => ['id' => $id, 'deleted' => true]];
            })->meta($this->resourceRouteMeta(
                action: 'delete',
                parameters: [[
                    'in' => 'path',
                    'name' => 'id',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ]],
                responseSchema: '#/components/schemas/DeleteResponse'
            ))
                ->permission($this->permissionForAction('delete'));
        }

        $router->register($dispatcher);
        $this->registeredContracts = $router->contracts();
    }

    private function registerTable(?DispatcherInterface $dispatcher): void
    {
        $this->assertPaginationConfiguration();

        $namespace = $this->parseRestNamespace($this->requireString($this->restNamespace, 'restNamespace is required.'));
        $router = Router::make($namespace['vendor'], $namespace['version']);
        $repository = $this->tableRepository ?? new WordPressTableRepository();
        $table = $this->requireString($this->sourceTable, 'sourceTable is required.');
        $primaryKey = $this->requireString($this->primaryKey, 'primaryKey is required.');
        $fields = $this->fields;
        if ($fields === []) {
            throw new InvalidArgumentException('fields are required for sourceTable resources.');
        }
        $writeFields = $this->writableFields($fields, $primaryKey);

        $queryParser = new TableListQueryParser(
            allowedFields: $fields,
            allowedFilters: $this->filters,
            allowedSort: $this->sort !== [] ? $this->sort : [$primaryKey],
            defaultPerPage: $this->defaultPerPage,
            maxPerPage: $this->maxPerPage,
            filterSchema: $this->filterSchema,
            maxOffset: $this->maxOffset
        );

        $allowed = $this->allowedActions !== [] ? $this->allowedActions : ['list', 'get', 'create', 'update', 'delete'];

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
            })->args($this->listRouteArgs($this->filterSchema))
                ->meta($this->resourceRouteMeta(
                    action: 'list',
                    parameters: $this->listQueryParameters($this->filterSchema),
                    responseSchema: '#/components/schemas/' . $this->resourceSchemaBase() . 'ListResponse'
                ))
                ->permission($this->permissionForAction('list'));
        }

        if (in_array('get', $allowed, true)) {
            $router->get('/' . $this->name . '/(?P<id>\d+)', function (mixed $request) use ($repository, $table, $primaryKey, $fields): array {
                $id = $this->readId($request);
                $item = $repository->get($table, $primaryKey, $id, $fields);

                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                if ($this->uniformEnvelope) {
                    return ['data' => $item];
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
            ))
                ->permission($this->permissionForAction('get'));
        }

        if (in_array('create', $allowed, true)) {
            $router->post('/' . $this->name, function (mixed $request) use ($repository, $table, $primaryKey, $fields, $writeFields): Response {
                $payload = $this->readPayload($request, $writeFields);
                $item = $repository->create($table, $primaryKey, $payload, $fields);
                return new Response(['data' => $item], 201);
            })->meta($this->resourceRouteMeta(
                action: 'create',
                parameters: [],
                responseSchema: '#/components/schemas/' . $this->resourceSchemaBase(),
                requestSchema: '#/components/schemas/' . $this->resourceSchemaBase() . 'Input'
            ))
                ->permission($this->permissionForAction('create'));
        }

        if (in_array('update', $allowed, true)) {
            $updateHandler = function (mixed $request) use ($repository, $table, $primaryKey, $fields, $writeFields): array {
                $id = $this->readId($request);
                $payload = $this->readPayload($request, $writeFields);
                $item = $repository->update($table, $primaryKey, $id, $payload, $fields);
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            };

            $meta = $this->resourceRouteMeta(
                action: 'update',
                parameters: [[
                    'in' => 'path',
                    'name' => 'id',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ]],
                responseSchema: '#/components/schemas/' . $this->resourceSchemaBase(),
                requestSchema: '#/components/schemas/' . $this->resourceSchemaBase() . 'Input'
            );

            $router->put('/' . $this->name . '/(?P<id>\d+)', $updateHandler)
                ->meta($meta)
                ->permission($this->permissionForAction('update'));
            $router->patch('/' . $this->name . '/(?P<id>\d+)', $updateHandler)
                ->meta($meta)
                ->permission($this->permissionForAction('update'));
        }

        if (in_array('delete', $allowed, true)) {
            $router->delete('/' . $this->name . '/(?P<id>\d+)', function (mixed $request) use ($repository, $table, $primaryKey): array {
                $id = $this->readId($request);
                $deleted = $repository->delete($table, $primaryKey, $id);
                if (!$deleted) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => ['id' => $id, 'deleted' => true]];
            })->meta($this->resourceRouteMeta(
                action: 'delete',
                parameters: [[
                    'in' => 'path',
                    'name' => 'id',
                    'required' => true,
                    'schema' => ['type' => 'integer'],
                ]],
                responseSchema: '#/components/schemas/DeleteResponse'
            ))
                ->permission($this->permissionForAction('delete'));
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
     * @param array<string, array<string, mixed>|string> $filterSchema
     * @return array<string, array<string, mixed>>
     */
    private function listRouteArgs(array $filterSchema): array
    {
        $args = [
            'fields' => ['type' => 'string', 'required' => false],
            'sort' => ['type' => 'string', 'required' => false],
            'page' => ['type' => 'integer', 'required' => false],
            'per_page' => ['type' => 'integer', 'required' => false],
        ];

        foreach ($this->filters as $filter) {
            $args[$filter] = [
                'required' => false,
                'type' => $this->filterWpArgType($filter, $filterSchema),
            ];
        }

        return $args;
    }

    /**
     * @param array<string, array<string, mixed>|string> $filterSchema
     * @return list<array<string, mixed>>
     */
    private function listQueryParameters(array $filterSchema): array
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
                'schema' => $this->filterOpenApiSchema($filter, $filterSchema),
            ];
        }

        return $parameters;
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @return array<string, mixed>
     */
    private function resourceRouteMeta(
        string $action,
        array $parameters,
        string $responseSchema,
        ?string $requestSchema = null
    ): array {
        return [
            'resource' => $this->name,
            'action' => $action,
            'policy' => $this->policy,
            'operationId' => $this->resourceOperationId($action),
            'tags' => [$this->resourceTag()],
            'scopes' => $this->policyScopes(),
            'parameters' => $parameters,
            'requestSchema' => $requestSchema,
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

    private function permissionForAction(string $action): callable
    {
        if (($this->policy['public'] ?? false) === true) {
            return static fn (): bool => true;
        }

        $callback = $this->policy['permissionCallback'] ?? null;
        if (is_callable($callback)) {
            return fn (mixed $request): mixed => $this->invokePolicyCallable($callback, $request, $action);
        }

        $permissions = $this->policy['permissions'] ?? null;
        if (!is_array($permissions)) {
            return static fn (): bool => true;
        }

        $rule = $permissions[$action] ?? ($permissions['*'] ?? null);
        if (is_callable($rule)) {
            return fn (mixed $request): mixed => $this->invokePolicyCallable($rule, $request, $action);
        }

        if (is_bool($rule)) {
            return static fn (): bool => $rule;
        }

        if (is_string($rule) && $rule !== '') {
            return fn (): bool => $this->currentUserCan($rule);
        }

        if (is_array($rule)) {
            $caps = array_values(array_filter(
                array_map(static fn (mixed $cap): string => is_string($cap) ? trim($cap) : '', $rule),
                static fn (string $cap): bool => $cap !== ''
            ));

            if ($caps !== []) {
                return fn (): bool => $this->currentUserCanAny($caps);
            }
        }

        return static fn (): bool => true;
    }

    private function currentUserCan(string $capability): bool
    {
        if (!function_exists('current_user_can')) {
            return false;
        }

        return (bool) current_user_can($capability);
    }

    /**
     * @param list<string> $capabilities
     */
    private function currentUserCanAny(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->currentUserCan($capability)) {
                return true;
            }
        }

        return false;
    }

    private function invokePolicyCallable(callable $callable, mixed $request, string $action): mixed
    {
        $args = [$request, $action, $this];

        try {
            if (is_array($callable) && count($callable) === 2) {
                $reflection = new ReflectionMethod($callable[0], (string) $callable[1]);
            } else {
                $reflection = new ReflectionFunction(\Closure::fromCallable($callable));
            }

            if ($reflection->isVariadic()) {
                return $callable(...$args);
            }

            return $callable(...array_slice($args, 0, $reflection->getNumberOfParameters()));
        } catch (ReflectionException) {
            return $callable($request);
        }
    }

    /**
     * @param array<string, array<string, mixed>|string> $filterSchema
     */
    private function filterWpArgType(string $filter, array $filterSchema): string
    {
        $rule = $this->normalizeFilterRule($filterSchema[$filter] ?? null);
        if ($rule === null) {
            return 'string';
        }

        return match ((string) ($rule['type'] ?? 'string')) {
            'int' => 'integer',
            'float' => 'number',
            'bool' => 'boolean',
            default => 'string',
        };
    }

    /**
     * @param array<string, array<string, mixed>|string> $filterSchema
     * @return array<string, mixed>
     */
    private function filterOpenApiSchema(string $filter, array $filterSchema): array
    {
        $rule = $this->normalizeFilterRule($filterSchema[$filter] ?? null);
        if ($rule === null) {
            return ['type' => 'string'];
        }

        $type = (string) ($rule['type'] ?? 'string');
        if ($type === 'enum') {
            $values = $rule['values'] ?? [];
            if (is_array($values)) {
                $enum = [];
                foreach ($values as $value) {
                    if (is_string($value) && $value !== '') {
                        $enum[] = $value;
                    }
                }

                return ['type' => 'string', 'enum' => $enum];
            }

            return ['type' => 'string'];
        }

        return match ($type) {
            'int' => ['type' => 'integer'],
            'float' => ['type' => 'number'],
            'bool' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date-time'],
            default => ['type' => 'string'],
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

    /**
     * @return array<string, array<string, mixed>|string>
     */
    private function effectiveFilterSchemaForCpt(): array
    {
        $schema = $this->filterSchema;

        if (in_array('status', $this->filters, true) && !array_key_exists('status', $schema)) {
            $schema['status'] = [
                'type' => 'enum',
                'values' => $this->cptVisibleStatuses,
            ];
        }

        return $schema;
    }

    private function applyDefaultCptStatusFilter(CptListQuery $query): CptListQuery
    {
        if (array_key_exists('status', $query->filters)) {
            return $query;
        }

        $filters = $query->filters;
        $filters['status'] = $this->cptVisibleStatuses;

        return new CptListQuery(
            fields: $query->fields,
            filters: $filters,
            sortField: $query->sortField,
            sortDirection: $query->sortDirection,
            page: $query->page,
            perPage: $query->perPage
        );
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    private function filterVisibleCptItems(array $items, string $action): array
    {
        $result = [];
        foreach ($items as $item) {
            if ($this->isCptItemVisible($item, $action)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $item
     */
    private function isCptItemVisible(array $item, string $action): bool
    {
        $status = $item['status'] ?? null;
        $visible = !is_string($status) || in_array($status, $this->cptVisibleStatuses, true);

        if (is_callable($this->cptVisibilityPolicy)) {
            $visible = $visible && (bool) ($this->cptVisibilityPolicy)($item, $action);
        }

        return $visible;
    }

    /**
     * @param list<string> $fields
     * @return list<string>
     */
    private function writableFields(array $fields, string $idField): array
    {
        $result = [];
        foreach ($fields as $field) {
            if ($field !== $idField) {
                $result[] = $field;
            }
        }

        return array_values($result);
    }

    /**
     * @param list<string> $allowedFields
     * @return array<string, mixed>
     */
    private function readPayload(mixed $request, array $allowedFields): array
    {
        if ($allowedFields === []) {
            throw $this->validationError(['payload' => ['no writable fields configured']]);
        }

        $payload = [];

        if (is_object($request) && method_exists($request, 'get_json_params')) {
            $json = $request->get_json_params();
            if (is_array($json)) {
                $payload = $json;
            }
        }

        if ($payload === [] && is_object($request) && method_exists($request, 'get_body_params')) {
            $body = $request->get_body_params();
            if (is_array($body)) {
                $payload = $body;
            }
        }

        if ($payload === [] && is_array($request)) {
            if (isset($request['data']) && is_array($request['data'])) {
                $payload = $request['data'];
            } else {
                $payload = $request;
            }
        }

        if ($payload === []) {
            throw $this->validationError(['payload' => ['at least one field is required']]);
        }

        $unknown = array_values(array_filter(
            array_keys($payload),
            static fn (string $key): bool => !in_array($key, $allowedFields, true)
        ));

        if ($unknown !== []) {
            $errors = [];
            foreach ($unknown as $key) {
                $errors[$key] = ['field not allowed'];
            }

            throw $this->validationError($errors);
        }

        $result = [];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $payload)) {
                $result[$field] = $payload[$field];
            }
        }

        if ($result === []) {
            throw $this->validationError(['payload' => ['at least one field is required']]);
        }

        return $result;
    }

    private function assertPaginationConfiguration(): void
    {
        if ($this->defaultPerPage < 1) {
            throw new InvalidArgumentException('defaultPerPage must be greater than 0.');
        }

        if ($this->maxPerPage < 1) {
            throw new InvalidArgumentException('maxPerPage must be greater than 0.');
        }

        if ($this->maxPerPage < $this->defaultPerPage) {
            throw new InvalidArgumentException('maxPerPage must be greater than or equal to defaultPerPage.');
        }

        if ($this->maxOffset < 0) {
            throw new InvalidArgumentException('maxOffset must be greater than or equal to 0.');
        }
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

        throw $this->validationError(['id' => ['must be a positive integer']]);
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
