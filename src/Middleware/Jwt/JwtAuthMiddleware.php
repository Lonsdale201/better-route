<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Jwt;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\Auth\AuthContext;
use BetterRoute\Middleware\Auth\AuthIdentity;
use BetterRoute\Middleware\Auth\ClaimsUserMapperInterface;
use BetterRoute\Middleware\MiddlewareInterface;
use Throwable;

final class JwtAuthMiddleware implements MiddlewareInterface
{
    /** @var callable(int): void */
    private $setCurrentUser;

    /**
     * @param list<string> $requiredScopes
     * @param null|callable(int): void $setCurrentUser
     */
    public function __construct(
        private readonly JwtVerifierInterface $verifier,
        private readonly array $requiredScopes = [],
        private readonly ?ClaimsUserMapperInterface $userMapper = null,
        ?callable $setCurrentUser = null
    ) {
        $this->setCurrentUser = $setCurrentUser ?? static function (int $userId): void {
            if (function_exists('wp_set_current_user')) {
                wp_set_current_user($userId);
            }
        };
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $token = $this->extractBearerToken($context->request);
        if ($token === null) {
            throw new ApiException('Unauthorized.', 401, 'unauthorized');
        }

        try {
            $claims = $this->verifier->verify($token);
        } catch (Throwable $throwable) {
            throw new ApiException(
                message: 'Invalid token.',
                status: 401,
                errorCode: 'invalid_token',
                details: ['reason' => $throwable->getMessage()]
            );
        }

        $scopes = $this->extractScopes($claims);
        if (!$this->hasRequiredScopes($scopes, $this->requiredScopes)) {
            throw new ApiException('Forbidden.', 403, 'insufficient_scope');
        }

        $userId = null;
        if ($this->userMapper !== null) {
            $userId = $this->userMapper->mapUserId($claims, $context);
            if ($userId !== null && $userId > 0) {
                ($this->setCurrentUser)($userId);
            }
        }

        $identity = new AuthIdentity(
            provider: 'jwt',
            userId: $userId,
            subject: $this->resolveSubject($claims),
            claims: $claims,
            scopes: $scopes
        );

        return $next(AuthContext::withIdentity($context, $identity));
    }

    private function extractBearerToken(mixed $request): ?string
    {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            return null;
        }

        $authorization = $request->get_header('authorization');
        if (!is_string($authorization)) {
            return null;
        }

        if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorization), $matches)) {
            return null;
        }

        $token = trim($matches[1]);
        return $token !== '' ? $token : null;
    }

    /**
     * @param array<string, mixed> $claims
     * @return list<string>
     */
    private function extractScopes(array $claims): array
    {
        $raw = $claims['scopes'] ?? ($claims['scope'] ?? []);

        if (is_string($raw)) {
            $parts = preg_split('/\s+/', trim($raw)) ?: [];
            return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
        }

        if (!is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $scope) {
            if (is_string($scope) && $scope !== '') {
                $result[] = $scope;
            }
        }

        return array_values($result);
    }

    /**
     * @param list<string> $granted
     * @param list<string> $required
     */
    private function hasRequiredScopes(array $granted, array $required): bool
    {
        foreach ($required as $requiredScope) {
            $matched = false;
            foreach ($granted as $grantedScope) {
                if ($this->scopeMatches($requiredScope, $grantedScope)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    private function scopeMatches(string $required, string $granted): bool
    {
        if ($required === $granted) {
            return true;
        }

        if (str_ends_with($required, '*')) {
            $prefix = rtrim($required, '*');
            return str_starts_with($granted, $prefix);
        }

        if (str_ends_with($granted, '*')) {
            $prefix = rtrim($granted, '*');
            return str_starts_with($required, $prefix);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function resolveSubject(array $claims): ?string
    {
        if (!array_key_exists('sub', $claims)) {
            return null;
        }

        $subject = $claims['sub'];
        if (is_string($subject) && $subject !== '') {
            return $subject;
        }

        if (is_int($subject) && $subject > 0) {
            return (string) $subject;
        }

        return null;
    }
}
