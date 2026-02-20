<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;

final class CookieNonceAuthMiddleware implements MiddlewareInterface
{
    /** @var callable(): bool */
    private $isLoggedIn;

    /** @var callable(string, string): bool */
    private $verifyNonce;

    /** @var callable(): int */
    private $currentUserId;

    /**
     * @param null|callable(): bool $isLoggedIn
     * @param null|callable(string, string): bool $verifyNonce
     * @param null|callable(): int $currentUserId
     */
    public function __construct(
        private readonly string $nonceAction = 'wp_rest',
        private readonly bool $requireNonce = true,
        private readonly bool $requireLoggedIn = true,
        ?callable $isLoggedIn = null,
        ?callable $verifyNonce = null,
        ?callable $currentUserId = null
    ) {
        $this->isLoggedIn = $isLoggedIn ?? static fn (): bool => function_exists('is_user_logged_in') ? (bool) is_user_logged_in() : false;

        $this->verifyNonce = $verifyNonce ?? static function (string $nonce, string $action): bool {
            if (!function_exists('wp_verify_nonce')) {
                return false;
            }

            return wp_verify_nonce($nonce, $action) !== false;
        };

        $this->currentUserId = $currentUserId ?? static fn (): int => function_exists('get_current_user_id') ? (int) get_current_user_id() : 0;
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        if ($this->requireLoggedIn && !($this->isLoggedIn)()) {
            throw new ApiException('Unauthorized.', 401, 'unauthorized');
        }

        if ($this->requireNonce) {
            $nonce = $this->extractNonce($context->request);
            if ($nonce === null || !($this->verifyNonce)($nonce, $this->nonceAction)) {
                throw new ApiException('Invalid nonce.', 403, 'invalid_nonce');
            }
        }

        $identity = new AuthIdentity(
            provider: 'cookie_nonce',
            userId: ($this->currentUserId)()
        );

        return $next(AuthContext::withIdentity($context, $identity));
    }

    private function extractNonce(mixed $request): ?string
    {
        if (is_object($request) && method_exists($request, 'get_header')) {
            $nonce = $request->get_header('x-wp-nonce');
            if (is_string($nonce) && trim($nonce) !== '') {
                return trim($nonce);
            }
        }

        if (is_object($request) && method_exists($request, 'get_param')) {
            $nonce = $request->get_param('_wpnonce');
            if (is_string($nonce) && trim($nonce) !== '') {
                return trim($nonce);
            }
        }

        if (is_array($request) && isset($request['_wpnonce']) && is_string($request['_wpnonce'])) {
            $nonce = trim($request['_wpnonce']);
            return $nonce !== '' ? $nonce : null;
        }

        return null;
    }
}
