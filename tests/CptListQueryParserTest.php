<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Resource\Cpt\CptListQueryParser;
use PHPUnit\Framework\TestCase;

final class CptListQueryParserTest extends TestCase
{
    public function testParsesValidQuery(): void
    {
        $parser = new CptListQueryParser(
            allowedFields: ['id', 'title', 'status'],
            allowedFilters: ['status', 'author'],
            allowedSort: ['date', 'id']
        );

        $query = $parser->parse([
            'fields' => 'id,title',
            'status' => 'publish',
            'sort' => '-date',
            'page' => '2',
            'per_page' => '10',
        ]);

        self::assertSame(['id', 'title'], $query->fields);
        self::assertSame(['status' => 'publish'], $query->filters);
        self::assertSame('date', $query->sortField);
        self::assertSame('DESC', $query->sortDirection);
        self::assertSame(2, $query->page);
        self::assertSame(10, $query->perPage);
    }

    public function testRejectsUnknownParams(): void
    {
        $parser = new CptListQueryParser(
            allowedFields: ['id'],
            allowedFilters: ['status'],
            allowedSort: ['id']
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('Invalid request.');
        $parser->parse(['foo' => 'bar']);
    }

    public function testRejectsInvalidFieldProjection(): void
    {
        $parser = new CptListQueryParser(
            allowedFields: ['id'],
            allowedFilters: [],
            allowedSort: ['id']
        );

        $this->expectException(ApiException::class);
        $parser->parse(['fields' => 'title']);
    }

    public function testRejectsTooLargePageSize(): void
    {
        $parser = new CptListQueryParser(
            allowedFields: ['id'],
            allowedFilters: [],
            allowedSort: ['id'],
            defaultPerPage: 20,
            maxPerPage: 100
        );

        $this->expectException(ApiException::class);
        $parser->parse(['per_page' => '101']);
    }
}
