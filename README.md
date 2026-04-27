# better-route

[![OPEN BETTER-ROUTE DOCS](https://img.shields.io/badge/OPEN-BETTER--ROUTE%20DOCS-27AE60?style=for-the-badge&labelColor=2C3E50)]([https://lonsdale201.github.io/better-route-docs/docs/getting-started/installation](https://lonsdale201.github.io/better-docs/docs/better-route/getting-started/installation))

A thin PHP 8.1+ REST routing and resource library for WordPress.

Built for headless and integration-heavy projects where you want a stable, versioned API contract on top of WP.

## What It Gives You

- Fluent REST router on top of `register_rest_route()`
- Middleware pipeline (`global -> group -> route`)
- Resource DSL for:
  - CPT-backed endpoints
  - custom table-backed endpoints
- Strict query contract (unknown params -> `400`)
- Payload schema + field-level write policy for Resource writes
- Unified error payload with `requestId`
- Built-in auth bridge middlewares:
  - JWT/Bearer
  - cookie + nonce
  - application password
- Write safety middlewares:
  - idempotency key
  - optimistic lock (`If-Match` / version)
- Read safety/caching helpers:
  - ETag / `If-None-Match`
  - identity-aware cache and rate-limit keys
- Observability baseline:
  - audit event schema
  - metrics middleware
  - Prometheus-friendly sink

## Install

From public GitHub via Composer (`type: vcs`):

```json
{
  "require": {
    "better-route/better-route": "^0.3.0"
  },
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/Lonsdale201/better-route"
    }
  ]
}
```

For local development only, you can still use path repository + symlink.

## Quick Start

```php
use BetterRoute\Router\Router;

add_action('rest_api_init', function () {
    Router::make('better-route', 'v1')
        ->get('/ping', fn () => ['pong' => true])
        ->meta(['operationId' => 'ping', 'tags' => ['System']]);
});
```

## Router + Middleware Example

```php
use BetterRoute\Router\Router;
use BetterRoute\Middleware\Jwt\JwtAuthMiddleware;
use BetterRoute\Middleware\Jwt\Hs256JwtVerifier;
use BetterRoute\Middleware\Auth\WpClaimsUserMapper;

add_action('rest_api_init', function () {
    $jwt = new Hs256JwtVerifier(
        secret: $_ENV['JWT_SECRET'],
        expectedIssuer: 'https://auth.example.com',
        expectedAudience: 'better-route'
    );

    $router = Router::make('better-route', 'v1')
        ->middlewareFactory(function (string $class) use ($jwt) {
            if ($class === JwtAuthMiddleware::class) {
                return new JwtAuthMiddleware($jwt, ['content:*'], new WpClaimsUserMapper());
            }

            return null;
        });

    $router->group('/secure', function (Router $r): void {
        $r->middleware([JwtAuthMiddleware::class]);

        $r->get('/me', fn () => ['ok' => true])
            ->meta(['operationId' => 'secureMe', 'tags' => ['Auth']]);
    });

    $router->register();
});
```

## Resource DSL Examples

### CPT Resource

```php
use BetterRoute\Resource\Resource;
use BetterRoute\Resource\ResourcePolicy;

add_action('rest_api_init', function () {
    Resource::make('articles')
        ->restNamespace('better-route/v1')
        ->sourceCpt('post')
        ->allow(['list', 'get', 'create', 'update', 'delete'])
        ->fields(['id', 'title', 'slug', 'excerpt', 'content', 'date', 'status', 'author'])
        ->filters(['status', 'author', 'after', 'before'])
        ->filterSchema([
            'status' => ['type' => 'enum', 'values' => ['publish', 'draft', 'private']],
            'author' => 'int',
            'after' => 'date',
            'before' => 'date',
        ])
        ->sort(['date', 'id'])
        ->policy([
            'permissions' => [
                'list' => true,
                'get' => true,
                'create' => 'edit_posts',
                'update' => 'edit_posts',
                'delete' => 'delete_posts',
            ],
        ])
        ->fieldPolicy([
            'status' => ['write' => 'publish_posts'],
            'author' => ['write' => 'edit_others_posts'],
        ])
        ->writeSchema([
            'title' => ['type' => 'string', 'required' => true, 'minLength' => 3, 'sanitize' => 'text'],
            'status' => ['type' => 'enum', 'values' => ['draft', 'publish']],
            'author' => ['type' => 'int', 'min' => 1],
        ])
        ->deleteMode('trash')
        ->maxPerPage(100)
        ->maxOffset(5000)
        ->register();
});
```

### Custom Table Resource

```php
use BetterRoute\Resource\Resource;

add_action('rest_api_init', function () {
    Resource::make('raw-articles')
        ->restNamespace('better-route/v1')
        ->sourceTable('ai_raw_articles', 'id')
        ->allow(['list', 'get', 'create', 'update', 'delete'])
        ->fields(['id', 'source', 'title', 'lang', 'published', 'version', 'created_at', 'updated_at'])
        ->filters(['source', 'lang', 'published'])
        ->filterSchema([
            'source' => 'string',
            'lang' => 'string',
            'published' => 'bool',
        ])
        ->policy(ResourcePolicy::adminOnly())
        ->writeSchema([
            'title' => ['type' => 'string', 'required' => true, 'sanitize' => 'text'],
            'published' => ['type' => 'bool'],
            'lang' => ['type' => 'enum', 'values' => ['hu', 'en']],
        ])
        ->sort(['created_at', 'id'])
        ->maxPerPage(100)
        ->maxOffset(5000)
        ->register();
});
```

## Built-in Middlewares

### Auth bridge

- `BetterRoute\Middleware\Jwt\JwtAuthMiddleware`
- `BetterRoute\Middleware\Auth\BearerTokenAuthMiddleware`
- `BetterRoute\Middleware\Auth\CookieNonceAuthMiddleware`
- `BetterRoute\Middleware\Auth\ApplicationPasswordAuthMiddleware`
- `BetterRoute\Middleware\Auth\WpClaimsUserMapper`

### Write safety

- `BetterRoute\Middleware\Write\IdempotencyMiddleware`
- `BetterRoute\Middleware\Write\WpdbIdempotencyStore`
- `BetterRoute\Middleware\Write\OptimisticLockMiddleware`
- `BetterRoute\Http\ConflictException` (`409`)
- `BetterRoute\Http\PreconditionFailedException` (`412`)

### Cache / conditional reads

- `BetterRoute\Middleware\Cache\CachingMiddleware`
- `BetterRoute\Middleware\Cache\ETagMiddleware`

### Rate limiting

- `BetterRoute\Middleware\RateLimit\RateLimitMiddleware`
- `BetterRoute\Middleware\RateLimit\TransientRateLimiter`
- `BetterRoute\Middleware\RateLimit\WpObjectCacheRateLimiter`
- `BetterRoute\Http\ClientIpResolver`

### Observability

- `BetterRoute\Middleware\Audit\AuditMiddleware`
- `BetterRoute\Middleware\Observability\MetricsMiddleware`
- `BetterRoute\Observability\AuditEventFactory`
- `BetterRoute\Observability\PrometheusMetricSink`

## OpenAPI (MVP)

The library already stores route/resource metadata for OpenAPI.  
You can export a document from contracts and optionally expose it as a REST endpoint.

### Manual export from contracts

```php
use BetterRoute\OpenApi\OpenApiExporter;
use BetterRoute\BetterRoute;

$contracts = array_merge(
    $router->contracts(true),
    $articlesResource->contracts(true)
);

$openApi = (new OpenApiExporter())->export($contracts, [
    'title' => 'better-route API',
    'version' => 'v0.1.0',
    'serverUrl' => '/wp-json',
    'strictSchemas' => true,
    'components' => array_replace_recursive(
        BetterRoute::wooOpenApiComponents(),
        [
            'schemas' => [
                // Your non-Woo schemas can be merged here
            ],
        ]
    ),
    // Reusable security definitions, merged into components.securitySchemes
    'securitySchemes' => [
        'bearerAuth' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'JWT',
        ],
        'cookieNonce' => [
            'type' => 'apiKey',
            'in' => 'header',
            'name' => 'X-WP-Nonce',
        ],
    ],
    // Document-level default security; per-route meta['security'] overrides.
    'globalSecurity' => [
        ['bearerAuth' => []],
    ],
]);
```

Per-route overrides live in route `meta`:

```php
$router->get('/public/ping', fn () => ['pong' => true])
    ->meta([
        'operationId' => 'publicPing',
        'security' => [],            // explicit no-auth (overrides globalSecurity)
    ]);

$router->post('/admin/reset', fn () => ['ok' => true])
    ->meta([
        'operationId' => 'adminReset',
        'security' => [['bearerAuth' => ['admin:write']]],
    ]);
```

### Register `openapi.json` endpoint

```php
use BetterRoute\OpenApi\OpenApiRouteRegistrar;

OpenApiRouteRegistrar::register(
    restNamespace: 'better-route/v1',
    contractsProvider: static fn (): array => OpenApiRouteRegistrar::contractsFromSources([
        $router,
        $articlesResource,
    ]),
    options: [
        'title' => 'better-route API',
        'version' => 'v0.1.0',
        'serverUrl' => '/wp-json',
        // Defaults to manage_options when omitted.
        'permissionCallback' => static fn (): bool => current_user_can('manage_options'),
    ]
);
```

Result endpoint: `GET /wp-json/better-route/v1/openapi.json`

## WooCommerce HPOS Integration (Optional)

The library can register WooCommerce routes as an optional integration layer.  
This is **extra support** and does not affect non-Woo projects.

```php
use BetterRoute\BetterRoute;

add_action('rest_api_init', function () {
    $woo = BetterRoute::wooRouteRegistrar()->register('better-route/v1', [
        'requireHpos' => true, // HPOS-only guard
        'basePath' => 'woo',
        'deleteMode' => 'trash', // force|trash for orders/products/coupons
        'idempotency' => [
            'enabled' => true,
            'requireKey' => true,
            'ttlSeconds' => 600,
            // optional:
            // 'resources' => ['orders' => true, 'products' => true],
            // 'store' => new CustomIdempotencyStore(),
        ],
        'permissions' => [
            // defaults are manage_woocommerce for all actions
            'orders.list' => 'manage_woocommerce',
            'orders.get' => 'manage_woocommerce',
            'orders.create' => 'manage_woocommerce',
            'orders.update' => 'manage_woocommerce',
            'orders.delete' => 'manage_woocommerce',
            'products.list' => 'manage_woocommerce',
            'products.get' => 'manage_woocommerce',
            'products.create' => 'manage_woocommerce',
            'products.update' => 'manage_woocommerce',
            'products.delete' => 'manage_woocommerce',
            'customers.list' => 'manage_woocommerce',
            'customers.get' => 'manage_woocommerce',
            'customers.create' => 'manage_woocommerce',
            'customers.update' => 'manage_woocommerce',
            'customers.delete' => 'manage_woocommerce',
            'coupons.list' => 'manage_woocommerce',
            'coupons.get' => 'manage_woocommerce',
            'coupons.create' => 'manage_woocommerce',
            'coupons.update' => 'manage_woocommerce',
            'coupons.delete' => 'manage_woocommerce',
        ],
        // Optional — restrict which CRUD actions each resource exposes.
        // Omit a key to get the full `['list', 'get', 'create', 'update', 'delete']` set.
        'actions' => [
            'customers' => ['list', 'get'], // read-only customers, full CRUD elsewhere
        ],
    ]);

    // Optional OpenAPI export from registered Woo routes:
    // $contracts = $woo->contracts(true);
    // $components = BetterRoute::wooOpenApiComponents();
});
```

Registered endpoints under `/wp-json/<vendor>/<version>/woo`:

- orders: `list`, `get`, `create`, `update` (`PUT`/`PATCH`), `delete`
- products: `list`, `get`, `create`, `update` (`PUT`/`PATCH`), `delete`
- customers: `list`, `get`, `create`, `update` (`PUT`/`PATCH`), `delete`
- coupons: `list`, `get`, `create`, `update` (`PUT`/`PATCH`), `delete`

When idempotency is enabled, write routes document and accept `Idempotency-Key` header, and may return:

- `409` for `idempotency_conflict`
- `400` for `idempotency_key_required` (if `requireKey=true`)

## Error Contract

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Invalid request.",
    "requestId": "req_...",
    "details": {
      "fieldErrors": {
        "title": ["required"]
      }
    }
  }
}
```

## Local Quality Commands

```bash
composer test
composer analyse
composer cs-check
```

## Current Status

Active development.

## Changelog

### 0.3.0

Security and hardening:

- Fixed route `id` handling so resource and Woo endpoints prefer URL route parameters over merged request parameters.
- Hardened error responses so unexpected server errors no longer expose internal exception messages or classes.
- Hardened JWT handling with required `exp` by default, optional issuer/audience checks, max lifetime, and max token size.
- Removed default numeric `sub` to WP user ID mapping from `WpClaimsUserMapper`.
- Made custom table resource reads deny-by-default unless an explicit policy is configured.
- Made cache, idempotency, and rate-limit default keys identity-aware.
- Restricted Woo customer endpoints to customer users and added user capability checks for create/update/delete operations.
- Protected Woo meta keys (`_...`) are no longer writable or returned by default.
- Hardened CPT writes with WordPress capability checks around publish/status/author/delete operations.
- Hardened `WpdbAdapter` by rejecting cross-database table names and structured write payloads.
- OpenAPI document route now defaults to `manage_options` instead of public access.
- Sanitized accepted `X-Request-ID` values.

New features:

- Added Resource `writeSchema()` / `payloadSchema()` for write validation, coercion, sanitization, required fields, ranges, lengths, regex, enum, email, and URL checks.
- Added Resource `fieldPolicy()` for field-level write authorization.
- Added `ResourcePolicy` presets: `adminOnly()`, `publicReadPrivateWrite()`, `capabilities()`, and `callbacks()`.
- Added `deleteMode('trash'|'force')` for CPT resources.
- Added Woo `deleteMode` option for orders, products, and coupons.
- Added strict OpenAPI schema mode via `strictSchemas => true`.
- Added `ETagMiddleware` with `If-None-Match` / `304 Not Modified` support.
- Added `ClientIpResolver` with trusted proxy support.
- Added `WpObjectCacheRateLimiter`.
- Added `WpdbIdempotencyStore`.

Developer experience:

- Composer scripts now run tools through `php vendor/bin/...`, avoiding executable-bit issues on some deployments.
- Expanded regression coverage for security defaults, resource validation, OpenAPI strict mode, ETag handling, and WP-backed stores.
