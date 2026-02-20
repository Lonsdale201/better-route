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

## Documentation

- Architecture and milestones: `DEVELOPMENT_BLUEPRINT.md`
