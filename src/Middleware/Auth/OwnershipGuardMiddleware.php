<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;

final class OwnershipGuardMiddleware implements MiddlewareInterface
{
    /** @var callable */
    private $ownerResolver;

    /**
     * @param callable $ownerResolver
     */
    public function __construct(
        callable $ownerResolver,
        private readonly ?string $bypassCapability = null,
        private readonly int $deniedStatus = 404
    ) {
        $this->ownerResolver = $ownerResolver;
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        if ($this->bypassCapability !== null && function_exists('current_user_can') && current_user_can($this->bypassCapability)) {
            return $next($context->withAttribute('ownership', ['bypassed' => true]));
        }

        $owner = ($this->ownerResolver)($context);
        $identity = $this->identity($context);

        if ($owner !== null && $identity !== null && (string) $owner === (string) $identity) {
            return $next($context->withAttribute('ownership', [
                'owner' => $owner,
                'identity' => $identity,
                'bypassed' => false,
            ]));
        }

        throw new ApiException(
            message: $this->deniedStatus === 404 ? 'Resource not found.' : 'Forbidden.',
            status: $this->deniedStatus,
            errorCode: $this->deniedStatus === 404 ? 'not_found' : 'forbidden'
        );
    }

    private function identity(RequestContext $context): int|string|null
    {
        $auth = $context->attributes['auth'] ?? null;
        if (is_array($auth)) {
            $userId = $auth['userId'] ?? null;
            if (is_int($userId) && $userId > 0) {
                return $userId;
            }

            $subject = $auth['subject'] ?? null;
            if (is_string($subject) && $subject !== '') {
                return $subject;
            }
        }

        if (function_exists('get_current_user_id')) {
            $userId = (int) get_current_user_id();
            if ($userId > 0) {
                return $userId;
            }
        }

        return null;
    }
}
