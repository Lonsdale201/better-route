<?php

declare(strict_types=1);

namespace BetterRoute\OpenApi;

use InvalidArgumentException;

/** Local contract checks only. Full OAS/JSON Schema validation runs separately in CI. */
final class OpenApiDocumentValidator
{
    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function prepare(array $document): array
    {
        foreach ($document['components']['securitySchemes'] ?? [] as $name => $scheme) {
            foreach ($scheme['flows'] ?? [] as $flowName => $flow) {
                if (($flow['scopes'] ?? null) === []) {
                    $document['components']['securitySchemes'][$name]['flows'][$flowName]['scopes'] = (object) [];
                }
            }
        }
        return self::jsonObjects($document);
    }

    /**
     * @param array<mixed> $node
     * @return array<mixed>
     */
    private static function jsonObjects(array $node): array
    {
        foreach ($node as $key => $value) {
            if (!is_array($value) || in_array($key, ['example', 'examples', 'default', 'enum', 'const', 'value'], true)) {
                continue;
            }
            if ($key === 'schemas') {
                foreach ($value as $name => $schema) {
                    $value[$name] = self::schemaObject($schema);
                }
                $node[$key] = $value !== [] ? $value : (object) [];
            } elseif ($key === 'schema') {
                $node[$key] = self::schemaObject($value);
            } else {
                $node[$key] = self::jsonObjects($value);
            }
        }
        return $node;
    }

    private static function schemaObject(mixed $schema): mixed
    {
        if (!is_array($schema)) {
            return $schema;
        }
        if ($schema === []) {
            return (object) [];
        }
        foreach ($schema as $key => $value) {
            if (in_array($key, ['properties', 'patternProperties', '$defs', 'dependentSchemas'], true) && is_array($value)) {
                foreach ($value as $name => $child) {
                    $value[$name] = self::schemaObject($child);
                }
                $schema[$key] = $value !== [] ? $value : (object) [];
            } elseif (in_array($key, ['items', 'contains', 'additionalProperties', 'unevaluatedProperties', 'unevaluatedItems', 'propertyNames', 'not', 'if', 'then', 'else', 'contentSchema'], true)) {
                $schema[$key] = self::schemaObject($value);
            } elseif (in_array($key, ['allOf', 'anyOf', 'oneOf', 'prefixItems'], true) && is_array($value)) {
                $schema[$key] = array_map(self::schemaObject(...), $value);
            }
        }
        return $schema;
    }

    /** @param array<string, mixed> $document */
    public static function validate(array $document, bool $strictReferences): void
    {
        $schemes = $document['components']['securitySchemes'] ?? [];
        self::security($document['security'] ?? null, $schemes);
        foreach ($document['paths'] as $operations) {
            foreach ($operations as $operation) {
                self::security($operation['security'] ?? null, $schemes);
            }
        }
        if ($strictReferences) {
            // Treat caller-supplied stdClass maps exactly like PHP map arrays.
            $tree = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
            self::references($tree, $tree);
        }
    }

    /** @param array<string, mixed> $schemes */
    private static function security(mixed $requirements, array $schemes): void
    {
        if ($requirements === null) {
            return;
        }
        foreach ($requirements as $requirement) {
            foreach ($requirement as $name => $scopes) {
                if (!isset($schemes[$name]) || !is_array($schemes[$name])) {
                    throw new InvalidArgumentException('Missing OpenAPI security scheme "' . $name . '".');
                }
                $scheme = $schemes[$name];
                $seen = [];
                while (isset($scheme['$ref']) && is_string($scheme['$ref'])) {
                    $ref = $scheme['$ref'];
                    if (isset($seen[$ref])) {
                        throw new InvalidArgumentException('Circular OpenAPI security scheme reference.');
                    }
                    $seen[$ref] = true;
                    if (!str_starts_with($ref, '#/components/securitySchemes/')) {
                        // External references are validated by the consuming application's tooling.
                        continue 2;
                    }
                    $target = str_replace(['~1', '~0'], ['/', '~'], rawurldecode(substr($ref, strlen('#/components/securitySchemes/'))));
                    if (!isset($schemes[$target]) || !is_array($schemes[$target])) {
                        throw new InvalidArgumentException('Missing OpenAPI security scheme reference "' . $ref . '".');
                    }
                    $scheme = $schemes[$target];
                }
                if (!in_array($scheme['type'] ?? null, ['apiKey', 'http', 'mutualTLS', 'oauth2', 'openIdConnect'], true)) {
                    throw new InvalidArgumentException('Invalid OpenAPI security scheme "' . $name . '".');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<mixed> $node
     */
    private static function references(array $document, array $node, bool $schema = false, bool $map = false): void
    {
        if (isset($node['$ref']) && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/')) {
            $target = $document;
            foreach (explode('/', substr($node['$ref'], 2)) as $token) {
                $token = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($token));
                if (!is_array($target) || !array_key_exists($token, $target)) {
                    throw new InvalidArgumentException('Missing OpenAPI reference "' . $node['$ref'] . '".');
                }
                $target = $target[$token];
            }
        }
        foreach ($node as $key => $value) {
            if (!is_array($value)) {
                continue;
            }
            if (!$map && is_string($key) && (str_starts_with($key, 'x-') || in_array($key, ['example', 'default', 'enum', 'const', 'value'], true) || ($schema && $key === 'examples'))) {
                continue;
            }
            $isSchema = $schema || $key === 'schema' || $key === 'schemas';
            $isMap = in_array($key, ['schemas', 'properties', 'patternProperties', '$defs', 'dependentSchemas', 'responses', 'parameters', 'requestBodies', 'headers', 'securitySchemes', 'callbacks', 'links', 'examples', 'content', 'paths', 'webhooks'], true);
            self::references($document, $value, $isSchema, $isMap);
        }
    }
}
