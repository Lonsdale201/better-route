<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Storage\WpdbAdapter;
use BetterRoute\Storage\WpdbClient;
use PHPUnit\Framework\TestCase;

final class WpdbAdapterTest extends TestCase
{
    public function testListUsesPreparedStatementsAndPagination(): void
    {
        $fakeWpdb = new FakeWpdbClient();
        $fakeWpdb->results = [
            ['id' => 1, 'title' => 'A'],
        ];
        $fakeWpdb->count = 1;
        $GLOBALS['wpdb'] = $fakeWpdb;

        $adapter = new WpdbAdapter();
        $result = $adapter->list(
            table: 'ai_raw_articles',
            primaryKey: 'id',
            fields: ['id', 'title'],
            filters: ['source' => 'rss'],
            sortField: 'id',
            sortDirection: 'DESC',
            page: 2,
            perPage: 25
        );

        self::assertSame(1, $result['total']);
        self::assertSame(2, $result['page']);
        self::assertCount(2, $fakeWpdb->preparedCalls);

        self::assertStringContainsString('LIMIT %d OFFSET %d', $fakeWpdb->preparedCalls[0]['query']);
        self::assertSame([25, 25], array_slice($fakeWpdb->preparedCalls[0]['args'], -2));
    }

    public function testRejectsInvalidIdentifiers(): void
    {
        $GLOBALS['wpdb'] = new FakeWpdbClient();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid field');

        (new WpdbAdapter())->list(
            table: 'ai_raw_articles',
            primaryKey: 'id',
            fields: ['title;DROP'],
            filters: [],
            sortField: null,
            sortDirection: 'ASC',
            page: 1,
            perPage: 20
        );
    }

    public function testCreateUpdateDeleteWorkWithPreparedQueries(): void
    {
        $fakeWpdb = new FakeWpdbClient();
        $fakeWpdb->insert_id = 10;
        $fakeWpdb->results = [['id' => 10, 'title' => 'Created']];
        $GLOBALS['wpdb'] = $fakeWpdb;

        $adapter = new WpdbAdapter();
        $created = $adapter->create(
            table: 'ai_raw_articles',
            primaryKey: 'id',
            payload: ['title' => 'Created'],
            fields: ['id', 'title']
        );

        self::assertSame(10, $created['id']);
        self::assertNotEmpty($fakeWpdb->queryCalls);

        $fakeWpdb->results = [['id' => 10, 'title' => 'Updated']];
        $updated = $adapter->update(
            table: 'ai_raw_articles',
            primaryKey: 'id',
            id: 10,
            payload: ['title' => 'Updated'],
            fields: ['id', 'title']
        );
        self::assertSame('Updated', $updated['title']);

        $deleted = $adapter->delete(
            table: 'ai_raw_articles',
            primaryKey: 'id',
            id: 10
        );
        self::assertTrue($deleted);
    }

    protected function tearDown(): void
    {
        unset($GLOBALS['wpdb']);
    }
}

final class FakeWpdbClient implements WpdbClient
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;

    /** @var list<array{query: string, args: list<mixed>}> */
    public array $preparedCalls = [];

    /** @var list<mixed> */
    public array $queryCalls = [];

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public int $count = 0;

    /**
     * @return array{query: string, args: list<mixed>}
     */
    public function prepare(string $query, mixed ...$args): array
    {
        $call = ['query' => $query, 'args' => array_values($args)];
        $this->preparedCalls[] = $call;
        return $call;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function get_results(mixed $query, mixed $output = null): array
    {
        return $this->results;
    }

    public function get_var(mixed $query): int
    {
        if (is_string($query) && stripos($query, 'LAST_INSERT_ID') !== false) {
            return $this->insert_id;
        }

        return $this->count;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get_row(mixed $query, mixed $output = null): ?array
    {
        return $this->results[0] ?? null;
    }

    public function query(mixed $query): int
    {
        $this->queryCalls[] = $query;
        return 1;
    }
}
