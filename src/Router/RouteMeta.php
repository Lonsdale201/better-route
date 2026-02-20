<?php

declare(strict_types=1);

namespace BetterRoute\Router;

final class RouteMeta
{
    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    public static function normalize(array $meta, string $method, string $uri): array
    {
        $operationId = self::stringOrNull($meta['operationId'] ?? null);
        if ($operationId === null || $operationId === '') {
            $operationId = self::defaultOperationId($method, $uri);
        }

        $tags = self::stringList($meta['tags'] ?? []);
        $scopes = self::stringList($meta['scopes'] ?? []);
        if ($scopes === []) {
            $scopes = self::stringList($meta['policy']['scopes'] ?? []);
        }
        $parameters = is_array($meta['parameters'] ?? null) ? $meta['parameters'] : [];
        $requestSchema = self::stringOrNull($meta['requestSchema'] ?? null);
        $responseSchema = self::stringOrNull($meta['responseSchema'] ?? null);
        $openApiInclude = self::boolOrDefault($meta['openapi']['include'] ?? null, true);

        $known = [
            'operationId',
            'tags',
            'scopes',
            'parameters',
            'requestSchema',
            'responseSchema',
            'openapi',
        ];

        $extensions = [];
        foreach ($meta as $key => $value) {
            if (!in_array($key, $known, true)) {
                $extensions[$key] = $value;
            }
        }

        return array_merge($extensions, [
            'operationId' => $operationId,
            'tags' => $tags,
            'scopes' => $scopes,
            'parameters' => $parameters,
            'requestSchema' => $requestSchema,
            'responseSchema' => $responseSchema,
            'openapi' => [
                'include' => $openApiInclude,
            ],
        ]);
    }

    private static function defaultOperationId(string $method, string $uri): string
    {
        $cleanUri = trim($uri, '/');
        if ($cleanUri === '') {
            return strtolower($method) . 'Root';
        }

        $cleanUri = preg_replace('/\(\?P<([a-zA-Z0-9_]+)>[^)]+\)/', '$1', $cleanUri) ?? $cleanUri;
        $segments = array_values(array_filter(explode('/', $cleanUri), static fn (string $part): bool => $part !== ''));
        $pascal = array_map(static function (string $segment): string {
            $normalized = preg_replace('/[^a-zA-Z0-9_]/', '_', $segment) ?? $segment;
            $normalized = str_replace('_', ' ', strtolower($normalized));
            return str_replace(' ', '', ucwords($normalized));
        }, $segments);

        return strtolower($method) . implode('', $pascal);
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $result[] = $item;
            }
        }

        return array_values($result);
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function boolOrDefault(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }
}
