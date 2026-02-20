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
        ->allow(['list', 'get'])
        ->fields(['id', 'title', 'slug', 'excerpt', 'date', 'status'])
        ->filters(['status', 'author', 'after', 'before'])
        ->sort(['date', 'id'])
        ->register();
});
```

```php
use BetterRoute\Resource\Resource;

add_action('rest_api_init', function () {
    Resource::make('raw-articles')
        ->restNamespace('better-route/v1')
        ->sourceTable('ai_raw_articles', 'id')
        ->allow(['list', 'get'])
        ->fields(['id', 'source', 'title', 'created_at'])
        ->filters(['source'])
        ->sort(['id', 'created_at'])
        ->register();
});
```

## Documentation

- Architecture and milestones: `DEVELOPMENT_BLUEPRINT.md`
- Stable contract and breaking checklist: `CONTRACT.md`
