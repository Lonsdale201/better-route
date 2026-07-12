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
     * Map verified JWT/OAuth claims to a WordPress user id.
     *
     * SECURITY: email/login mapping is DISABLED by default. Resolving a WP
     * account from an `email`/`login` claim trusts an identity assertion that
     * many third-party issuers do not verify — an attacker who obtains a
     * validly signed token bearing a victim's email would otherwise be logged
     * in as that user (account takeover). Prefer an explicit, issuer-scoped
     * `sub`->user mapping (a $customResolver, or a first-party `user_id` claim).
     *
     * If you must map by email, opt in by passing $emailClaims AND ensure the
     * issuer sets a trustworthy `email_verified` claim ($requireEmailVerified,
     * on by default). Login-name mapping cannot be verified this way — only
     * enable it for issuers you fully control.
     *
     * @param list<string> $idClaims
     * @param list<string> $emailClaims Opt-in; empty by default.
     * @param list<string> $loginClaims Opt-in; empty by default.
     * @param null|callable(array<string, mixed>, RequestContext): ?int $customResolver
     */
    public function __construct(
        array $idClaims = ['user_id', 'uid', 'wp_user_id'],
        array $emailClaims = [],
        array $loginClaims = [],
        ?callable $customResolver = null,
        private readonly bool $requireEmailVerified = true
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

        if ($this->emailClaims !== [] && $this->emailClaimIsTrustworthy($claims)) {
            foreach ($this->emailClaims as $claimKey) {
                if (!isset($claims[$claimKey]) || !is_string($claims[$claimKey])) {
                    continue;
                }

                $userId = $this->findUserIdBy('email', $claims[$claimKey]);
                if ($userId !== null) {
                    return $userId;
                }
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

    /**
     * @param array<string, mixed> $claims
     */
    private function emailClaimIsTrustworthy(array $claims): bool
    {
        if (!$this->requireEmailVerified) {
            return true;
        }

        $verified = $claims['email_verified'] ?? null;

        return $verified === true
            || (is_string($verified) && in_array(strtolower(trim($verified)), ['1', 'true'], true));
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
        if (!($user instanceof \WP_User)) {
            return null;
        }

        return $user->ID > 0 ? $user->ID : null;
    }
}
