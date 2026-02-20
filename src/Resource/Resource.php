<?php

declare(strict_types=1);

namespace BetterRoute\Resource;

use BetterRoute\Http\ApiException;
use BetterRoute\Resource\Cpt\CptListQueryParser;
use BetterRoute\Resource\Cpt\CptRepositoryInterface;
use BetterRoute\Resource\Cpt\WordPressCptRepository;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\Router;
use InvalidArgumentException;
use RuntimeException;

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

    public function register(?DispatcherInterface $dispatcher = null): void
    {
        if ($this->sourceCpt !== null) {
            $this->registerCpt($dispatcher);
            return;
        }

        if ($this->sourceTable !== null) {
            throw new RuntimeException('Custom table resources will be implemented in M3.');
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
                ->meta([
                    'resource' => $this->name,
                    'action' => 'list',
                    'policy' => $this->policy,
                ]);
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
            ])->meta([
                'resource' => $this->name,
                'action' => 'get',
                'policy' => $this->policy,
            ]);
        }

        $router->register($dispatcher);
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
