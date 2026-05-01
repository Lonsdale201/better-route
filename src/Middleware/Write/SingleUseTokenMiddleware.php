<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\ConflictException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;
use RuntimeException;

final class SingleUseTokenMiddleware implements MiddlewareInterface
{
    /** @var callable(mixed): mixed */
    private $tokenSource;

    /** @var null|callable(array<string, mixed>, mixed, RequestContext): mixed */
    private $onConsumed;

    /**
     * @param callable(mixed): mixed $tokenSource
     * @param null|callable(array<string, mixed>, mixed, RequestContext): mixed $onConsumed
     */
    public function __construct(
        private readonly SingleUseTokenStoreInterface $store,
        callable $tokenSource,
        ?callable $onConsumed = null,
        private readonly string $hashSalt = '',
        private readonly int $ttlSeconds = 300
    ) {
        if ($ttlSeconds < 1) {
            throw new RuntimeException('Single-use token TTL must be positive.');
        }

        $this->tokenSource = $tokenSource;
        $this->onConsumed = $onConsumed;
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $token = ($this->tokenSource)($context->request);
        if (!is_string($token) || trim($token) === '') {
            throw new ApiException('Single-use token is required.', 400, 'single_use_token_required');
        }

        $tokenHash = self::hashToken($token, $this->resolvedHashSalt());
        $consumedContext = $this->store->consume($tokenHash);
        if ($consumedContext === null) {
            if ($this->store->wasConsumed($tokenHash)) {
                throw new ConflictException('Single-use token has already been consumed.', 'single_use_token_reused');
            }

            throw new ApiException('Single-use token is invalid.', 401, 'invalid_single_use_token');
        }

        $nextContext = $context->withAttribute('singleUseToken', $consumedContext);
        if ($this->onConsumed !== null) {
            $result = ($this->onConsumed)($consumedContext, $context->request, $nextContext);
            if ($result instanceof RequestContext) {
                $nextContext = $result;
            }
        }

        return $next($nextContext);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function storeToken(string $token, array $context, ?int $ttlSeconds = null): void
    {
        $this->store->store(
            self::hashToken($token, $this->resolvedHashSalt()),
            $context,
            $ttlSeconds ?? $this->ttlSeconds
        );
    }

    public static function hashToken(string $token, string $salt): string
    {
        if ($salt === '') {
            throw new RuntimeException('Single-use token hash salt must not be empty.');
        }

        return hash_hmac('sha256', $token, $salt);
    }

    private function resolvedHashSalt(): string
    {
        if ($this->hashSalt !== '') {
            return $this->hashSalt;
        }

        if (function_exists('wp_salt')) {
            $salt = (string) wp_salt('better_route_single_use_token');
            if ($salt !== '') {
                return $salt;
            }
        }

        throw new RuntimeException('Single-use token hash salt must be configured.');
    }
}
