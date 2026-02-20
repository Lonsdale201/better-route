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
}
