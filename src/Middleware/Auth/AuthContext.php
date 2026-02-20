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

        if ($identity->claims !== []) {
            $next = $next->withAttribute('claims', $identity->claims);
        }

        if ($identity->scopes !== []) {
            $next = $next->withAttribute('scopes', $identity->scopes);
        }

        if ($identity->userId !== null) {
            $next = $next->withAttribute('userId', $identity->userId);
        }

        if ($identity->user !== null) {
            $next = $next->withAttribute('user', $identity->user);
        }

        return $next;
    }
}
