<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Resource\OwnedResourcePolicy;
use BetterRoute\Resource\Resource;
use BetterRoute\Resource\Table\TableListQuery;
use BetterRoute\Resource\Table\TableRepositoryInterface;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState(false)]
final class OwnedResourcePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        require __DIR__ . '/fixtures/ownership-user.php';
    }

    /** @return iterable<string, array{string, string}> */
    public static function actionsAndSources(): iterable
    {
        foreach (['get', 'update', 'delete'] as $action) {
            foreach (['query', 'body'] as $source) {
                yield $action . '-' . $source => [$action, $source];
            }
        }
    }

    #[DataProvider('actionsAndSources')]
    public function testAnotherOwnedIdCannotAuthorizeVictimUrl(string $action, string $source): void
    {
        $repository = new OwnershipTableRepository();
        $dispatcher = new OwnershipDispatcher();
        $checked = [];
        Resource::make('records')->restNamespace('ownership/v1')->sourceTable('records', 'id')
            ->allow([$action])->fields(['id', 'title'])
            ->policy(OwnedResourcePolicy::currentUserOwns(
                static function (int $id) use (&$checked): int {
                    $checked[] = $id;
                    return $id === 11 ? 7 : 8;
                },
                bypassCapability: null
            ))
            ->usingTableRepository($repository)->register($dispatcher);

        foreach ($dispatcher->registrations as $route) {
            $request = new OwnershipRequest(22, $source === 'query' ? ['id' => 11] : [], $source === 'body' ? ['id' => 11, 'title' => 'changed'] : ['title' => 'changed']);
            $allowed = ($route['permission'])($request);
            if ($allowed) {
                ($route['callback'])($request);
            }
            self::assertFalse($allowed, 'A merged request ID must not authorize a different URL object.');
        }
        self::assertSame(array_fill(0, count($dispatcher->registrations), 22), $checked);
        self::assertSame([], $repository->effects);
    }

    #[DataProvider('actionsAndSources')]
    public function testOwnedUrlAndHandlerRemainAlignedWhenOtherInputDiffers(string $action, string $source): void
    {
        $repository = new OwnershipTableRepository();
        $dispatcher = new OwnershipDispatcher();
        $checked = [];
        Resource::make('records')->restNamespace('ownership/v1')->sourceTable('records', 'id')
            ->allow([$action])->fields(['id', 'title'])
            ->policy(OwnedResourcePolicy::currentUserOwns(
                static function (int $id) use (&$checked): int {
                    $checked[] = $id;
                    return $id === 11 ? 7 : 8;
                },
                bypassCapability: null
            ))
            ->usingTableRepository($repository)->register($dispatcher);

        foreach ($dispatcher->registrations as $route) {
            $request = new OwnershipRequest(11, $source === 'query' ? ['id' => 22] : [], $source === 'body' ? ['id' => 22, 'title' => 'changed'] : ['title' => 'changed']);
            self::assertTrue(($route['permission'])($request));
            $response = ($route['callback'])($request);
            // IDs are not writable payload fields; preserve that independent validation.
            self::assertSame($action === 'update' && $source === 'body' ? 400 : 200, $response['status']);
        }
        self::assertSame(array_fill(0, count($dispatcher->registrations), 11), $checked);
        if ($action === 'update' && $source === 'body') {
            self::assertSame([], $repository->effects);
        } else {
            self::assertNotEmpty($repository->effects);
        }
        foreach ($repository->effects as $id) {
            self::assertSame(11, $id);
        }
    }

    public function testInvalidUrlIdsFailClosedAndLegacyFallbackStillWorks(): void
    {
        $checked = [];
        $policy = OwnedResourcePolicy::currentUserOwns(static function (int $id) use (&$checked): int {
            $checked[] = $id;
            return 7;
        }, bypassCapability: null);
        $permission = $policy['permissions']['get'];
        foreach ([0, -1, true, 1.5, '11suffix', [], ''] as $invalid) {
            self::assertFalse($permission(new OwnershipRequest($invalid, ['id' => 11]), 'get'));
        }
        self::assertSame([], $checked);
        self::assertTrue($permission(['id' => '11'], 'get'));
        $legacy = new class () {
            public function get_param(string $name): string
            {
                return '11';
            }
        };
        self::assertTrue($permission($legacy, 'get'));
        self::assertSame([11, 11], $checked);
    }
}

final class OwnershipRequest
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $body
     */
    public function __construct(private readonly mixed $urlId, private readonly array $query = [], private readonly array $body = [])
    {
    }

    /** @return array<string, mixed> */
    public function get_url_params(): array
    {
        return ['id' => $this->urlId];
    }

    public function get_param(string $name): mixed
    {
        return $this->body[$name] ?? $this->query[$name] ?? ($name === 'id' ? $this->urlId : null);
    }

    /** @return array<string, mixed> */
    public function get_params(): array
    {
        return array_replace(['id' => $this->urlId], $this->query, $this->body);
    }

    /** @return array<string, mixed> */
    public function get_json_params(): array
    {
        return $this->body;
    }

    public function get_header(string $name): string
    {
        return '';
    }
}

final class OwnershipDispatcher implements DispatcherInterface
{
    /** @var list<array{callback: callable, permission: callable}> */
    public array $registrations = [];

    public function register(string $namespace, RouteDefinition $route, callable $callback, callable $permissionCallback): void
    {
        $this->registrations[] = ['callback' => $callback, 'permission' => $permissionCallback];
    }
}

final class OwnershipTableRepository implements TableRepositoryInterface
{
    /** @var list<int> */
    public array $effects = [];

    public function list(string $table, string $primaryKey, TableListQuery $query): array
    {
        return ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 20];
    }

    public function get(string $table, string $primaryKey, int $id, array $fields): ?array
    {
        $this->effects[] = $id;
        return ['id' => $id, 'title' => 'fixture'];
    }

    public function create(string $table, string $primaryKey, array $payload, array $fields): array
    {
        return ['id' => 11, 'title' => 'fixture'];
    }

    public function update(string $table, string $primaryKey, int $id, array $payload, array $fields): ?array
    {
        $this->effects[] = $id;
        return ['id' => $id, 'title' => 'fixture'];
    }

    public function delete(string $table, string $primaryKey, int $id): bool
    {
        $this->effects[] = $id;
        return true;
    }
}
