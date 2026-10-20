<?php

declare(strict_types=1);

namespace BetterRoute\Resource;

final class OwnedResourcePolicy
{
    /**
     * @param callable $ownerResolver
     * @param list<string> $ownedActions
     * @return array<string, mixed>
     */
    public static function currentUserOwns(
        callable $ownerResolver,
        array $ownedActions = ['get', 'update', 'delete'],
        ?string $bypassCapability = 'manage_options',
        bool $allowListForAuthenticatedUsers = true
    ): array {
        $permissions = [];

        if ($allowListForAuthenticatedUsers) {
            $permissions['list'] = static fn (): bool => self::currentUserId() > 0;
        }

        foreach ($ownedActions as $action) {
            $permissions[$action] = static function (mixed $request, string $currentAction) use ($ownerResolver, $bypassCapability): bool {
                if ($bypassCapability !== null && function_exists('current_user_can') && current_user_can($bypassCapability)) {
                    return true;
                }

                $userId = self::currentUserId();
                if ($userId <= 0) {
                    return false;
                }

                $resourceId = self::resourceId($request);
                if ($resourceId <= 0) {
                    return false;
                }

                $ownerId = $ownerResolver($resourceId, $request, $currentAction);
                return $ownerId !== null && (string) $ownerId === (string) $userId;
            };
        }

        return [
            'permissions' => $permissions,
            'ownership' => [
                'type' => 'current_user',
                'ownedActions' => array_values($ownedActions),
                'bypassCapability' => $bypassCapability,
            ],
        ];
    }

    private static function currentUserId(): int
    {
        if (!function_exists('get_current_user_id')) {
            return 0;
        }

        return max(0, (int) get_current_user_id());
    }

    private static function resourceId(mixed $request): int
    {
        // Match Resource::readId(): authorize the URL object that the handler uses.
        $raw = null;
        if (is_object($request) && method_exists($request, 'get_url_params')) {
            $params = $request->get_url_params();
            if (is_array($params) && array_key_exists('id', $params)) {
                $raw = $params['id'];
            }
        }

        if ($raw === null && is_object($request) && method_exists($request, 'get_param')) {
            $raw = $request->get_param('id');
        } elseif (is_array($request) && isset($request['id'])) {
            $raw = $request['id'];
        }

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }
        if (is_string($raw) && ctype_digit($raw) && (int) $raw > 0) {
            return (int) $raw;
        }

        return 0;
    }
}
