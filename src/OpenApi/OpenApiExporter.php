<?php

declare(strict_types=1);

namespace BetterRoute\OpenApi;

final class OpenApiExporter
{
    /**
     * @param list<array{
     *   namespace: string,
     *   method: string,
     *   path: string,
     *   args: array<string, mixed>,
     *   meta: array<string, mixed>
     * }> $contracts
     * @param array{
     *   title?: string,
     *   version?: string,
     *   description?: string,
     *   serverUrl?: string,
     *   openapiVersion?: string,
     *   includeExcluded?: bool,
     *   components?: array<string, mixed>
     * } $options
     * @return array<string, mixed>
     */
    public function export(array $contracts, array $options = []): array
    {
        $title = $this->stringOrDefault($options['title'] ?? null, 'better-route API');
        $version = $this->stringOrDefault($options['version'] ?? null, 'v1');
        $description = $this->stringOrNull($options['description'] ?? null);
        $serverUrl = $this->stringOrDefault($options['serverUrl'] ?? null, '/wp-json');
        $openApiVersion = $this->stringOrDefault($options['openapiVersion'] ?? null, '3.1.0');
        $includeExcluded = ($options['includeExcluded'] ?? false) === true;

        /** @var array<string, array<string, mixed>> $paths */
        $paths = [];

        foreach ($contracts as $contract) {
            $meta = $contract['meta'];
            $include = (bool) ($meta['openapi']['include'] ?? true);
            if (!$includeExcluded && !$include) {
                continue;
            }

            $method = strtolower($contract['method']);
            if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete', 'options', 'head'], true)) {
                continue;
            }

            $openApiPath = $this->toOpenApiPath(
                $contract['namespace'],
                $contract['path']
            );

            $paths[$openApiPath][$method] = $this->operationFromContract($contract, $meta, $method, $openApiPath);
        }

        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }
        unset($operations);

        $document = [
            'openapi' => $openApiVersion,
            'info' => [
                'title' => $title,
                'version' => $version,
            ],
            'servers' => [
                ['url' => $serverUrl],
            ],
            'paths' => $paths,
            'components' => $this->components(is_array($options['components'] ?? null) ? $options['components'] : []),
        ];

        if ($description !== null && $description !== '') {
            $document['info']['description'] = $description;
        }

        return $document;
    }

    /**
     * @param array{
     *   namespace: string,
     *   method: string,
     *   path: string,
     *   args: array<string, mixed>,
     *   meta: array<string, mixed>
     * } $contract
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function operationFromContract(array $contract, array $meta, string $method, string $openApiPath): array
    {
        $operationId = $this->stringOrDefault(
            $meta['operationId'] ?? null,
            strtolower((string) $contract['method']) . $this->fallbackOperationSuffix($openApiPath)
        );

        $operation = [
            'operationId' => $operationId,
            'responses' => $this->responses($method, $meta),
        ];

        $tags = $this->stringList($meta['tags'] ?? []);
        if ($tags !== []) {
            $operation['tags'] = $tags;
        }

        $scopes = $this->stringList($meta['scopes'] ?? []);
        if ($scopes !== []) {
            $operation['x-scopes'] = $scopes;
        }

        $parameters = $this->normalizeParameters(is_array($meta['parameters'] ?? null) ? $meta['parameters'] : []);
        $parameters = $this->ensurePathParameters($parameters, $openApiPath);
        if ($parameters !== []) {
            $operation['parameters'] = $parameters;
        }

        $requestSchema = $this->stringOrNull($meta['requestSchema'] ?? null);
        if ($requestSchema !== null && $requestSchema !== '') {
            $operation['requestBody'] = [
                'required' => in_array($method, ['post', 'put', 'patch'], true),
                'content' => [
                    'application/json' => [
                        'schema' => [
                            '$ref' => $requestSchema,
                        ],
                    ],
                ],
            ];
        }

        $extensions = $this->metaExtensions($meta);
        if ($extensions !== []) {
            $operation['x-better-route'] = $extensions;
        }

        return $operation;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function responses(string $method, array $meta): array
    {
        $status = $method === 'post' ? 201 : 200;
        $response = [
            'description' => 'Successful response',
        ];

        $responseSchema = $this->stringOrNull($meta['responseSchema'] ?? null);
        if ($responseSchema !== null && $responseSchema !== '') {
            $response['content'] = [
                'application/json' => [
                    'schema' => [
                        '$ref' => $responseSchema,
                    ],
                ],
            ];
        }

        return [
            (string) $status => $response,
            'default' => [
                '$ref' => '#/components/responses/ErrorResponse',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $parameters
     * @return list<array<string, mixed>>
     */
    private function ensurePathParameters(array $parameters, string $openApiPath): array
    {
        if (!preg_match_all('/\{([a-zA-Z0-9_]+)\}/', $openApiPath, $matches)) {
            return $parameters;
        }

        /** @var list<string> $pathParams */
        $pathParams = array_values(array_unique($matches[1]));

        foreach ($parameters as $index => $parameter) {
            if (($parameter['in'] ?? null) === 'path') {
                $parameters[$index]['required'] = true;
            }
        }

        foreach ($pathParams as $pathParam) {
            $alreadyDefined = false;
            foreach ($parameters as $parameter) {
                if (($parameter['in'] ?? null) === 'path' && ($parameter['name'] ?? null) === $pathParam) {
                    $alreadyDefined = true;
                    break;
                }
            }

            if (!$alreadyDefined) {
                $parameters[] = [
                    'in' => 'path',
                    'name' => $pathParam,
                    'required' => true,
                    'schema' => ['type' => 'string'],
                ];
            }
        }

        return $parameters;
    }

    /**
     * @param mixed $parameters
     * @return list<array<string, mixed>>
     */
    private function normalizeParameters(mixed $parameters): array
    {
        if (!is_array($parameters)) {
            return [];
        }

        $normalized = [];
        foreach ($parameters as $parameter) {
            if (!is_array($parameter)) {
                continue;
            }

            $name = $this->stringOrNull($parameter['name'] ?? null);
            if ($name === null || $name === '') {
                continue;
            }

            $in = $this->stringOrDefault($parameter['in'] ?? null, 'query');
            if (!in_array($in, ['query', 'path', 'header', 'cookie'], true)) {
                $in = 'query';
            }

            $schema = is_array($parameter['schema'] ?? null) ? $parameter['schema'] : ['type' => 'string'];

            $result = [
                'in' => $in,
                'name' => $name,
                'required' => $in === 'path' ? true : (($parameter['required'] ?? false) === true),
                'schema' => $schema,
            ];

            $description = $this->stringOrNull($parameter['description'] ?? null);
            if ($description !== null && $description !== '') {
                $result['description'] = $description;
            }

            $normalized[] = $result;
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $meta
     * @return array<string, mixed>
     */
    private function metaExtensions(array $meta): array
    {
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

        return $extensions;
    }

    /**
     * @param array<string, mixed> $custom
     * @return array<string, mixed>
     */
    private function components(array $custom): array
    {
        $base = [
            'schemas' => [
                'Error' => [
                    'type' => 'object',
                    'required' => ['error'],
                    'properties' => [
                        'error' => [
                            'type' => 'object',
                            'required' => ['code', 'message', 'requestId'],
                            'properties' => [
                                'code' => ['type' => 'string'],
                                'message' => ['type' => 'string'],
                                'requestId' => ['type' => 'string'],
                                'details' => [
                                    'type' => 'object',
                                    'additionalProperties' => true,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'responses' => [
                'ErrorResponse' => [
                    'description' => 'Error response',
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                '$ref' => '#/components/schemas/Error',
                            ],
                        ],
                    ],
                ],
            ],
        ];

        if ($custom === []) {
            return $base;
        }

        return array_replace_recursive($base, $custom);
    }

    private function toOpenApiPath(string $namespace, string $path): string
    {
        $trimmedNamespace = trim($namespace, '/');
        $trimmedPath = trim($path, '/');

        $convertedPath = preg_replace('/\(\?P<([a-zA-Z0-9_]+)>[^)]+\)/', '{$1}', $trimmedPath) ?? $trimmedPath;
        $joined = trim($trimmedNamespace . '/' . $convertedPath, '/');

        return '/' . $joined;
    }

    private function fallbackOperationSuffix(string $openApiPath): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $openApiPath) ?? $openApiPath;
        $words = str_replace(' ', '', ucwords(strtolower(trim($normalized))));
        return $words !== '' ? $words : 'Operation';
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
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

    private function stringOrDefault(mixed $value, string $default): string
    {
        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
