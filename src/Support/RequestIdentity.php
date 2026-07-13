<?php

declare(strict_types=1);

namespace BetterRoute\Support;

use BetterRoute\Http\RequestContext;

final class RequestIdentity
{
    public static function key(RequestContext $context): string
    {
        $auth = $context->attributes['auth'] ?? null;
        if (is_array($auth)) {
            $provider = is_string($auth['provider'] ?? null) && $auth['provider'] !== ''
                ? $auth['provider']
                : 'auth';
            $userId = $auth['userId'] ?? null;
            if (is_int($userId) && $userId > 0) {
                return self::encoded($provider, 'user', $userId);
            }

            $subject = $auth['subject'] ?? null;
            if (is_string($subject) && $subject !== '') {
                return self::encoded($provider, 'subject', $subject);
            }
        }

        $attributeUserId = $context->attributes['userId'] ?? null;
        if (is_int($attributeUserId) && $attributeUserId > 0) {
            return self::encoded('wordpress', 'user', $attributeUserId);
        }

        if (function_exists('get_current_user_id')) {
            $wordpressUserId = (int) get_current_user_id();
            if ($wordpressUserId > 0) {
                return self::encoded('wordpress', 'user', $wordpressUserId);
            }
        }

        $hmac = $context->attributes['hmac'] ?? null;
        $keyId = is_array($hmac) ? ($hmac['keyId'] ?? null) : null;
        if (is_string($keyId) && $keyId !== '') {
            return self::encoded('hmac', 'key', $keyId);
        }

        return 'guest';
    }

    private static function encoded(string $provider, string $kind, int|string $value): string
    {
        return 'identity:' . hash('sha256', Canonicalizer::json([
            'provider' => $provider,
            'kind' => $kind,
            'value' => $value,
        ]));
    }
}
