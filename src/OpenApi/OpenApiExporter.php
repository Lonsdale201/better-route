<?php

declare(strict_types=1);

namespace BetterRoute\OpenApi;

use InvalidArgumentException;

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
     *   components?: array<string, mixed>,
     *   securitySchemes?: array<string, array<string, mixed>>,
     *   globalSecurity?: list<array<string, list<string>>>,
     *   strictSchemas?: bool
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
        if (preg_match('/^3\.1\.\d+$/D', $openApiVersion) !== 1) {
            throw new InvalidArgumentException('This exporter supports OpenAPI 3.1 only.');
        }
        $includeExcluded = ($options['includeExcluded'] ?? false) === true;
        $strictSchemas = ($options['strictSchemas'] ?? false) === true;

        /** @var array<string, array<string, mixed>> $paths */
        $paths = [];
        /** @var array<string, bool> $referencedSchemas */
        $referencedSchemas = [];
        $operationIds = [];
        $templates = [];

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

            $shape = preg_replace('/\{[^}]+\}/', '{}', $openApiPath);
            if (isset($templates[$shape]) && $templates[$shape] !== $openApiPath) {
                throw new InvalidArgumentException('Equivalent OpenAPI path templates: ' . $openApiPath);
            }
            $templates[$shape] = $openApiPath;
            if (isset($paths[$openApiPath][$method])) {
                throw new InvalidArgumentException('Duplicate OpenAPI operation: ' . $method . ' ' . $openApiPath);
            }
            $operation = $this->operationFromContract($contract, $meta, $method, $openApiPath);
            if (isset($operationIds[$operation['operationId']])) {
                throw new InvalidArgumentException('Duplicate OpenAPI operationId "' . $operation['operationId'] . '". Set unique route operationId metadata.');
            }
            $operationIds[$operation['operationId']] = true;
            $paths[$openApiPath][$method] = $operation;
            $this->collectSchemaRef($meta['requestSchema'] ?? null, $referencedSchemas);
            $this->collectSchemaRef($meta['responseSchema'] ?? null, $referencedSchemas);
            $this->collectSchemaRefsFromResponses(
                is_array($meta['responses'] ?? null) ? $meta['responses'] : [],
                $referencedSchemas
            );
        }

        ksort($paths);
        foreach ($paths as &$operations) {
            ksort($operations);
        }
        unset($operations);

        $securitySchemes = is_array($options['securitySchemes'] ?? null) ? $options['securitySchemes'] : [];
        $globalSecurity = $this->normalizeSecurity($options['globalSecurity'] ?? null, []) ?? [];

        $components = $this->components(
            is_array($options['components'] ?? null) ? $options['components'] : [],
            array_keys($referencedSchemas),
            $securitySchemes,
            $strictSchemas
        );

        $document = [
            'openapi' => $openApiVersion,
            'info' => [
                'title' => $title,
                'version' => $version,
            ],
            'servers' => [
                ['url' => $serverUrl],
            ],
            'paths' => $paths !== [] ? $paths : (object) [],
            'components' => $components,
        ];

        if ($description !== null && $description !== '') {
            $document['info']['description'] = $description;
        }

        if ($globalSecurity !== []) {
            $document['security'] = $globalSecurity;
        }

        $document = OpenApiDocumentValidator::prepare($document);
        OpenApiDocumentValidator::validate($document, $strictSchemas);
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
        foreach (['summary', 'description', 'deprecated'] as $key) {
            if (array_key_exists($key, $meta)) {
                $operation[$key] = $meta[$key];
            }
        }

        $tags = $this->stringList($meta['tags'] ?? []);
        if ($tags !== []) {
            $operation['tags'] = $tags;
        }

        $scopes = $this->stringList($meta['scopes'] ?? []);
        if ($scopes !== []) {
            $operation['x-scopes'] = $scopes;
        }

        $security = $this->normalizeSecurity($meta['security'] ?? null, $scopes);
        if ($security !== null) {
            $operation['security'] = $security;
        }

        $parameters = $this->mergeParameters(
            $this->parametersFromArgs($contract['args'], $openApiPath),
            $this->normalizeParameters(is_array($meta['parameters'] ?? null) ? $meta['parameters'] : [])
        );
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
        $status = match ($method) {
            'post' => 201,
            'options' => 204,
            default => 200,
        };
        $response = [
            'description' => 'Successful response',
        ];

        $responseSchema = $this->stringOrNull($meta['responseSchema'] ?? null);
        if ($status !== 204 && $method !== 'head' && $responseSchema !== null && $responseSchema !== '') {
            $response['content'] = [
                'application/json' => [
                    'schema' => [
                        '$ref' => $responseSchema,
                    ],
                ],
            ];
        }

        $defaults = [
            (string) $status => $response,
            'default' => [
                '$ref' => '#/components/responses/ErrorResponse',
            ],
        ];

        $responses = array_replace(
            $defaults,
            $this->normalizeResponses(is_array($meta['responses'] ?? null) ? $meta['responses'] : [])
        );
        if ($method === 'head') {
            foreach ($responses as &$definition) {
                unset($definition['content']);
            }
            unset($definition);
        }
        return $responses;
    }

    /**
     * WordPress REST `args` are the executable validation contract. Exporting
     * them prevents the generated document from silently omitting list filters
     * and pagination parameters when no duplicate meta declaration was added.
     *
     * @param array<string, mixed> $args
     * @return list<array<string, mixed>>
     */
    private function parametersFromArgs(array $args, string $openApiPath): array
    {
        $parameters = [];

        foreach ($args as $name => $definition) {
            if (!is_string($name) || $name === '' || !is_array($definition)) {
                continue;
            }

            $isPath = str_contains($openApiPath, '{' . $name . '}');
            $schema = [];

            $type = $definition['type'] ?? null;
            if (is_array($type) && $type !== [] && array_is_list($type) && count(array_filter($type, static fn (mixed $item): bool => is_string($item) && in_array($item, ['array', 'boolean', 'integer', 'number', 'object', 'string', 'null'], true))) === count($type)) {
                $schema['type'] = $type;
            } elseif (is_string($type) && in_array($type, ['array', 'boolean', 'integer', 'number', 'object', 'string'], true)) {
                $schema['type'] = $type;
            } else {
                $schema['type'] = 'string';
            }

            foreach (['default', 'enum', 'format', 'items', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'minLength', 'maxLength', 'pattern', 'minItems', 'maxItems', 'uniqueItems', 'multipleOf', 'properties', 'additionalProperties', 'oneOf', 'anyOf', 'allOf'] as $key) {
                if (array_key_exists($key, $definition)) {
                    $schema[$key] = $definition[$key];
                }
            }
            $schema = $this->wordPressSchema($schema);

            $parameter = [
                'in' => $isPath ? 'path' : 'query',
                'name' => $name,
                'required' => $isPath || (($definition['required'] ?? false) === true),
                'schema' => $schema,
            ];

            $description = $this->stringOrNull($definition['description'] ?? null);
            if ($description !== null && $description !== '') {
                $parameter['description'] = $description;
            }

            $parameters[] = $parameter;
        }

        return $parameters;
    }

    /**
     * Explicit OpenAPI meta wins over the derived WordPress argument while
     * retaining every argument that was not documented manually.
     *
     * @param list<array<string, mixed>> $derived
     * @param list<array<string, mixed>> $explicit
     * @return list<array<string, mixed>>
     */
    private function mergeParameters(array $derived, array $explicit): array
    {
        $merged = [];

        foreach (array_merge($derived, $explicit) as $parameter) {
            $location = (string) ($parameter['in'] ?? 'query');
            $name = (string) ($parameter['name'] ?? '');
            $key = $location . ':' . ($location === 'header' ? strtolower($name) : $name);
            if ($key === 'query:') {
                continue;
            }

            $merged[$key] = $parameter;
        }

        return array_values($merged);
    }

    /** @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function wordPressSchema(array $schema): array
    {
        foreach (['Minimum' => 'minimum', 'Maximum' => 'maximum'] as $suffix => $bound) {
            $key = 'exclusive' . $suffix;
            if (is_bool($schema[$key] ?? null)) {
                if ($schema[$key] === true) {
                    if (!is_int($schema[$bound] ?? null) && !is_float($schema[$bound] ?? null)) {
                        throw new InvalidArgumentException('WordPress exclusive bound requires ' . $bound . '.');
                    }
                    $schema[$key] = $schema[$bound];
                    unset($schema[$bound]);
                } else {
                    unset($schema[$key]);
                }
            }
        }
        foreach (['items', 'additionalProperties'] as $key) {
            if (is_array($schema[$key] ?? null)) {
                $schema[$key] = $this->wordPressSchema($schema[$key]);
            }
        }
        foreach (['properties', 'allOf', 'anyOf', 'oneOf'] as $key) {
            foreach (is_array($schema[$key] ?? null) ? $schema[$key] : [] as $name => $child) {
                if (is_array($child)) {
                    $schema[$key][$name] = $this->wordPressSchema($child);
                }
            }
        }
        return $schema;
    }

    /**
     * @param array<int|string, mixed> $responses
     * @return array<string, array<string, mixed>>
     */
    private function normalizeResponses(array $responses): array
    {
        $normalized = [];

        foreach ($responses as $status => $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $code = is_int($status) ? (string) $status : trim((string) $status);
            if ($code === '') {
                continue;
            }

            if (!preg_match('/^(default|[1-5](?:[0-9]{2}|XX))$/D', $code)) {
                continue;
            }

            $entry = $definition;
            if (!array_key_exists('$ref', $entry)) {
                $description = $this->stringOrNull($entry['description'] ?? null);
                $entry['description'] = $description !== null && $description !== ''
                    ? $description
                    : 'Response';
            }

            if (in_array($code, ['204', '304'], true)) {
                unset($entry['content']);
            }
            $normalized[$code] = $entry;
        }

        return $normalized;
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
            foreach (['style', 'explode', 'allowReserved', 'deprecated', 'example', 'examples'] as $key) {
                if (array_key_exists($key, $parameter)) {
                    $result[$key] = $parameter[$key];
                }
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
            'summary',
            'description',
            'deprecated',
            'tags',
            'scopes',
            'security',
            'parameters',
            'responses',
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
     * @param list<string> $referencedSchemas
     * @param array<string, array<string, mixed>> $securitySchemes
     * @return array<string, mixed>
     */
    private function components(
        array $custom,
        array $referencedSchemas = [],
        array $securitySchemes = [],
        bool $strictSchemas = false
    ): array {
        $base = [
            'schemas' => [
                'WordPressError' => [
                    'type' => 'object',
                    'required' => ['code', 'message', 'data'],
                    'properties' => [
                        'code' => ['type' => 'string'],
                        'message' => ['type' => 'string'],
                        'data' => true,
                        'additional_errors' => ['type' => 'array', 'items' => ['type' => 'object']],
                    ],
                ],
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
                                    'anyOf' => [
                                        ['type' => 'object', 'additionalProperties' => true],
                                        ['type' => 'array', 'maxItems' => 0],
                                    ],
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
                                'oneOf' => [
                                    ['$ref' => '#/components/schemas/Error'],
                                    ['$ref' => '#/components/schemas/WordPressError'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $components = $custom === [] ? $base : array_replace_recursive($base, $custom);

        if ($securitySchemes !== []) {
            if (!isset($components['securitySchemes']) || !is_array($components['securitySchemes'])) {
                $components['securitySchemes'] = [];
            }

            foreach ($securitySchemes as $name => $scheme) {
                if (is_string($name) && $name !== '' && is_array($scheme)) {
                    $components['securitySchemes'][$name] = $scheme;
                }
            }
        }

        if (!isset($components['schemas']) || !is_array($components['schemas'])) {
            $components['schemas'] = [];
        }

        foreach ($referencedSchemas as $schemaName) {
            if ($schemaName === '' || isset($components['schemas'][$schemaName])) {
                continue;
            }

            if ($strictSchemas) {
                throw new InvalidArgumentException(sprintf(
                    'Missing OpenAPI schema "%s". Disable strictSchemas or provide options.components.schemas.%s.',
                    $schemaName,
                    $schemaName
                ));
            }

            $components['schemas'][$schemaName] = [
                'type' => 'object',
                'description' => 'Auto-generated placeholder schema. Override via export options.components.schemas.',
                'additionalProperties' => true,
            ];
        }

        return $components;
    }

    /**
     * @param array<string, bool> $target
     */
    private function collectSchemaRef(mixed $value, array &$target): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        $prefix = '#/components/schemas/';
        if (!str_starts_with($value, $prefix)) {
            return;
        }

        $schemaName = substr($value, strlen($prefix));
        // Only component-root references need compatibility placeholders.
        // Nested JSON pointers are checked by the complete local-ref traversal.
        if (str_contains($schemaName, '/')) {
            return;
        }
        $schemaName = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($schemaName));
        if ($schemaName !== '') {
            $target[$schemaName] = true;
        }
    }

    /**
     * @param array<string, mixed> $responses
     * @param array<string, bool> $target
     */
    private function collectSchemaRefsFromResponses(array $responses, array &$target): void
    {
        foreach ($responses as $definition) {
            if (!is_array($definition)) {
                continue;
            }

            $content = $definition['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $mediaType) {
                if (!is_array($mediaType)) {
                    continue;
                }

                $schema = $mediaType['schema'] ?? null;
                if (!is_array($schema)) {
                    continue;
                }

                $this->collectSchemaRef($schema['$ref'] ?? null, $target);
            }
        }
    }

    private function toOpenApiPath(string $namespace, string $path): string
    {
        return OpenApiPath::fromRoute($namespace, $path);
    }

    private function fallbackOperationSuffix(string $openApiPath): string
    {
        $normalized = preg_replace('/[^a-zA-Z0-9]+/', ' ', $openApiPath) ?? $openApiPath;
        $words = str_replace(' ', '', ucwords(strtolower(trim($normalized))));
        return $words !== '' ? $words : 'Operation';
    }

    /**
     * @param list<string> $scopes
     * @return list<array<string, list<string>>|\stdClass>|null
     */
    private function normalizeSecurity(mixed $security, array $scopes): ?array
    {
        if ($security === null) {
            return null;
        }
        if (is_string($security) && $security !== '') {
            $security = [[$security => $scopes]];
        }
        if (!is_array($security) || !array_is_list($security)) {
            throw new InvalidArgumentException('OpenAPI security must be a list of requirement objects.');
        }
        $normalized = [];
        foreach ($security as $requirement) {
            if (is_object($requirement) && $requirement instanceof \stdClass) {
                $requirement = get_object_vars($requirement);
            }
            if (!is_array($requirement) || ($requirement !== [] && array_is_list($requirement))) {
                throw new InvalidArgumentException('Invalid OpenAPI security requirement object.');
            }
            foreach ($requirement as $name => $values) {
                if (!is_string($name) || $name === '' || !is_array($values) || !array_is_list($values)) {
                    throw new InvalidArgumentException('Invalid OpenAPI security scheme/scopes.');
                }
                foreach ($values as $value) {
                    if (!is_string($value) || $value === '') {
                        throw new InvalidArgumentException('OpenAPI security scope/role names must be non-empty strings.');
                    }
                }
            }
            $normalized[] = $requirement !== [] ? $requirement : (object) [];
        }
        return $normalized;
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
