<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\MetaDataHelper;
use PHPUnit\Framework\TestCase;

final class WooMetaDataHelperTest extends TestCase
{
    public function testCustomerMetadataRejectsSecurityKeysInBothInputShapes(): void
    {
        foreach (['capabilities', 'wp_capabilities', 'customprefixcapabilities', 'custom_17_capabilities', 'WP_17_CAPABILITIES ', 'user_level', 'custom_user_level', 'session_tokens', 'custom_session_tokens', '_application_passwords', 'application_passwords', 'role', 'roles', 'user_pass', 'user_activation_key', 'primary_blog', 'active_blog', 'source_domain', 'spam', 'deleted', 'use_ssl'] as $key) {
            foreach ([[$key => ['administrator' => true]], [['key' => $key, 'value' => ['administrator' => true]]]] as $payload) {
                try {
                    MetaDataHelper::normalizeCustomerIncoming($payload);
                    self::fail('Security metadata accepted: ' . $key);
                } catch (ApiException $exception) {
                    self::assertSame(400, $exception->status());
                }
            }
        }
    }

    public function testCustomerSerializationFiltersSecurityKeysAndPreservesCustomValues(): void
    {
        $safe = [['key' => 'loyalty_id', 'value' => 'customer-123']];
        self::assertSame($safe, MetaDataHelper::normalizeCustomerIncoming($safe));
        $object = new class () {
            /** @return array<string, mixed> */
            public function get_data(): array
            {
                return ['key' => 'custom_17_capabilities', 'value' => ['administrator' => true]];
            }
        };
        self::assertSame($safe, MetaDataHelper::serializeCustomer([
            ...$safe, $object, ['key' => 'session_tokens', 'value' => 'secret'], ['key' => '_application_passwords', 'value' => 'secret'],
        ]));
        $generic = [['key' => 'capabilities', 'value' => 'display']];
        self::assertSame($generic, MetaDataHelper::normalizeIncoming($generic));
    }

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
