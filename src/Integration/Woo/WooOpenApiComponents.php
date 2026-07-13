<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

final class WooOpenApiComponents
{
    /**
     * @return array<string, mixed>
     */
    public static function components(): array
    {
        return [
            'schemas' => [
                'MetaDataEntry' => [
                    'type' => 'object',
                    'required' => ['key'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'key' => ['type' => 'string'],
                        'value' => [],
                    ],
                    'additionalProperties' => false,
                ],
                'WooOrderAddress' => [
                    'type' => 'object',
                    'properties' => [
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'company' => ['type' => 'string'],
                        'address_1' => ['type' => 'string'],
                        'address_2' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'state' => ['type' => 'string'],
                        'postcode' => ['type' => 'string'],
                        'country' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'WooOrderLineItemInput' => [
                    'type' => 'object',
                    'required' => ['product_id'],
                    'properties' => [
                        'product_id' => ['type' => 'integer'],
                        'variation_id' => ['type' => 'integer'],
                        'quantity' => ['type' => 'integer'],
                        'total' => ['type' => 'string'],
                        'subtotal' => ['type' => 'string'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooOrderLineItem' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'product_id' => ['type' => 'integer'],
                        'variation_id' => ['type' => 'integer'],
                        'quantity' => ['type' => 'integer'],
                        'total' => ['type' => 'string'],
                        'subtotal' => ['type' => 'string'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooOrderInput' => [
                    'type' => 'object',
                    'properties' => [
                        'status' => ['type' => 'string'],
                        'customer_id' => ['type' => 'integer'],
                        'currency' => ['type' => 'string'],
                        'payment_method' => ['type' => 'string'],
                        'payment_method_title' => ['type' => 'string'],
                        'billing' => ['$ref' => '#/components/schemas/WooOrderAddress'],
                        'shipping' => ['$ref' => '#/components/schemas/WooOrderAddress'],
                        'customer_note' => ['type' => 'string'],
                        'set_paid' => ['type' => 'boolean'],
                        'line_items' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/WooOrderLineItemInput'],
                        ],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooOrder' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'number' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'currency' => ['type' => 'string'],
                        'total' => ['type' => 'string'],
                        'total_tax' => ['type' => 'string'],
                        'customer_id' => ['type' => 'integer'],
                        'billing_email' => ['type' => 'string'],
                        'payment_method' => ['type' => 'string'],
                        'payment_method_title' => ['type' => 'string'],
                        'date_created' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'date_modified' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'billing' => ['$ref' => '#/components/schemas/WooOrderAddress'],
                        'shipping' => ['$ref' => '#/components/schemas/WooOrderAddress'],
                        'customer_note' => ['type' => 'string'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                        'line_items' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/WooOrderLineItem'],
                        ],
                    ],
                    'additionalProperties' => true,
                ],
                'WooOrderResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => ['$ref' => '#/components/schemas/WooOrder'],
                    ],
                    'additionalProperties' => false,
                ],
                'WooOrderListResponse' => [
                    'type' => 'object',
                    'required' => ['data', 'meta'],
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/WooOrder'],
                        ],
                        'meta' => [
                            'type' => 'object',
                            'required' => ['page', 'perPage', 'total'],
                            'properties' => [
                                'page' => ['type' => 'integer'],
                                'perPage' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooProductInput' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'sku' => ['type' => 'string'],
                        'regular_price' => ['type' => 'string'],
                        'sale_price' => ['type' => 'string'],
                        'catalog_visibility' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'short_description' => ['type' => 'string'],
                        'stock_status' => ['type' => 'string'],
                        'stock_quantity' => ['type' => ['integer', 'null']],
                        'manage_stock' => ['type' => 'boolean'],
                        'virtual' => ['type' => 'boolean'],
                        'downloadable' => ['type' => 'boolean'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooProduct' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'slug' => ['type' => 'string'],
                        'status' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'sku' => ['type' => 'string'],
                        'price' => ['type' => 'string'],
                        'regular_price' => ['type' => 'string'],
                        'sale_price' => ['type' => 'string'],
                        'date_created' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'date_modified' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'catalog_visibility' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'short_description' => ['type' => 'string'],
                        'stock_status' => ['type' => 'string'],
                        'stock_quantity' => ['type' => ['integer', 'null']],
                        'manage_stock' => ['type' => 'boolean'],
                        'virtual' => ['type' => 'boolean'],
                        'downloadable' => ['type' => 'boolean'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => true,
                ],
                'WooProductResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => ['$ref' => '#/components/schemas/WooProduct'],
                    ],
                    'additionalProperties' => false,
                ],
                'WooProductListResponse' => [
                    'type' => 'object',
                    'required' => ['data', 'meta'],
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/WooProduct'],
                        ],
                        'meta' => [
                            'type' => 'object',
                            'required' => ['page', 'perPage', 'total'],
                            'properties' => [
                                'page' => ['type' => 'integer'],
                                'perPage' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooCustomerAddress' => [
                    'type' => 'object',
                    'properties' => [
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'company' => ['type' => 'string'],
                        'address_1' => ['type' => 'string'],
                        'address_2' => ['type' => 'string'],
                        'city' => ['type' => 'string'],
                        'state' => ['type' => 'string'],
                        'postcode' => ['type' => 'string'],
                        'country' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'phone' => ['type' => 'string'],
                    ],
                    'additionalProperties' => false,
                ],
                'WooCustomerInput' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string', 'format' => 'email'],
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'username' => ['type' => 'string'],
                        'password' => ['type' => 'string', 'format' => 'password'],
                        'billing' => ['$ref' => '#/components/schemas/WooCustomerAddress'],
                        'shipping' => ['$ref' => '#/components/schemas/WooCustomerAddress'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooCustomerCreateInput' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/WooCustomerInput'],
                        ['required' => ['email']],
                    ],
                ],
                'WooCustomer' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'email' => ['type' => 'string'],
                        'first_name' => ['type' => 'string'],
                        'last_name' => ['type' => 'string'],
                        'display_name' => ['type' => 'string'],
                        'role' => ['type' => 'string'],
                        'username' => ['type' => 'string'],
                        'date_created' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'date_modified' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'billing' => ['$ref' => '#/components/schemas/WooCustomerAddress'],
                        'shipping' => ['$ref' => '#/components/schemas/WooCustomerAddress'],
                        'is_paying_customer' => ['type' => 'boolean'],
                        'avatar_url' => ['type' => 'string'],
                        'orders_count' => ['type' => 'integer'],
                        'total_spent' => ['type' => 'string'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => true,
                ],
                'WooCustomerResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => ['$ref' => '#/components/schemas/WooCustomer'],
                    ],
                    'additionalProperties' => false,
                ],
                'WooCustomerListResponse' => [
                    'type' => 'object',
                    'required' => ['data', 'meta'],
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/WooCustomer'],
                        ],
                        'meta' => [
                            'type' => 'object',
                            'required' => ['page', 'perPage', 'total'],
                            'properties' => [
                                'page' => ['type' => 'integer'],
                                'perPage' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooCouponInput' => [
                    'type' => 'object',
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'amount' => ['type' => 'string'],
                        'discount_type' => ['type' => 'string', 'enum' => ['percent', 'fixed_cart', 'fixed_product']],
                        'description' => ['type' => 'string'],
                        'date_expires' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'usage_limit' => ['type' => 'integer'],
                        'usage_limit_per_user' => ['type' => 'integer'],
                        'limit_usage_to_x_items' => ['type' => ['integer', 'null']],
                        'individual_use' => ['type' => 'boolean'],
                        'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'excluded_product_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'free_shipping' => ['type' => 'boolean'],
                        'minimum_amount' => ['type' => 'string'],
                        'maximum_amount' => ['type' => 'string'],
                        'email_restrictions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'exclude_sale_items' => ['type' => 'boolean'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'WooCouponCreateInput' => [
                    'allOf' => [
                        ['$ref' => '#/components/schemas/WooCouponInput'],
                        ['required' => ['code']],
                    ],
                ],
                'WooCoupon' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'code' => ['type' => 'string'],
                        'amount' => ['type' => 'string'],
                        'discount_type' => ['type' => 'string'],
                        'description' => ['type' => 'string'],
                        'date_created' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'date_modified' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'date_expires' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                        'usage_count' => ['type' => 'integer'],
                        'usage_limit' => ['type' => 'integer'],
                        'usage_limit_per_user' => ['type' => 'integer'],
                        'limit_usage_to_x_items' => ['type' => ['integer', 'null']],
                        'individual_use' => ['type' => 'boolean'],
                        'product_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'excluded_product_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                        'free_shipping' => ['type' => 'boolean'],
                        'minimum_amount' => ['type' => 'string'],
                        'maximum_amount' => ['type' => 'string'],
                        'email_restrictions' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'exclude_sale_items' => ['type' => 'boolean'],
                        'meta_data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/MetaDataEntry'],
                        ],
                    ],
                    'additionalProperties' => true,
                ],
                'WooCouponResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => ['$ref' => '#/components/schemas/WooCoupon'],
                    ],
                    'additionalProperties' => false,
                ],
                'WooCouponListResponse' => [
                    'type' => 'object',
                    'required' => ['data', 'meta'],
                    'properties' => [
                        'data' => [
                            'type' => 'array',
                            'items' => ['$ref' => '#/components/schemas/WooCoupon'],
                        ],
                        'meta' => [
                            'type' => 'object',
                            'required' => ['page', 'perPage', 'total'],
                            'properties' => [
                                'page' => ['type' => 'integer'],
                                'perPage' => ['type' => 'integer'],
                                'total' => ['type' => 'integer'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
                'DeleteResponse' => [
                    'type' => 'object',
                    'required' => ['data'],
                    'properties' => [
                        'data' => [
                            'type' => 'object',
                            'required' => ['id', 'deleted'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'deleted' => ['type' => 'boolean'],
                            ],
                            'additionalProperties' => false,
                        ],
                    ],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
