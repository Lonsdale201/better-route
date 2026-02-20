<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Auth;

use BetterRoute\Http\RequestContext;

final class WpClaimsUserMapper implements ClaimsUserMapperInterface
{
    /** @var list<string> */
    private array $idClaims;

    /** @var list<string> */
    private array $emailClaims;

    /** @var list<string> */
    private array $loginClaims;

    /** @var null|callable(array<string, mixed>, RequestContext): ?int */
    private $customResolver;

    /**
     * @param list<string> $idClaims
     * @param list<string> $emailClaims
     * @param list<string> $loginClaims
     * @param null|callable(array<string, mixed>, RequestContext): ?int $customResolver
     */
    public function __construct(
        array $idClaims = ['user_id', 'uid', 'wp_user_id', 'sub'],
        array $emailClaims = ['email'],
        array $loginClaims = ['username', 'login', 'user_login'],
        ?callable $customResolver = null
    ) {
        $this->idClaims = $idClaims;
        $this->emailClaims = $emailClaims;
        $this->loginClaims = $loginClaims;
        $this->customResolver = $customResolver;
    }

    public function mapUserId(array $claims, RequestContext $context): ?int
    {
        if ($this->customResolver !== null) {
            $resolved = ($this->customResolver)($claims, $context);
            if (is_int($resolved) && $resolved > 0) {
                return $resolved;
            }
        }

        foreach ($this->idClaims as $claimKey) {
            if (!array_key_exists($claimKey, $claims)) {
                continue;
            }

            $userId = $this->normalizeUserId($claims[$claimKey]);
            if ($userId !== null) {
                return $userId;
            }
        }

        foreach ($this->emailClaims as $claimKey) {
            if (!isset($claims[$claimKey]) || !is_string($claims[$claimKey])) {
                continue;
            }

            $userId = $this->findUserIdBy('email', $claims[$claimKey]);
            if ($userId !== null) {
                return $userId;
            }
        }

        foreach ($this->loginClaims as $claimKey) {
            if (!isset($claims[$claimKey]) || !is_string($claims[$claimKey])) {
                continue;
            }

            $userId = $this->findUserIdBy('login', $claims[$claimKey]);
            if ($userId !== null) {
                return $userId;
            }
        }

        return null;
    }

    private function normalizeUserId(mixed $value): ?int
    {
        if (is_int($value) && $value > 0) {
            return $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', $value) === 1) {
            $userId = (int) $value;
            return $userId > 0 ? $userId : null;
        }

        return null;
    }

    private function findUserIdBy(string $field, string $value): ?int
    {
        if (!function_exists('get_user_by')) {
            return null;
        }

        $user = get_user_by($field, $value);
        if (!is_object($user) || !isset($user->ID)) {
            return null;
        }

        $id = is_numeric($user->ID) ? (int) $user->ID : null;
        return $id !== null && $id > 0 ? $id : null;
    }
}
