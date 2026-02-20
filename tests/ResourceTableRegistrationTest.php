<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Resource\Resource;
use BetterRoute\Resource\Table\TableListQuery;
use BetterRoute\Resource\Table\TableRepositoryInterface;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use PHPUnit\Framework\TestCase;

final class ResourceTableRegistrationTest extends TestCase
{
    public function testRegistersListAndGetRoutesForTableResource(): void
    {
        $repository = new ArrayTableRepository();
        $dispatcher = new TableResourceDispatcher();

        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['list', 'get'])
            ->fields(['id', 'title', 'source'])
            ->filters(['source'])
            ->sort(['id', 'created_at'])
            ->usingTableRepository($repository)
            ->register($dispatcher);

        self::assertCount(2, $dispatcher->registrations);
        self::assertSame('/raw-articles', $dispatcher->registrations[0]['route']->uri);
        self::assertSame('/raw-articles/(?P<id>\d+)', $dispatcher->registrations[1]['route']->uri);
        self::assertSame('rawArticlesList', $dispatcher->registrations[0]['route']->meta['operationId']);
        self::assertSame('rawArticlesGet', $dispatcher->registrations[1]['route']->meta['operationId']);
        self::assertSame(['RawArticles'], $dispatcher->registrations[0]['route']->meta['tags']);

        $listResponse = ($dispatcher->registrations[0]['callback'])(new TableResourceFakeRequest([
            'fields' => 'id,title',
            'source' => 'rss',
            'sort' => '-created_at',
            'page' => '1',
            'per_page' => '5',
        ]));
        self::assertSame(200, $listResponse['status']);
        self::assertSame('rss', $repository->lastListQuery?->filters['source']);

        $getResponse = ($dispatcher->registrations[1]['callback'])(new TableResourceFakeRequest(['id' => '1']));
        self::assertSame(200, $getResponse['status']);
        self::assertSame(1, $getResponse['body']['id']);
    }

    public function testTableResourceExposesContracts(): void
    {
        $resource = Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['list', 'get'])
            ->fields(['id', 'title', 'source'])
            ->filters(['source'])
            ->sort(['id'])
            ->usingTableRepository(new ArrayTableRepository());

        $resource->register(new TableResourceDispatcher());
        $contracts = $resource->contracts(true);

        self::assertCount(2, $contracts);
        self::assertSame('rawArticlesList', $contracts[0]['meta']['operationId']);
        self::assertSame('rawArticlesGet', $contracts[1]['meta']['operationId']);
    }

    public function testRequiresFieldsForTableResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['list'])
            ->usingTableRepository(new ArrayTableRepository())
            ->register(new TableResourceDispatcher());
    }

    public function testInvalidPaginationConfigurationThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['list'])
            ->fields(['id'])
            ->defaultPerPage(50)
            ->maxPerPage(10);
    }

    public function testReturnsValidationErrorForUnknownQueryParams(): void
    {
        $dispatcher = new TableResourceDispatcher();
        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['list'])
            ->fields(['id', 'title'])
            ->usingTableRepository(new ArrayTableRepository())
            ->register($dispatcher);

        $response = ($dispatcher->registrations[0]['callback'])(new TableResourceFakeRequest(['foo' => 'bar']));
        self::assertSame(400, $response['status']);
        self::assertSame('validation_failed', $response['body']['error']['code']);
    }

    public function testTableResourceMaxPerPageIsEnforced(): void
    {
        $dispatcher = new TableResourceDispatcher();
        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['list'])
            ->fields(['id', 'title'])
            ->defaultPerPage(2)
            ->maxPerPage(2)
            ->usingTableRepository(new ArrayTableRepository())
            ->register($dispatcher);

        $response = ($dispatcher->registrations[0]['callback'])(new TableResourceFakeRequest(['per_page' => '3']));
        self::assertSame(400, $response['status']);
        self::assertSame('validation_failed', $response['body']['error']['code']);
    }

    public function testTableGetCanReturnUniformEnvelope(): void
    {
        $dispatcher = new TableResourceDispatcher();
        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['get'])
            ->fields(['id', 'title'])
            ->uniformEnvelope()
            ->usingTableRepository(new ArrayTableRepository())
            ->register($dispatcher);

        $response = ($dispatcher->registrations[0]['callback'])(new TableResourceFakeRequest(['id' => '1']));
        self::assertSame(200, $response['status']);
        self::assertSame(1, $response['body']['data']['id']);
    }

    public function testRegistersCrudRoutesForTable(): void
    {
        $dispatcher = new TableResourceDispatcher();
        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['create', 'update', 'delete'])
            ->fields(['id', 'title'])
            ->usingTableRepository(new ArrayTableRepository())
            ->register($dispatcher);

        self::assertCount(4, $dispatcher->registrations);
        self::assertSame('POST', $dispatcher->registrations[0]['route']->method);
        self::assertSame('PUT', $dispatcher->registrations[1]['route']->method);
        self::assertSame('PATCH', $dispatcher->registrations[2]['route']->method);
        self::assertSame('DELETE', $dispatcher->registrations[3]['route']->method);
    }

    public function testCreateAndDeleteFlowForTable(): void
    {
        $dispatcher = new TableResourceDispatcher();
        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['create', 'delete'])
            ->fields(['id', 'title'])
            ->usingTableRepository(new ArrayTableRepository())
            ->register($dispatcher);

        $create = ($dispatcher->registrations[0]['callback'])(new TableResourceFakeRequest(['title' => 'Row X']));
        self::assertSame(201, $create['status']);
        self::assertSame('Row X', $create['body']['data']['title']);

        $delete = ($dispatcher->registrations[1]['callback'])(new TableResourceFakeRequest(['id' => '1']));
        self::assertSame(200, $delete['status']);
        self::assertTrue($delete['body']['data']['deleted']);
    }

    public function testTableResourceUsesFilterSchemaTypeCoercion(): void
    {
        $repository = new ArrayTableRepository();
        $dispatcher = new TableResourceDispatcher();

        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['list'])
            ->fields(['id', 'title'])
            ->filters(['source_id', 'published'])
            ->filterSchema([
                'source_id' => 'int',
                'published' => 'bool',
            ])
            ->usingTableRepository($repository)
            ->register($dispatcher);

        ($dispatcher->registrations[0]['callback'])(new TableResourceFakeRequest([
            'source_id' => '12',
            'published' => 'true',
        ]));

        self::assertNotNull($repository->lastListQuery);
        self::assertSame(12, $repository->lastListQuery->filters['source_id']);
        self::assertTrue($repository->lastListQuery->filters['published']);
    }

    public function testTableResourceRegistersPermissionCallbackFromPolicy(): void
    {
        $dispatcher = new TableResourceDispatcher();

        Resource::make('raw-articles')
            ->restNamespace('better-route/v1')
            ->sourceTable('ai_raw_articles', 'id')
            ->allow(['list'])
            ->fields(['id', 'title'])
            ->policy([
                'permissions' => [
                    'list' => static fn (): bool => false,
                ],
            ])
            ->usingTableRepository(new ArrayTableRepository())
            ->register($dispatcher);

        $permission = $dispatcher->registrations[0]['permissionCallback'];
        self::assertFalse((bool) $permission(new TableResourceFakeRequest([])));
    }
}

