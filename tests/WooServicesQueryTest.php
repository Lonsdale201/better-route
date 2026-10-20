<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\CouponListQueryParser;
use BetterRoute\Integration\Woo\CustomerListQueryParser;
use BetterRoute\Integration\Woo\CustomerRolePolicy;
use BetterRoute\Integration\Woo\WooCouponService;
use PHPUnit\Framework\TestCase;

final class WooServicesQueryTest extends TestCase
{
    public function testConfiguredRolesDefaultToAllowlistAndMayOnlyNarrowIt(): void
    {
        $policy = new CustomerRolePolicy(['customer', 'subscriber']);
        $parser = new CustomerListQueryParser(['id'], rolePolicy: $policy);
        self::assertSame(['customer', 'subscriber'], $parser->parse([])->role);
        self::assertSame(['subscriber'], $parser->parse(['role' => ['subscriber']])->role);
        self::assertSame(['subscriber'], $parser->parse(['role' => 'subscriber,subscriber'])->role);
        self::assertSame(['customer', 'subscriber'], $policy->queryArgs([])['role__in']);
        self::assertContains('manage_options', $policy->queryArgs([])['capability__not_in']);
        $this->expectException(ApiException::class);
        $policy->queryArgs(['administrator']);
    }

    public function testRoleArraysRejectInvalidElementsInsteadOfDroppingThem(): void
    {
        $parser = new CustomerListQueryParser(['id']);
        foreach ([['customer', 7], ['customer', []], [''], ['administrator']] as $roles) {
            try {
                $parser->parse(['role' => $roles]);
                self::fail('Malformed role filter was accepted.');
            } catch (ApiException $exception) {
                self::assertSame(400, $exception->status());
            }
        }
    }

    public function testRoleConfigCannotSilentlyBecomeAnUnrestrictedDirectory(): void
    {
        foreach ([[], [''], ['customer', '*'], ['role' => 'customer']] as $roles) {
            try {
                new CustomerRolePolicy($roles);
                self::fail('Invalid policy was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $optIn = new CustomerRolePolicy(['administrator'], allowPrivilegedRoles: true);
        self::assertArrayNotHasKey('capability__not_in', $optIn->queryArgs([]));
    }

    public function testCouponFiltersAreStrictAndDraftStateSelectsDraftPosts(): void
    {
        $service = new WooCouponService();
        $parser = new CouponListQueryParser($service->allowedFields());
        $query = $parser->parse(['state' => 'draft', 'sort' => '-usage_count']);
        self::assertSame('draft', $query->status);
        self::assertSame('usage_count', $query->sortField);
        self::assertSame('DESC', $query->sortDirection);
        self::assertSame('future', $parser->parse(['state' => 'scheduled'])->status);
        foreach ([['state' => 'unknown'], ['state' => 'active', 'status' => 'draft'], ['expiring_days' => 3651], ['sort' => 'usage_count DESC; DROP TABLE posts'], ['state' => []], ['status' => 'any']] as $params) {
            try {
                $parser->parse($params);
                self::fail('Invalid coupon query accepted.');
            } catch (ApiException $exception) {
                self::assertSame(400, $exception->status());
            }
        }
    }

    public function testCouponRawMetaIsNeverInServiceDefaults(): void
    {
        $service = new WooCouponService();
        self::assertContains('state', $service->listDefaultFields());
        self::assertContains('state', $service->getDefaultFields());
        self::assertNotContains('meta_data', $service->listDefaultFields());
        self::assertNotContains('meta_data', $service->getDefaultFields());
        self::assertContains('meta_data', $service->allowedFields());
        self::assertNotContains('meta_data', (new CouponListQueryParser($service->allowedFields()))->parse([])->fields);
        self::assertNotContains('meta_data', (new CouponListQueryParser($service->allowedFields(), defaultFields: ['id', 'meta_data']))->parse([])->fields);
    }
}
