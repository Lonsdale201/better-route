# better-route

A thin PHP 8.1+ REST routing and resource library for WordPress.

Built for headless and integration-heavy projects where you want a stable, versioned API contract on top of WP.

## What It Gives You

- Fluent REST router on top of `register_rest_route()`
- Middleware pipeline (`global -> group -> route`)
- Resource DSL for:
  - CPT-backed endpoints
  - custom table-backed endpoints
- Strict query contract (unknown params -> `400`)
- Unified error payload with `requestId`
- Built-in auth bridge middlewares:
  - JWT/Bearer
  - cookie + nonce
  - application password
- Write safety middlewares:
  - idempotency key
  - optimistic lock (`If-Match` / version)
- Observability baseline:
  - audit event schema
  - metrics middleware
  - Prometheus-friendly sink

## Install

For local development (path repository + symlink):

```json
{
  "require": {
    "better-route/better-route": "*"
  },
  "repositories": [
    {
      "type": "path",
      "url": "../../../libraries/better-route",
      "options": { "symlink": true }
    }
  ]
}
```

After package publication, consumers can use normal Composer constraint (for example `^1.0`).

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
    $jwt = new Hs256JwtVerifier($_ENV['JWT_SECRET']);

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
- `BetterRoute\Middleware\Write\OptimisticLockMiddleware`
- `BetterRoute\Http\ConflictException` (`409`)
- `BetterRoute\Http\PreconditionFailedException` (`412`)

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

$contracts = array_merge(
    $router->contracts(true),
    $articlesResource->contracts(true)
);

$openApi = (new OpenApiExporter())->export($contracts, [
    'title' => 'better-route API',
    'version' => 'v0.1.0',
    'serverUrl' => '/wp-json',
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
    ]
);
```

Result endpoint: `GET /wp-json/better-route/v1/openapi.json`

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
