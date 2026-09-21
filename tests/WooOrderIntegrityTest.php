<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Integration\Woo\StockQuantity;
use BetterRoute\Integration\Woo\WooOpenApiComponents;
use BetterRoute\Integration\Woo\WooOrderService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class WooOrderIntegrityTest extends TestCase
{
    public function testAddressChangesCalculateBeforeRequestedStatusIsStaged(): void
    {
        foreach (['billing', 'shipping'] as $address) {
            $order = new class () {
                /** @var list<string> */
                public array $trace = [];
                public function set_address(mixed $value, string $type): void
                {
                    $this->trace[] = 'address:' . $type;
                }
                public function calculate_totals(bool $taxes): void
                {
                    $this->trace[] = 'totals';
                }
                public function set_status(string $status): void
                {
                    $this->trace[] = 'status:' . $status;
                }
            };
            (new ReflectionMethod(WooOrderService::class, 'applyPayload'))->invoke(
                new WooOrderService(),
                $order,
                [$address => ['country' => 'DE'], 'status' => 'processing'],
                false
            );
            self::assertSame(['address:' . $address, 'totals', 'status:processing'], $order->trace);
        }
    }

    public function testPaymentCompletionFollowsSaveAndIsNotRepeatedForPaidUpdates(): void
    {
        $order = new class () {
            /** @var list<string> */
            public array $trace = [];
            public bool $paid = false;
            public function save(): void
            {
                $this->trace[] = 'save';
            }
            public function needs_payment(): bool
            {
                return !$this->paid;
            }
            public function payment_complete(): void
            {
                $this->trace[] = 'paid';
                $this->paid = true;
            }
        };
        $persist = new ReflectionMethod(WooOrderService::class, 'persistPayload');
        $persist->invoke(new WooOrderService(), $order, ['set_paid' => true], false);
        $persist->invoke(new WooOrderService(), $order, ['set_paid' => true], false);
        self::assertSame(['save', 'paid', 'save'], $order->trace);
    }

    public function testStoredFractionalQuantityIsNotTruncated(): void
    {
        $order = new class () {
            /** @return list<object> */
            public function get_items(string $type): array
            {
                return [new class () {
                    public function get_quantity(): float
                    {
                        return 0.5;
                    }
                }];
            }
        };
        $lines = (new ReflectionMethod(WooOrderService::class, 'mapLineItems'))->invoke(new WooOrderService(), $order);
        self::assertSame(0.5, $lines[0]['quantity']);
    }

    public function testQuantitiesAcceptFiniteDecimalsAndNegativeInventory(): void
    {
        self::assertSame(0.5, StockQuantity::parse('0.5', 'quantity', true));
        self::assertSame(1, StockQuantity::parse(1, 'quantity', true));
        self::assertSame(-2.5, StockQuantity::parse(-2.5, 'stock_quantity'));
        $schemas = WooOpenApiComponents::components()['schemas'];
        self::assertSame(['type' => 'number', 'exclusiveMinimum' => 0], $schemas['WooOrderLineItemInput']['properties']['quantity']);
        self::assertSame('number', $schemas['WooOrderLineItem']['properties']['quantity']['type']);
    }

    public function testInvalidOrderQuantitiesCannotBecomeValidThroughNumericCasts(): void
    {
        foreach ([true, false, null, [], 'bad', INF, NAN, 0, -1, '1e999'] as $value) {
            try {
                StockQuantity::parse($value, 'quantity', true);
                self::fail('Invalid quantity was accepted.');
            } catch (ApiException $exception) {
                self::assertSame('validation_failed', $exception->errorCode());
            }
        }
    }
}