final class TableResourceDispatcher implements DispatcherInterface
{
    /** @var list<array{namespace: string, route: RouteDefinition, callback: callable, permissionCallback: callable}> */
    public array $registrations = [];

    public function register(
        string $namespace,
        RouteDefinition $route,
        callable $callback,
        callable $permissionCallback
    ): void {
        $this->registrations[] = [
            'namespace' => $namespace,
            'route' => $route,
            'callback' => $callback,
            'permissionCallback' => $permissionCallback,
        ];
    }
}

final class TableResourceFakeRequest
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(private readonly array $params)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function get_params(): array
    {
        return $this->params;
    }

    public function get_param(string $name): mixed
    {
        return $this->params[$name] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_json_params(): array
    {
        return $this->params;
    }

    /**
     * @return array<string, mixed>
     */
    public function get_body_params(): array
    {
        return $this->params;
    }

    public function get_header(string $name): string
    {
        return strtolower($name) === 'x-request-id' ? 'req_table_resource_test' : '';
    }
}

final class ArrayTableRepository implements TableRepositoryInterface
{
    public ?TableListQuery $lastListQuery = null;

    /** @var array<string, mixed>|null */
    public ?array $item = [
        'id' => 1,
        'title' => 'Row 1',
        'source' => 'rss',
    ];

    public function list(string $table, string $primaryKey, TableListQuery $query): array
    {
        $this->lastListQuery = $query;

        return [
            'items' => [$this->item ?? []],
            'total' => $this->item === null ? 0 : 1,
            'page' => $query->page,
            'perPage' => $query->perPage,
        ];
    }

    public function get(string $table, string $primaryKey, int $id, array $fields): ?array
    {
        if ($this->item === null) {
            return null;
        }

        $row = [];
        foreach ($fields as $field) {
            $row[$field] = $this->item[$field] ?? null;
        }

        return $row;
    }

    public function create(string $table, string $primaryKey, array $payload, array $fields): array
    {
        $this->item = array_merge($this->item ?? [], $payload);
        $this->item[$primaryKey] = $this->item[$primaryKey] ?? 1;

        return $this->get($table, $primaryKey, (int) $this->item[$primaryKey], $fields) ?? [];
    }

    public function update(string $table, string $primaryKey, int $id, array $payload, array $fields): ?array
    {
        if ($this->item === null) {
            return null;
        }

        $this->item = array_merge($this->item, $payload);
        $this->item[$primaryKey] = $id;

        return $this->get($table, $primaryKey, $id, $fields);
    }

    public function delete(string $table, string $primaryKey, int $id): bool
    {
        if ($this->item === null) {
            return false;
        }

        $this->item = null;
        return true;
    }
}
