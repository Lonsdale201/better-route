<?php

declare(strict_types=1);

namespace BetterRoute\Resource;

final class ResourcePolicy
{
    /**
     * @param string|list<string> $writeCapability
     * @return array<string, mixed>
     */
    public static function publicReadPrivateWrite(string|array $writeCapability = 'manage_options'): array
    {
        return [
            'permissions' => [
                'list' => true,
                'get' => true,
                'create' => $writeCapability,
                'update' => $writeCapability,
                'delete' => $writeCapability,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adminOnly(string $capability = 'manage_options'): array
    {
        return self::capabilities([
            'list' => $capability,
            'get' => $capability,
            'create' => $capability,
            'update' => $capability,
            'delete' => $capability,
        ]);
    }

    /**
     * @param array<string, mixed> $permissions
     * @return array<string, mixed>
     */
    public static function capabilities(array $permissions): array
    {
        return ['permissions' => $permissions];
    }

    /**
     * @param array<string, callable> $callbacks
     * @return array<string, mixed>
     */
    public static function callbacks(array $callbacks): array
    {
        return ['permissions' => $callbacks];
    }
}
