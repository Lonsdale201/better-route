<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Resource\Table\TableListQueryParser;
use PHPUnit\Framework\TestCase;

final class TableListQueryParserTest extends TestCase
{
    public function testParsesValidQuery(): void
    {
        $parser = new TableListQueryParser(
            allowedFields: ['id', 'title', 'source'],
            allowedFilters: ['source', 'published'],
            allowedSort: ['id', 'created_at']
        );

        $query = $parser->parse([
            'fields' => 'id,title',
            'source' => 'rss',
            'sort' => '-created_at',
            'page' => '2',
            'per_page' => '10',
        ]);

        self::assertSame(['id', 'title'], $query->fields);
        self::assertSame(['source' => 'rss'], $query->filters);
        self::assertSame('created_at', $query->sortField);
        self::assertSame('DESC', $query->sortDirection);
        self::assertSame(2, $query->page);
        self::assertSame(10, $query->perPage);
    }

    public function testRejectsUnknownParameter(): void
    {
        $parser = new TableListQueryParser(
            allowedFields: ['id'],
            allowedFilters: [],
            allowedSort: ['id']
        );

        $this->expectException(ApiException::class);
        $parser->parse(['unknown' => 'value']);
    }

    public function testRejectsUnsupportedSortField(): void
    {
        $parser = new TableListQueryParser(
            allowedFields: ['id'],
            allowedFilters: [],
            allowedSort: ['id']
        );

        $this->expectException(ApiException::class);
        $parser->parse(['sort' => 'created_at']);
    }

    public function testParsesTypedFilters(): void
    {
        $parser = new TableListQueryParser(
            allowedFields: ['id'],
            allowedFilters: ['published', 'created_at', 'source_id'],
            allowedSort: ['id'],
            filterSchema: [
                'published' => 'bool',
                'created_at' => 'date',
                'source_id' => 'int',
            ]
        );

        $query = $parser->parse([
            'published' => '1',
            'created_at' => '2024-02-03 11:30:00+00:00',
            'source_id' => '9',
        ]);

        self::assertTrue($query->filters['published']);
        self::assertSame('2024-02-03T11:30:00+00:00', $query->filters['created_at']);
        self::assertSame(9, $query->filters['source_id']);
    }

    public function testRejectsDeepPaginationOffset(): void
    {
        $parser = new TableListQueryParser(
            allowedFields: ['id'],
            allowedFilters: [],
            allowedSort: ['id'],
            defaultPerPage: 10,
            maxPerPage: 100,
            maxOffset: 20
        );

        $this->expectException(ApiException::class);
        $parser->parse(['page' => '3', 'per_page' => '11']);
    }
}
