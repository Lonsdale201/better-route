<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\CustomerListQueryParser;
use PHPUnit\Framework\TestCase;

final class WooCustomerListQueryParserTest extends TestCase
{
    public function testDefaultsCustomerListToCustomerRole(): void
    {
        $query = (new CustomerListQueryParser(['id', 'email']))->parse([]);

        self::assertSame(['customer'], $query->role);
    }

    public function testRejectsNonCustomerRoleFilter(): void
    {
        $this->expectException(ApiException::class);

        (new CustomerListQueryParser(['id', 'email']))->parse([
            'role' => 'administrator',
        ]);
    }
}
