<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;
use ReflectionException;
use ReflectionFunction;
use ReflectionMethod;

final class ApplicationPasswordAuthMiddleware implements MiddlewareInterface
{
    /** @var callable(string, string, mixed): mixed */
    private $authenticate;

    /** @var callable(int): void */
    private $setCurrentUser;

    /**
     * @param null|callable(string, string, mixed): mixed $authenticate
     * @param null|callable(int): void $setCurrentUser
     */
    public function __construct(
        ?callable $authenticate = null,
        ?callable $setCurrentUser = null
    ) {
        $this->authenticate = $authenticate ?? static function (string $username, string $password, mixed $request): mixed {
            if (!function_exists('wp_authenticate_application_password')) {
                return null;
            }

            return wp_authenticate_application_password(null, $username, $password);
        };

        $this->setCurrentUser = $setCurrentUser ?? static function (int $userId): void {
            if (function_exists('wp_set_current_user')) {
                wp_set_current_user($userId);
            }
        };
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        [$username, $password] = $this->extractBasicCredentials($context->request);

        $user = $this->invokeAuthenticate($username, $password, $context->request);
        if ($this->isWpError($user) || !is_object($user) || !isset($user->ID) || !is_numeric($user->ID)) {
            throw new ApiException('Invalid application credentials.', 401, 'invalid_credentials');
        }

        $userId = (int) $user->ID;
        if ($userId < 1) {
            throw new ApiException('Invalid application credentials.', 401, 'invalid_credentials');
        }

        ($this->setCurrentUser)($userId);

        $identity = new AuthIdentity(
            provider: 'application_password',
            userId: $userId,
            user: $user
        );

        return $next(AuthContext::withIdentity($context, $identity));
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function extractBasicCredentials(mixed $request): array
    {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            throw new ApiException('Unauthorized.', 401, 'unauthorized');
        }

        $authorization = $request->get_header('authorization');
        if (!is_string($authorization) || $authorization === '') {
            throw new ApiException('Unauthorized.', 401, 'unauthorized');
        }

        if (!preg_match('/^Basic\s+(.+)$/i', trim($authorization), $matches)) {
            throw new ApiException('Invalid Authorization header.', 401, 'invalid_authorization_header');
        }

        $decoded = base64_decode(trim($matches[1]), true);
        if (!is_string($decoded) || !str_contains($decoded, ':')) {
            throw new ApiException('Invalid Authorization header.', 401, 'invalid_authorization_header');
        }

        [$username, $password] = explode(':', $decoded, 2);
        if ($username === '' || $password === '') {
            throw new ApiException('Invalid Authorization header.', 401, 'invalid_authorization_header');
        }

        return [$username, $password];
    }

    private function isWpError(mixed $value): bool
    {
        return class_exists('WP_Error') && $value instanceof \WP_Error;
    }

    private function invokeAuthenticate(string $username, string $password, mixed $request): mixed
    {
        $args = [$username, $password, $request];

        try {
            if (is_array($this->authenticate) && count($this->authenticate) === 2) {
                $reflection = new ReflectionMethod($this->authenticate[0], (string) $this->authenticate[1]);
            } else {
                $reflection = new ReflectionFunction(\Closure::fromCallable($this->authenticate));
            }

            if ($reflection->isVariadic()) {
                return ($this->authenticate)(...$args);
            }

            return ($this->authenticate)(...array_slice($args, 0, $reflection->getNumberOfParameters()));
        } catch (ReflectionException) {
            return ($this->authenticate)($username, $password, $request);
        }
    }
}
