<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

use BetterRoute\Http\RequestContext;

final class AuthContext
{
    public static function withIdentity(RequestContext $context, AuthIdentity $identity): RequestContext
    {
        $next = $context->withAttribute('auth', [
            'provider' => $identity->provider,
            'userId' => $identity->userId,
            'subject' => $identity->subject,
            'scopes' => $identity->scopes,
        ]);

        // Replacing an identity must not retain claims or a user from an outer
        // authentication middleware when the new identity has no WP mapping.
        return $next->withAttribute('claims', $identity->claims)
            ->withAttribute('scopes', $identity->scopes)
            ->withAttribute('userId', $identity->userId)
            ->withAttribute('user', $identity->user);
    }
}
