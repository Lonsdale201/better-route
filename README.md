# better-route

Thin PHP 8.1+ REST routing and resource library for WordPress (headless and integration use-cases).

## Status

Early development (`0.1.0-dev`).

## Local development

1. Install dependencies in the library:

```bash
composer install
```

2. Run quality checks:

```bash
composer test
composer analyse
composer cs-check
```

3. Load from a host plugin with Composer `path` repository:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "../../../libraries/better-route",
      "options": {
        "symlink": true
      }
    }
  ]
}
```

## Current scope

- M1 done: router, dispatcher bridge, middleware pipeline, response/error normalization.
- M2 done: CPT resource list/get with strict query contract.
- M3 done: custom table resource list/get with allowlist + prepared statement adapter.
- M4 done: contract freeze + route/resource meta model + contract extraction API.
- M5 done: resource CRUD (`create/update/delete`) for CPT and custom table resources.
- Post-M5 hardening: middleware factory hook, policy-driven route permissions, typed filter schema, deep pagination cap (`maxOffset`), CPT visibility policy/status handling.
- Auth bridge: claims-to-user mapper + ready auth middleware for `jwt/bearer`, `cookie+nonce`, `application password`.
- Write safety: idempotency key middleware + optimistic lock middleware + standard `409/412` error classes.
- Observability baseline: standard audit event schema + metrics middleware + Prometheus-friendly metric sink.
- Built-in middleware implementations: JWT auth (HS256 verifier), transient rate limiter/cache store/idempotency store, structured audit logger.
- Smoke host plugin: `/home/idp/webapps/app-idp/wp-content/plugins/better-route`

## Minimal usage

```php
use BetterRoute\Router\Router;

add_action('rest_api_init', function () {
    $router = Router::make('better-route', 'v1');
    $router->get('/ping', fn () => ['pong' => true])
        ->meta(['operationId' => 'ping']);
    $router->register();
});
```

```php
use BetterRoute\Resource\Resource;

add_action('rest_api_init', function () {
    Resource::make('articles')
        ->restNamespace('better-route/v1')
        ->sourceCpt('post')
        ->allow(['list', 'get', 'create', 'update', 'delete'])
        ->fields(['id', 'title', 'slug', 'excerpt', 'date', 'status'])
        ->filters(['status', 'author', 'after', 'before'])
        ->filterSchema([
            'status' => ['type' => 'enum', 'values' => ['publish', 'draft', 'private']],
            'author' => 'int',
            'after' => 'date',
            'before' => 'date',
        ])
        ->policy([
            'permissions' => [
                'list' => 'read',
                'get' => 'read',
                'create' => 'edit_posts',
                'update' => 'edit_posts',
                'delete' => 'delete_posts',
            ],
        ])
        ->cptVisibleStatuses(['publish', 'draft', 'private'])
        ->sort(['date', 'id'])
        ->maxOffset(5000)
        ->register();
});
```

```php
use BetterRoute\Resource\Resource;

add_action('rest_api_init', function () {
    Resource::make('raw-articles')
        ->restNamespace('better-route/v1')
        ->sourceTable('ai_raw_articles', 'id')
        ->allow(['list', 'get', 'create', 'update', 'delete'])
        ->fields(['id', 'source', 'title', 'created_at'])
        ->filters(['source'])
        ->sort(['id', 'created_at'])
        ->filterSchema(['source' => 'string'])
        ->defaultPerPage(20)
        ->maxPerPage(100)
        ->maxOffset(5000)
        ->uniformEnvelope(false)
        ->register();
});
```

## Configuration highlights

1. Middleware DI/factory:

```php
$router = Router::make('better-route', 'v1')
    ->middlewareFactory(function (string $middlewareClass): mixed {
        if ($middlewareClass === JwtAuthMiddleware::class) {
            return new JwtAuthMiddleware($jwtVerifier, $userMapper);
        }

        return null;
    });
```

2. Pagination policy per resource:
- `defaultPerPage(int)`
- `maxPerPage(int)`

3. Success payload style for `get` endpoints:
- default: raw object
- `uniformEnvelope(true)`: `{ "data": { ... } }`

4. Resource-level permission policy:
- `policy(['public' => true])`
- `policy(['permissions' => ['list' => 'read', 'create' => 'edit_posts']])`
- `policy(['permissions' => ['*' => ['read', 'edit_posts']]])` (any listed capability)
- `policy(['permissionCallback' => fn (...) => bool|WP_Error])`

5. Strict, typed query filters:
- `filterSchema(['author' => 'int', 'published' => 'bool', 'after' => 'date'])`
- enum filter: `['type' => 'enum', 'values' => ['draft', 'publish']]`

6. Large-list guardrail:
- `maxOffset(int)` rejects deep pagination requests when `(page - 1) * per_page` exceeds cap.

7. Built-in WP adapters:
- `BetterRoute\Middleware\Jwt\Hs256JwtVerifier`
- `BetterRoute\Middleware\RateLimit\TransientRateLimiter`
- `BetterRoute\Middleware\Cache\TransientCacheStore`
- `BetterRoute\Middleware\Write\TransientIdempotencyStore`
- `BetterRoute\Middleware\Audit\ErrorLogAuditLogger`

8. Auth bridge middleware:
- `BetterRoute\Middleware\Auth\BearerTokenAuthMiddleware`
- `BetterRoute\Middleware\Auth\CookieNonceAuthMiddleware`
- `BetterRoute\Middleware\Auth\ApplicationPasswordAuthMiddleware`
- `BetterRoute\Middleware\Auth\WpClaimsUserMapper`

9. Write safety middleware:
- `BetterRoute\Middleware\Write\IdempotencyMiddleware`
- `BetterRoute\Middleware\Write\OptimisticLockMiddleware`
- `BetterRoute\Http\ConflictException` (`409`)
- `BetterRoute\Http\PreconditionFailedException` (`412`)

10. Observability primitives:
- `BetterRoute\Middleware\Observability\MetricsMiddleware`
- `BetterRoute\Observability\MetricSinkInterface`
- `BetterRoute\Observability\InMemoryMetricSink`
- `BetterRoute\Observability\PrometheusMetricSink`
- `BetterRoute\Observability\AuditEventFactory`

## Documentation

This `README.md` is the canonical public documentation of the library.
