<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\Write\ArrayIdempotencyStore;
use BetterRoute\Middleware\Write\IdempotencyMiddleware;
use BetterRoute\Middleware\Write\IdempotencyStoreInterface;
use BetterRoute\Middleware\Write\TransientIdempotencyStore;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\Router;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;
use RuntimeException;

final class WooRouteRegistrar
{
    public function __construct(
        private readonly HposGuard $guard = new HposGuard(),
        private readonly WooOrderService $orderService = new WooOrderService(),
        private readonly WooProductService $productService = new WooProductService(),
        private readonly WooCustomerService $customerService = new WooCustomerService(),
        private readonly WooCouponService $couponService = new WooCouponService()
    ) {
    }

    /**
     * @param array{
     *   requireHpos?: bool,
     *   basePath?: string,
     *   defaultPerPage?: int,
     *   maxPerPage?: int,
     *   register?: bool,
     *   permissions?: array<string, mixed>,
     *   actions?: array{orders?: list<string>, products?: list<string>, customers?: list<string>, coupons?: list<string>},
     *   idempotency?: array{
     *     enabled?: bool,
     *     requireKey?: bool,
     *     ttlSeconds?: int,
     *     store?: IdempotencyStoreInterface,
     *     resources?: array{orders?: bool, products?: bool, customers?: bool, coupons?: bool}
     *   }
     * } $options
     */
    public function register(
        string $restNamespace,
        array $options = [],
        ?DispatcherInterface $dispatcher = null
    ): Router {
        $namespace = $this->parseRestNamespace($restNamespace);
        $router = Router::make($namespace['vendor'], $namespace['version']);

        $basePath = '/' . trim((string) ($options['basePath'] ?? 'woo'), '/');
        if ($basePath === '//') {
            $basePath = '/woo';
        }

        $requireHpos = ($options['requireHpos'] ?? true) === true;
        $defaultPerPage = max(1, (int) ($options['defaultPerPage'] ?? 20));
        $maxPerPage = max($defaultPerPage, (int) ($options['maxPerPage'] ?? 100));
        $permissions = is_array($options['permissions'] ?? null) ? $options['permissions'] : [];
        $actions = is_array($options['actions'] ?? null) ? $options['actions'] : [];
        $idempotency = $this->resolveIdempotencyOptions($options['idempotency'] ?? null);

        $orderActions = $this->resolveActions($actions['orders'] ?? null);
        $productActions = $this->resolveActions($actions['products'] ?? null);
        $customerActions = $this->resolveActions($actions['customers'] ?? null);
        $couponActions = $this->resolveActions($actions['coupons'] ?? null);

        $orderListParser = new OrderListQueryParser(
            allowedFields: $this->orderService->allowedFields(),
            defaultFields: $this->orderService->listDefaultFields(),
            defaultPerPage: $defaultPerPage,
            maxPerPage: $maxPerPage
        );

        $ordersCreateIdempotency = $this->createIdempotencyMiddleware(
            $idempotency,
            'orders',
            ['POST']
        );
        $ordersUpdateIdempotency = $this->createIdempotencyMiddleware(
            $idempotency,
            'orders',
            ['PUT', 'PATCH']
        );
        $productsCreateIdempotency = $this->createIdempotencyMiddleware(
            $idempotency,
            'products',
            ['POST']
        );
        $productsUpdateIdempotency = $this->createIdempotencyMiddleware(
            $idempotency,
            'products',
            ['PUT', 'PATCH']
        );
        $productListParser = new ProductListQueryParser(
            allowedFields: $this->productService->allowedFields(),
            defaultFields: $this->productService->listDefaultFields(),
            defaultPerPage: $defaultPerPage,
            maxPerPage: $maxPerPage
        );

        $customerListParser = new CustomerListQueryParser(
            allowedFields: $this->customerService->allowedFields(),
            defaultFields: $this->customerService->listDefaultFields(),
            defaultPerPage: $defaultPerPage,
            maxPerPage: $maxPerPage
        );

        $couponListParser = new CouponListQueryParser(
            allowedFields: $this->couponService->allowedFields(),
            defaultFields: $this->couponService->listDefaultFields(),
            defaultPerPage: $defaultPerPage,
            maxPerPage: $maxPerPage
        );

        if (in_array('list', $orderActions, true)) {
            $router->get($basePath . '/orders', function (mixed $request) use ($orderListParser, $requireHpos): array {
                $this->guard->assertReady($requireHpos);
                $query = $orderListParser->parse($request);
                $result = $this->orderService->list($query);

                return [
                    'data' => $result['items'],
                    'meta' => [
                        'page' => $result['page'],
                        'perPage' => $result['perPage'],
                        'total' => $result['total'],
                    ],
                ];
            })->args($this->orderListArgs())
                ->meta([
                    'operationId' => 'wooOrdersList',
                    'tags' => ['WooOrders'],
                    'requestSchema' => null,
                    'responseSchema' => '#/components/schemas/WooOrderListResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['orders.list'] ?? 'manage_woocommerce'));
        }

        if (in_array('get', $orderActions, true)) {
            $router->get($basePath . '/orders/(?P<id>\d+)', function (mixed $request) use ($requireHpos): array {
                $this->guard->assertReady($requireHpos);
                $this->assertAllowedParams($request, ['id', 'fields']);
                $id = $this->readId($request);
                $fields = $this->parseFieldsParameter($request, $this->orderService->allowedFields(), $this->orderService->getDefaultFields());
                $item = $this->orderService->get($id, $fields);
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            })->args([
                'id' => ['required' => true, 'type' => 'integer'],
                'fields' => ['required' => false, 'type' => 'string'],
            ])->meta([
                'operationId' => 'wooOrdersGet',
                'tags' => ['WooOrders'],
                'parameters' => [
                    ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['in' => 'query', 'name' => 'fields', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                'responseSchema' => '#/components/schemas/WooOrderResponse',
            ])
                ->permission($this->resolvePermissionCallback($permissions['orders.get'] ?? 'manage_woocommerce'));
        }

        if (in_array('create', $orderActions, true)) {
            $meta = $this->withIdempotencyOpenApiMeta(
                [
                    'operationId' => 'wooOrdersCreate',
                    'tags' => ['WooOrders'],
                    'requestSchema' => '#/components/schemas/WooOrderInput',
                    'responseSchema' => '#/components/schemas/WooOrderResponse',
                ],
                $ordersCreateIdempotency !== null,
                (bool) $idempotency['requireKey']
            );

            $builder = $router->post($basePath . '/orders', function (mixed $request) use ($requireHpos): Response {
                $this->guard->assertReady($requireHpos);
                $payload = $this->readPayload($request);
                $item = $this->orderService->create($payload, $this->orderService->getDefaultFields());
                return new Response(['data' => $item], 201);
            })
                ->meta($meta)
                ->permission($this->resolvePermissionCallback($permissions['orders.create'] ?? 'manage_woocommerce'));

            if ($ordersCreateIdempotency !== null) {
                $builder->middleware([$ordersCreateIdempotency]);
            }
        }

        if (in_array('update', $orderActions, true)) {
            $updateOrder = function (mixed $request) use ($requireHpos): array {
                $this->guard->assertReady($requireHpos);
                $id = $this->readId($request);
                $payload = $this->readPayload($request);
                $item = $this->orderService->update($id, $payload, $this->orderService->getDefaultFields());
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            };

            $meta = $this->withIdempotencyOpenApiMeta(
                [
                    'operationId' => 'wooOrdersUpdate',
                    'tags' => ['WooOrders'],
                    'parameters' => [
                        ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestSchema' => '#/components/schemas/WooOrderInput',
                    'responseSchema' => '#/components/schemas/WooOrderResponse',
                ],
                $ordersUpdateIdempotency !== null,
                (bool) $idempotency['requireKey']
            );

            $putBuilder = $router->put($basePath . '/orders/(?P<id>\d+)', $updateOrder)
                ->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta($meta)
                ->permission($this->resolvePermissionCallback($permissions['orders.update'] ?? 'manage_woocommerce'));
            $patchBuilder = $router->patch($basePath . '/orders/(?P<id>\d+)', $updateOrder)
                ->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta($meta)
                ->permission($this->resolvePermissionCallback($permissions['orders.update'] ?? 'manage_woocommerce'));

            if ($ordersUpdateIdempotency !== null) {
                $putBuilder->middleware([$ordersUpdateIdempotency]);
                $patchBuilder->middleware([$ordersUpdateIdempotency]);
            }
        }

        if (in_array('delete', $orderActions, true)) {
            $router->delete($basePath . '/orders/(?P<id>\d+)', function (mixed $request) use ($requireHpos): array {
                $this->guard->assertReady($requireHpos);
                $id = $this->readId($request);
                $deleted = $this->orderService->delete($id);
                if (!$deleted) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => ['id' => $id, 'deleted' => true]];
            })->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta([
                    'operationId' => 'wooOrdersDelete',
                    'tags' => ['WooOrders'],
                    'parameters' => [
                        ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responseSchema' => '#/components/schemas/DeleteResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['orders.delete'] ?? 'manage_woocommerce'));
        }

        if (in_array('list', $productActions, true)) {
            $router->get($basePath . '/products', function (mixed $request) use ($productListParser, $requireHpos): array {
                $this->guard->assertReady($requireHpos);
                $query = $productListParser->parse($request);
                $result = $this->productService->list($query);

                return [
                    'data' => $result['items'],
                    'meta' => [
                        'page' => $result['page'],
                        'perPage' => $result['perPage'],
                        'total' => $result['total'],
                    ],
                ];
            })->args($this->productListArgs())
                ->meta([
                    'operationId' => 'wooProductsList',
                    'tags' => ['WooProducts'],
                    'responseSchema' => '#/components/schemas/WooProductListResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['products.list'] ?? 'manage_woocommerce'));
        }

        if (in_array('get', $productActions, true)) {
            $router->get($basePath . '/products/(?P<id>\d+)', function (mixed $request) use ($requireHpos): array {
                $this->guard->assertReady($requireHpos);
                $this->assertAllowedParams($request, ['id', 'fields']);
                $id = $this->readId($request);
                $fields = $this->parseFieldsParameter($request, $this->productService->allowedFields(), $this->productService->getDefaultFields());
                $item = $this->productService->get($id, $fields);
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            })->args([
                'id' => ['required' => true, 'type' => 'integer'],
                'fields' => ['required' => false, 'type' => 'string'],
            ])->meta([
                'operationId' => 'wooProductsGet',
                'tags' => ['WooProducts'],
                'parameters' => [
                    ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['in' => 'query', 'name' => 'fields', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                'responseSchema' => '#/components/schemas/WooProductResponse',
            ])
                ->permission($this->resolvePermissionCallback($permissions['products.get'] ?? 'manage_woocommerce'));
        }

        if (in_array('create', $productActions, true)) {
            $meta = $this->withIdempotencyOpenApiMeta(
                [
                    'operationId' => 'wooProductsCreate',
                    'tags' => ['WooProducts'],
                    'requestSchema' => '#/components/schemas/WooProductInput',
                    'responseSchema' => '#/components/schemas/WooProductResponse',
                ],
                $productsCreateIdempotency !== null,
                (bool) $idempotency['requireKey']
            );

            $builder = $router->post($basePath . '/products', function (mixed $request) use ($requireHpos): Response {
                $this->guard->assertReady($requireHpos);
                $payload = $this->readPayload($request);
                $item = $this->productService->create($payload, $this->productService->getDefaultFields());
                return new Response(['data' => $item], 201);
            })
                ->meta($meta)
                ->permission($this->resolvePermissionCallback($permissions['products.create'] ?? 'manage_woocommerce'));

            if ($productsCreateIdempotency !== null) {
                $builder->middleware([$productsCreateIdempotency]);
            }
        }

        if (in_array('update', $productActions, true)) {
            $updateProduct = function (mixed $request) use ($requireHpos): array {
                $this->guard->assertReady($requireHpos);
                $id = $this->readId($request);
                $payload = $this->readPayload($request);
                $item = $this->productService->update($id, $payload, $this->productService->getDefaultFields());
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            };

            $meta = $this->withIdempotencyOpenApiMeta(
                [
                    'operationId' => 'wooProductsUpdate',
                    'tags' => ['WooProducts'],
                    'parameters' => [
                        ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'requestSchema' => '#/components/schemas/WooProductInput',
                    'responseSchema' => '#/components/schemas/WooProductResponse',
                ],
                $productsUpdateIdempotency !== null,
                (bool) $idempotency['requireKey']
            );

            $putBuilder = $router->put($basePath . '/products/(?P<id>\d+)', $updateProduct)
                ->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta($meta)
                ->permission($this->resolvePermissionCallback($permissions['products.update'] ?? 'manage_woocommerce'));
            $patchBuilder = $router->patch($basePath . '/products/(?P<id>\d+)', $updateProduct)
                ->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta($meta)
                ->permission($this->resolvePermissionCallback($permissions['products.update'] ?? 'manage_woocommerce'));

            if ($productsUpdateIdempotency !== null) {
                $putBuilder->middleware([$productsUpdateIdempotency]);
                $patchBuilder->middleware([$productsUpdateIdempotency]);
            }
        }

        if (in_array('delete', $productActions, true)) {
            $router->delete($basePath . '/products/(?P<id>\d+)', function (mixed $request) use ($requireHpos): array {
                $this->guard->assertReady($requireHpos);
                $id = $this->readId($request);
                $deleted = $this->productService->delete($id);
                if (!$deleted) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => ['id' => $id, 'deleted' => true]];
            })->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta([
                    'operationId' => 'wooProductsDelete',
                    'tags' => ['WooProducts'],
                    'parameters' => [
                        ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responseSchema' => '#/components/schemas/DeleteResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['products.delete'] ?? 'manage_woocommerce'));
        }

        // --- Customers ---

        if (in_array('list', $customerActions, true)) {
            $router->get($basePath . '/customers', function (mixed $request) use ($customerListParser): array {
                $query = $customerListParser->parse($request);
                $result = $this->customerService->list($query);

                return [
                    'data' => $result['items'],
                    'meta' => [
                        'page' => $result['page'],
                        'perPage' => $result['perPage'],
                        'total' => $result['total'],
                    ],
                ];
            })->args($this->customerListArgs())
                ->meta([
                    'operationId' => 'wooCustomersList',
                    'tags' => ['WooCustomers'],
                    'responseSchema' => '#/components/schemas/WooCustomerListResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['customers.list'] ?? 'manage_woocommerce'));
        }

        if (in_array('get', $customerActions, true)) {
            $router->get($basePath . '/customers/(?P<id>\d+)', function (mixed $request): array {
                $this->assertAllowedParams($request, ['id', 'fields']);
                $id = $this->readId($request);
                $fields = $this->parseFieldsParameter($request, $this->customerService->allowedFields(), $this->customerService->getDefaultFields());
                $item = $this->customerService->get($id, $fields);
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            })->args([
                'id' => ['required' => true, 'type' => 'integer'],
                'fields' => ['required' => false, 'type' => 'string'],
            ])->meta([
                'operationId' => 'wooCustomersGet',
                'tags' => ['WooCustomers'],
                'parameters' => [
                    ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['in' => 'query', 'name' => 'fields', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                'responseSchema' => '#/components/schemas/WooCustomerResponse',
            ])
                ->permission($this->resolvePermissionCallback($permissions['customers.get'] ?? 'manage_woocommerce'));
        }

        if (in_array('create', $customerActions, true)) {
            $router->post($basePath . '/customers', function (mixed $request): Response {
                $payload = $this->readPayload($request);
                $item = $this->customerService->create($payload, $this->customerService->getDefaultFields());
                return new Response(['data' => $item], 201);
            })
                ->meta([
                    'operationId' => 'wooCustomersCreate',
                    'tags' => ['WooCustomers'],
                    'requestSchema' => '#/components/schemas/WooCustomerInput',
                    'responseSchema' => '#/components/schemas/WooCustomerResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['customers.create'] ?? 'manage_woocommerce'));
        }

        if (in_array('update', $customerActions, true)) {
            $updateCustomer = function (mixed $request): array {
                $id = $this->readId($request);
                $payload = $this->readPayload($request);
                $item = $this->customerService->update($id, $payload, $this->customerService->getDefaultFields());
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            };

            $customerUpdateMeta = [
                'operationId' => 'wooCustomersUpdate',
                'tags' => ['WooCustomers'],
                'parameters' => [
                    ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
                'requestSchema' => '#/components/schemas/WooCustomerInput',
                'responseSchema' => '#/components/schemas/WooCustomerResponse',
            ];

            $router->put($basePath . '/customers/(?P<id>\d+)', $updateCustomer)
                ->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta($customerUpdateMeta)
                ->permission($this->resolvePermissionCallback($permissions['customers.update'] ?? 'manage_woocommerce'));
            $router->patch($basePath . '/customers/(?P<id>\d+)', $updateCustomer)
                ->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta($customerUpdateMeta)
                ->permission($this->resolvePermissionCallback($permissions['customers.update'] ?? 'manage_woocommerce'));
        }

        if (in_array('delete', $customerActions, true)) {
            $router->delete($basePath . '/customers/(?P<id>\d+)', function (mixed $request): array {
                $id = $this->readId($request);
                $deleted = $this->customerService->delete($id);
                if (!$deleted) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => ['id' => $id, 'deleted' => true]];
            })->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta([
                    'operationId' => 'wooCustomersDelete',
                    'tags' => ['WooCustomers'],
                    'parameters' => [
                        ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responseSchema' => '#/components/schemas/DeleteResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['customers.delete'] ?? 'manage_woocommerce'));
        }

        // --- Coupons ---

        if (in_array('list', $couponActions, true)) {
            $router->get($basePath . '/coupons', function (mixed $request) use ($couponListParser): array {
                $query = $couponListParser->parse($request);
                $result = $this->couponService->list($query);

                return [
                    'data' => $result['items'],
                    'meta' => [
                        'page' => $result['page'],
                        'perPage' => $result['perPage'],
                        'total' => $result['total'],
                    ],
                ];
            })->args($this->couponListArgs())
                ->meta([
                    'operationId' => 'wooCouponsList',
                    'tags' => ['WooCoupons'],
                    'responseSchema' => '#/components/schemas/WooCouponListResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['coupons.list'] ?? 'manage_woocommerce'));
        }

        if (in_array('get', $couponActions, true)) {
            $router->get($basePath . '/coupons/(?P<id>\d+)', function (mixed $request): array {
                $this->assertAllowedParams($request, ['id', 'fields']);
                $id = $this->readId($request);
                $fields = $this->parseFieldsParameter($request, $this->couponService->allowedFields(), $this->couponService->getDefaultFields());
                $item = $this->couponService->get($id, $fields);
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            })->args([
                'id' => ['required' => true, 'type' => 'integer'],
                'fields' => ['required' => false, 'type' => 'string'],
            ])->meta([
                'operationId' => 'wooCouponsGet',
                'tags' => ['WooCoupons'],
                'parameters' => [
                    ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ['in' => 'query', 'name' => 'fields', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                'responseSchema' => '#/components/schemas/WooCouponResponse',
            ])
                ->permission($this->resolvePermissionCallback($permissions['coupons.get'] ?? 'manage_woocommerce'));
        }

        if (in_array('create', $couponActions, true)) {
            $router->post($basePath . '/coupons', function (mixed $request): Response {
                $payload = $this->readPayload($request);
                $item = $this->couponService->create($payload, $this->couponService->getDefaultFields());
                return new Response(['data' => $item], 201);
            })
                ->meta([
                    'operationId' => 'wooCouponsCreate',
                    'tags' => ['WooCoupons'],
                    'requestSchema' => '#/components/schemas/WooCouponInput',
                    'responseSchema' => '#/components/schemas/WooCouponResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['coupons.create'] ?? 'manage_woocommerce'));
        }

        if (in_array('update', $couponActions, true)) {
            $updateCoupon = function (mixed $request): array {
                $id = $this->readId($request);
                $payload = $this->readPayload($request);
                $item = $this->couponService->update($id, $payload, $this->couponService->getDefaultFields());
                if ($item === null) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => $item];
            };

            $couponUpdateMeta = [
                'operationId' => 'wooCouponsUpdate',
                'tags' => ['WooCoupons'],
                'parameters' => [
                    ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                ],
                'requestSchema' => '#/components/schemas/WooCouponInput',
                'responseSchema' => '#/components/schemas/WooCouponResponse',
            ];

            $router->put($basePath . '/coupons/(?P<id>\d+)', $updateCoupon)
                ->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta($couponUpdateMeta)
                ->permission($this->resolvePermissionCallback($permissions['coupons.update'] ?? 'manage_woocommerce'));
            $router->patch($basePath . '/coupons/(?P<id>\d+)', $updateCoupon)
                ->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta($couponUpdateMeta)
                ->permission($this->resolvePermissionCallback($permissions['coupons.update'] ?? 'manage_woocommerce'));
        }

        if (in_array('delete', $couponActions, true)) {
            $router->delete($basePath . '/coupons/(?P<id>\d+)', function (mixed $request): array {
                $id = $this->readId($request);
                $deleted = $this->couponService->delete($id);
                if (!$deleted) {
                    throw new ApiException('Resource not found.', 404, 'not_found');
                }

                return ['data' => ['id' => $id, 'deleted' => true]];
            })->args(['id' => ['required' => true, 'type' => 'integer']])
                ->meta([
                    'operationId' => 'wooCouponsDelete',
                    'tags' => ['WooCoupons'],
                    'parameters' => [
                        ['in' => 'path', 'name' => 'id', 'required' => true, 'schema' => ['type' => 'integer']],
                    ],
                    'responseSchema' => '#/components/schemas/DeleteResponse',
                ])
                ->permission($this->resolvePermissionCallback($permissions['coupons.delete'] ?? 'manage_woocommerce'));
        }

        if (($options['register'] ?? true) !== false) {
            $router->register($dispatcher);
        }

        return $router;
    }

    /**
     * @return array<string, mixed>
     */
    public function openApiComponents(): array
    {
        return WooOpenApiComponents::components();
    }

    /**
     * @return array{vendor: string, version: string}
     */
    private function parseRestNamespace(string $restNamespace): array
    {
        $parts = array_values(array_filter(explode('/', trim($restNamespace, '/')), static fn (string $part): bool => $part !== ''));
        if (count($parts) < 2) {
            throw new \InvalidArgumentException('restNamespace must include vendor and version, e.g. better-route/v1');
        }

        $version = (string) array_pop($parts);

        return [
            'vendor' => implode('/', $parts),
            'version' => $version,
        ];
    }

    /**
     * @return array{
     *   enabled: bool,
     *   requireKey: bool,
     *   ttlSeconds: int,
     *   store: IdempotencyStoreInterface|null,
     *   resources: array{orders: bool, products: bool, customers: bool, coupons: bool}
     * }
     */
    private function resolveIdempotencyOptions(mixed $value): array
    {
        $raw = is_array($value) ? $value : [];
        $resourcesRaw = is_array($raw['resources'] ?? null) ? $raw['resources'] : [];
        $store = $raw['store'] ?? null;

        if ($store !== null && !$store instanceof IdempotencyStoreInterface) {
            throw new \InvalidArgumentException('idempotency.store must implement IdempotencyStoreInterface.');
        }

        return [
            'enabled' => ($raw['enabled'] ?? false) === true,
            'requireKey' => ($raw['requireKey'] ?? false) === true,
            'ttlSeconds' => max(1, (int) ($raw['ttlSeconds'] ?? 300)),
            'store' => $store,
            'resources' => [
                'orders' => ($resourcesRaw['orders'] ?? true) === true,
                'products' => ($resourcesRaw['products'] ?? true) === true,
                'customers' => ($resourcesRaw['customers'] ?? true) === true,
                'coupons' => ($resourcesRaw['coupons'] ?? true) === true,
            ],
        ];
    }

    /**
     * @param array{
     *   enabled: bool,
     *   requireKey: bool,
     *   ttlSeconds: int,
     *   store: IdempotencyStoreInterface|null,
     *   resources: array{orders: bool, products: bool, customers: bool, coupons: bool}
     * } $options
     * @param list<string> $methods
     */
    private function createIdempotencyMiddleware(
        array $options,
        string $resource,
        array $methods
    ): ?IdempotencyMiddleware {
        if (!$options['enabled']) {
            return null;
        }

        if (($options['resources'][$resource] ?? false) !== true) {
            return null;
        }

        $store = $options['store'] ?? $this->defaultIdempotencyStore();

        return new IdempotencyMiddleware(
            store: $store,
            ttlSeconds: $options['ttlSeconds'],
            requireKey: $options['requireKey'],
            methods: $methods
        );
    }

    private function defaultIdempotencyStore(): IdempotencyStoreInterface
    {
        if (function_exists('get_transient') && function_exists('set_transient')) {
            try {
                return new TransientIdempotencyStore();
            } catch (RuntimeException) {
                // Fallback for non-WP runtime.
            }
        }

        return new ArrayIdempotencyStore();
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function withIdempotencyOpenApiMeta(array $meta, bool $enabled, bool $requireKey): array
    {
        if (!$enabled) {
            return $meta;
        }

        $parameters = is_array($meta['parameters'] ?? null) ? $meta['parameters'] : [];
        $hasHeader = false;

        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $location = strtolower((string) ($parameter['in'] ?? ''));
            $name = strtolower((string) ($parameter['name'] ?? ''));
            if ($location === 'header' && $name === 'idempotency-key') {
                $hasHeader = true;
                break;
            }
        }

        if (!$hasHeader) {
            $parameters[] = [
                'in' => 'header',
                'name' => 'Idempotency-Key',
                'required' => $requireKey,
                'schema' => ['type' => 'string'],
                'description' => 'Unique key for safely retrying write requests.',
            ];
        }

        $responses = is_array($meta['responses'] ?? null) ? $meta['responses'] : [];
        $responses['409'] = ['$ref' => '#/components/responses/ErrorResponse'];

        if ($requireKey) {
            $responses['400'] = ['$ref' => '#/components/responses/ErrorResponse'];
        }

        $meta['parameters'] = $parameters;
        $meta['responses'] = $responses;

        return $meta;
    }

    /**
     * @param mixed $rule
     */
    private function resolvePermissionCallback(mixed $rule): callable
    {
        if (is_callable($rule)) {
            return fn (mixed $request): bool => (bool) $this->invokePermissionCallable($rule, $request);
        }

        if (is_bool($rule)) {
            return static fn (): bool => $rule;
        }

        if (is_string($rule) && $rule !== '') {
            return fn (): bool => $this->currentUserCan($rule);
        }

        if (is_array($rule)) {
            $caps = array_values(array_filter(
                array_map(static fn (mixed $cap): string => is_string($cap) ? trim($cap) : '', $rule),
                static fn (string $cap): bool => $cap !== ''
            ));

            if ($caps !== []) {
                return fn (): bool => $this->currentUserCanAny($caps);
            }
        }

        return static fn (): bool => false;
    }

    private function invokePermissionCallable(callable $callable, mixed $request): mixed
    {
        $args = [$request, $this];

        try {
            if (is_array($callable) && count($callable) === 2) {
                $reflection = new ReflectionMethod($callable[0], (string) $callable[1]);
            } else {
                $reflection = new ReflectionFunction(\Closure::fromCallable($callable));
            }

            if ($reflection->isVariadic()) {
                return $callable(...$args);
            }

            return $callable(...array_slice($args, 0, $reflection->getNumberOfParameters()));
        } catch (ReflectionException) {
            return $callable($request);
        }
    }

    private function currentUserCan(string $capability): bool
    {
        if (!function_exists('current_user_can')) {
            return false;
        }

        return (bool) current_user_can($capability);
    }

    /**
     * @param list<string> $capabilities
     */
    private function currentUserCanAny(array $capabilities): bool
    {
        foreach ($capabilities as $capability) {
            if ($this->currentUserCan($capability)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function resolveActions(mixed $value): array
    {
        $default = ['list', 'get', 'create', 'update', 'delete'];
        if (!is_array($value)) {
            return $default;
        }

        $allowed = array_values(array_filter(
            array_map(static fn (mixed $action): string => is_string($action) ? trim(strtolower($action)) : '', $value),
            static fn (string $action): bool => in_array($action, $default, true)
        ));

        return $allowed === [] ? $default : $allowed;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function orderListArgs(): array
    {
        return [
            'fields' => ['required' => false, 'type' => 'string'],
            'status' => ['required' => false, 'type' => 'string'],
            'customer_id' => ['required' => false, 'type' => 'integer'],
            'search' => ['required' => false, 'type' => 'string'],
            'sort' => ['required' => false, 'type' => 'string'],
            'page' => ['required' => false, 'type' => 'integer'],
            'per_page' => ['required' => false, 'type' => 'integer'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function productListArgs(): array
    {
        return [
            'fields' => ['required' => false, 'type' => 'string'],
            'status' => ['required' => false, 'type' => 'string'],
            'type' => ['required' => false, 'type' => 'string'],
            'sku' => ['required' => false, 'type' => 'string'],
            'search' => ['required' => false, 'type' => 'string'],
            'stock_status' => ['required' => false, 'type' => 'string'],
            'sort' => ['required' => false, 'type' => 'string'],
            'page' => ['required' => false, 'type' => 'integer'],
            'per_page' => ['required' => false, 'type' => 'integer'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function customerListArgs(): array
    {
        return [
            'fields' => ['required' => false, 'type' => 'string'],
            'role' => ['required' => false, 'type' => 'string'],
            'email' => ['required' => false, 'type' => 'string'],
            'search' => ['required' => false, 'type' => 'string'],
            'sort' => ['required' => false, 'type' => 'string'],
            'page' => ['required' => false, 'type' => 'integer'],
            'per_page' => ['required' => false, 'type' => 'integer'],
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function couponListArgs(): array
    {
        return [
            'fields' => ['required' => false, 'type' => 'string'],
            'code' => ['required' => false, 'type' => 'string'],
            'search' => ['required' => false, 'type' => 'string'],
            'sort' => ['required' => false, 'type' => 'string'],
            'page' => ['required' => false, 'type' => 'integer'],
            'per_page' => ['required' => false, 'type' => 'integer'],
        ];
    }

    /**
     * @param list<string> $allowed
     */
    private function assertAllowedParams(mixed $request, array $allowed): void
    {
        $params = [];
        if (is_object($request) && method_exists($request, 'get_params')) {
            $raw = $request->get_params();
            if (is_array($raw)) {
                $params = $raw;
            }
        } elseif (is_array($request)) {
            $params = $request;
        }

        if ($params === []) {
            return;
        }

        $unknown = array_values(array_filter(
            array_keys($params),
            static fn (string $key): bool => !in_array($key, $allowed, true)
        ));

        if ($unknown === []) {
            return;
        }

        $fieldErrors = [];
        foreach ($unknown as $key) {
            $fieldErrors[$key] = ['unknown parameter'];
        }

        throw new ApiException('Invalid request.', 400, 'validation_failed', [
            'fieldErrors' => $fieldErrors,
        ]);
    }

    /**
     * @param list<string> $allowedFields
     * @param list<string> $defaultFields
     * @return list<string>
     */
    private function parseFieldsParameter(mixed $request, array $allowedFields, array $defaultFields): array
    {
        $rawFields = null;
        if (is_object($request) && method_exists($request, 'get_param')) {
            $rawFields = $request->get_param('fields');
        } elseif (is_array($request) && array_key_exists('fields', $request)) {
            $rawFields = $request['fields'];
        }

        if ($rawFields === null || $rawFields === '') {
            return $defaultFields;
        }

        if (!is_string($rawFields)) {
            throw new ApiException('Invalid request.', 400, 'validation_failed', [
                'fieldErrors' => ['fields' => ['must be a comma separated string']],
            ]);
        }

        $fields = array_values(array_filter(
            array_map('trim', explode(',', $rawFields)),
            static fn (string $field): bool => $field !== ''
        ));

        if ($fields === []) {
            return $defaultFields;
        }

        $invalid = array_values(array_filter(
            $fields,
            fn (string $field): bool => !in_array($field, $allowedFields, true)
        ));

        if ($invalid !== []) {
            $fieldErrors = [];
            foreach ($invalid as $field) {
                $fieldErrors[$field] = ['field not allowed'];
            }

            throw new ApiException('Invalid request.', 400, 'validation_failed', [
                'fieldErrors' => $fieldErrors,
            ]);
        }

        return $fields;
    }

    /**
     * @return array<string, mixed>
     */
    private function readPayload(mixed $request): array
    {
        $payload = [];

        if (is_object($request) && method_exists($request, 'get_json_params')) {
            $json = $request->get_json_params();
            if (is_array($json)) {
                $payload = $json;
            }
        }

        if ($payload === [] && is_object($request) && method_exists($request, 'get_body_params')) {
            $body = $request->get_body_params();
            if (is_array($body)) {
                $payload = $body;
            }
        }

        if ($payload === [] && is_array($request)) {
            $payload = $request;
        }

        if ($payload === []) {
            throw new ApiException('Invalid request.', 400, 'validation_failed', [
                'fieldErrors' => ['payload' => ['at least one field is required']],
            ]);
        }

        return $payload;
    }

    private function readId(mixed $request): int
    {
        $raw = null;

        if (is_object($request) && method_exists($request, 'get_param')) {
            $raw = $request->get_param('id');
        } elseif (is_array($request) && isset($request['id'])) {
            $raw = $request['id'];
        }

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        throw new ApiException('Invalid request.', 400, 'validation_failed', [
            'fieldErrors' => ['id' => ['must be a positive integer']],
        ]);
    }
}
