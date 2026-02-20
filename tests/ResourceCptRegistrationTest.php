<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Resource\Cpt\CptListQuery;
use BetterRoute\Resource\Cpt\CptRepositoryInterface;
use BetterRoute\Resource\Resource;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use PHPUnit\Framework\TestCase;

final class ResourceCptRegistrationTest extends TestCase
{
    public function testRegistersListAndGetRoutesForCpt(): void
    {
        $repository = new ArrayCptRepository();
        $dispatcher = new ResourceDispatcher();

        Resource::make('articles')
            ->restNamespace('better-route/v1')
            ->sourceCpt('post')
            ->allow(['list', 'get'])
            ->fields(['id', 'title'])
            ->filters(['status'])
            ->sort(['date', 'id'])
            ->usingCptRepository($repository)
            ->register($dispatcher);

        self::assertCount(2, $dispatcher->registrations);

        $list = $dispatcher->registrations[0];
        $get = $dispatcher->registrations[1];

        self::assertSame('/articles', $list['route']->uri);
        self::assertSame('/articles/(?P<id>\d+)', $get['route']->uri);

        $listResponse = ($list['callback'])(new ResourceFakeRequest([
            'fields' => 'id,title',
            'status' => 'publish',
            'sort' => '-date',
            'page' => '1',
            'per_page' => '5',
        ]));

        self::assertSame(200, $listResponse['status']);
        self::assertSame(1, $listResponse['body']['meta']['total']);
        self::assertSame('publish', $repository->lastListQuery?->filters['status']);

        $getResponse = ($get['callback'])(new ResourceFakeRequest(['id' => '1']));
        self::assertSame(200, $getResponse['status']);
        self::assertSame(1, $getResponse['body']['id']);
    }

    public function testGetReturnsNotFoundErrorShape(): void
    {
        $repository = new ArrayCptRepository();
        $repository->item = null;

        $dispatcher = new ResourceDispatcher();
        Resource::make('articles')
            ->restNamespace('better-route/v1')
            ->sourceCpt('post')
            ->allow(['get'])
            ->fields(['id', 'title'])
            ->usingCptRepository($repository)
            ->register($dispatcher);

        $response = ($dispatcher->registrations[0]['callback'])(new ResourceFakeRequest(['id' => '999']));
        self::assertSame(404, $response['status']);
        self::assertSame('not_found', $response['body']['error']['code']);
    }

    public function testThrowsForInvalidNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Resource::make('articles')
            ->restNamespace('better-route')
            ->sourceCpt('post')
            ->allow(['list'])
            ->fields(['id'])
            ->usingCptRepository(new ArrayCptRepository())
            ->register(new ResourceDispatcher());
    }

    public function testValidationErrorBubblesAsApiExceptionInParser(): void
    {
        $dispatcher = new ResourceDispatcher();
        Resource::make('articles')
            ->restNamespace('better-route/v1')
            ->sourceCpt('post')
            ->allow(['list'])
            ->fields(['id'])
            ->usingCptRepository(new ArrayCptRepository())
            ->register($dispatcher);

        $response = ($dispatcher->registrations[0]['callback'])(new ResourceFakeRequest(['foo' => 'bar']));
        self::assertSame(400, $response['status']);
        self::assertSame('validation_failed', $response['body']['error']['code']);
    }
}

final class ResourceDispatcher implements DispatcherInterface
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

final class ResourceFakeRequest
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
        return strtolower($name) === 'x-request-id' ? 'req_resource_test' : '';
    }
}

final class ArrayCptRepository implements CptRepositoryInterface
{
    public ?CptListQuery $lastListQuery = null;

    /** @var array<string, mixed>|null */
    public ?array $item = [
        'id' => 1,
        'title' => 'Article',
    ];

    public function list(string $postType, CptListQuery $query): array
    {
        $this->lastListQuery = $query;

        return [
            'items' => [$this->item ?? []],
            'total' => $this->item === null ? 0 : 1,
            'page' => $query->page,
            'perPage' => $query->perPage,
        ];
    }

    public function get(string $postType, int $id, array $fields): ?array
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
