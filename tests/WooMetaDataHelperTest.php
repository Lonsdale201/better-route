<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\MetaDataHelper;
use PHPUnit\Framework\TestCase;

final class WooMetaDataHelperTest extends TestCase
{
    public function testRejectsProtectedIncomingMetaKeysByDefault(): void
    {
        $this->expectException(ApiException::class);

        MetaDataHelper::normalizeIncoming([
            ['key' => '_internal_key', 'value' => 'x'],
        ]);
    }

    public function testSerializeOmitsProtectedMetaKeysByDefault(): void
    {
        $meta = MetaDataHelper::serialize([
            ['key' => '_internal_key', 'value' => 'x'],
            ['key' => 'public_key', 'value' => 'y'],
        ]);

        self::assertSame([
            ['key' => 'public_key', 'value' => 'y'],
        ], $meta);
    }
}
