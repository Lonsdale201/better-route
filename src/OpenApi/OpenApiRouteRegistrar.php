<?php

declare(strict_types=1);

namespace BetterRoute\OpenApi;

use BetterRoute\Resource\Resource;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\Router;
use InvalidArgumentException;

final class OpenApiRouteRegistrar
{
    /**
     * @param callable(): list<array{
     *   namespace: string,
     *   method: string,
     *   path: string,
     *   args: array<string, mixed>,
     *   meta: array<string, mixed>
     * }> $contractsProvider
     * @param array{
     *   title?: string,
     *   version?: string,
     *   description?: string,
     *   serverUrl?: string,
     *   openapiVersion?: string,
     *   includeExcluded?: bool,
     *   components?: array<string, mixed>,
     *   permissionCallback?: callable
     * } $options
     */
    public static function register(
        string $restNamespace,
        callable $contractsProvider,
        array $options = [],
        ?DispatcherInterface $dispatcher = null
    ): void {
        $permissionCallback = $options['permissionCallback'] ?? (static fn (): bool => true);
        if (!is_callable($permissionCallback)) {
            throw new InvalidArgumentException('permissionCallback must be callable.');
        }

        $namespace = self::parseRestNamespace($restNamespace);
        $exporter = new OpenApiExporter();

        $router = Router::make($namespace['vendor'], $namespace['version']);
        $router->get('/openapi.json', static function () use ($contractsProvider, $exporter, $options): array {
            $contracts = $contractsProvider();
            if (!is_array($contracts)) {
                throw new InvalidArgumentException('contractsProvider must return an array.');
            }

            return $exporter->export($contracts, $options);
        })
            ->meta([
                'operationId' => 'openApiDocument',
                'tags' => ['OpenApi'],
                'openapi' => ['include' => false],
            ])
            ->permission($permissionCallback)
        ;

        $router->register($dispatcher);
    }

    /**
     * @param list<Router|Resource|list<array{
     *   namespace: string,
     *   method: string,
     *   path: string,
     *   args: array<string, mixed>,
     *   meta: array<string, mixed>
     * }>> $sources
     * @return list<array{
     *   namespace: string,
     *   method: string,
     *   path: string,
     *   args: array<string, mixed>,
     *   meta: array<string, mixed>
     * }>
     */
    public static function contractsFromSources(array $sources, bool $openApiOnly = true): array
    {
        $contracts = [];

        foreach ($sources as $source) {
            if ($source instanceof Router || $source instanceof Resource) {
                foreach ($source->contracts($openApiOnly) as $contract) {
                    $contracts[] = $contract;
                }

                continue;
            }

            if (!is_array($source)) {
                throw new InvalidArgumentException('OpenAPI sources must be Router, Resource, or contract list.');
            }

            foreach ($source as $contract) {
                if (self::isContract($contract)) {
                    $contracts[] = $contract;
                }
            }
        }

        return $contracts;
    }

    /**
     * @return array{vendor: string, version: string}
     */
    private static function parseRestNamespace(string $restNamespace): array
    {
        $trimmed = trim($restNamespace, '/');
        $parts = array_values(array_filter(explode('/', $trimmed), static fn (string $part): bool => $part !== ''));

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('restNamespace must be in "<vendor>/<version>" format.');
        }

        return [
            'vendor' => $parts[0],
            'version' => $parts[1],
        ];
    }

    private static function isContract(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }

        return is_string($value['namespace'] ?? null)
            && is_string($value['method'] ?? null)
            && is_string($value['path'] ?? null)
            && is_array($value['args'] ?? null)
            && is_array($value['meta'] ?? null);
    }
}
