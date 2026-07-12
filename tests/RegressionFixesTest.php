<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\ErrorNormalizer;
use BetterRoute\Http\PreconditionRequiredException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\Auth\BearerTokenAuthMiddleware;
use BetterRoute\Middleware\Auth\BearerTokenVerifierInterface;
use BetterRoute\Middleware\Cors\CorsPolicy;
use BetterRoute\Middleware\Network\TrustedProxyClientIpResolver;
use BetterRoute\Middleware\Write\OptimisticLockMiddleware;
use BetterRoute\Middleware\Write\OptimisticLockVersionResolverInterface;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RegressionFixesTest extends TestCase
{
    public function testForwardedForReturnsRightmostUntrustedHopNotSpoofedLeftmost(): void
    {
        $resolver = new TrustedProxyClientIpResolver(
            trustedProxyCidrs: ['10.0.0.0/8'],
            forwardedHeaders: ['X-Forwarded-For'],
            serverResolver: static fn (): array => [
                'REMOTE_ADDR' => '10.0.0.5',
                // Attacker-supplied "1.2.3.4" is leftmost; the trusted proxy
                // appended the real client (203.0.113.7) plus an internal hop.
                'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.7, 10.0.0.9',
            ]
        );

        self::assertSame('203.0.113.7', $resolver->resolve());
    }

    public function testForwardedForFallsBackToRemoteAddrWhenEveryHopIsTrusted(): void
    {
        $resolver = new TrustedProxyClientIpResolver(
            trustedProxyCidrs: ['10.0.0.0/8'],
            forwardedHeaders: ['X-Forwarded-For'],
            serverResolver: static fn (): array => [
                'REMOTE_ADDR' => '10.0.0.5',
                'HTTP_X_FORWARDED_FOR' => '10.0.0.8, 10.0.0.9',
            ]
        );

        self::assertSame('10.0.0.5', $resolver->resolve());
    }

    public function testCorsRejectsWildcardOriginWithCredentials(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CorsPolicy(['*'], allowCredentials: true);
    }

    public function testCorsWildcardWithoutCredentialsIsAllowed(): void
    {
        $policy = new CorsPolicy(['*']);
        $headers = $policy->headersFor('https://example.com');

        self::assertSame('*', $headers['Access-Control-Allow-Origin']);
    }

    public function testErrorNormalizerDoesNotLeakGenericThrowableDetails(): void
    {
        $response = (new ErrorNormalizer())->fromThrowable(
            new \RuntimeException('internal secret detail'),
            'req_1'
        );

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(500, $response->status);
        self::assertSame('Unexpected error.', $response->body['error']['message']);
        self::assertSame([], $response->body['error']['details']);
        self::assertStringNotContainsStringIgnoringCase('secret', json_encode($response->body));
    }

    public function testErrorNormalizerDoesNotLeakInvalidArgumentClassOrMessage(): void
    {
        $response = (new ErrorNormalizer())->fromThrowable(
            new \InvalidArgumentException('leaky validation internals'),
            'req_2'
        );

        self::assertSame(400, $response->status);
        self::assertSame('invalid_request', $response->body['error']['code']);
        self::assertSame('Invalid request.', $response->body['error']['message']);
        self::assertSame([], $response->body['error']['details']);
        self::assertStringNotContainsString('leaky', json_encode($response->body));
    }

    public function testErrorNormalizerStillSurfacesApiExceptionDetail(): void
    {
        $response = (new ErrorNormalizer())->fromThrowable(
            new ApiException('Nope.', 403, 'forbidden', ['field' => 'x']),
            'req_3'
        );

        self::assertSame(403, $response->status);
        self::assertSame('forbidden', $response->body['error']['code']);
        self::assertSame('Nope.', $response->body['error']['message']);
        self::assertSame(['field' => 'x'], $response->body['error']['details']);
    }

    public function testOptimisticLockMissingPreconditionIsPreconditionRequired428(): void
    {
        $resolver = new class () implements OptimisticLockVersionResolverInterface {
            public function resolve(RequestContext $context): ?string
            {
                return 'v1';
            }
        };

        $middleware = new OptimisticLockMiddleware($resolver, required: true);
        $context = new RequestContext('req', '/things/1', []);

        try {
            $middleware->handle($context, static fn (): string => 'unreached');
            self::fail('Expected PreconditionRequiredException.');
        } catch (PreconditionRequiredException $exception) {
            self::assertSame(428, $exception->status());
            self::assertSame('precondition_required', $exception->errorCode());
        }
    }

    public function testGrantedScopeWildcardIsRejectedByDefault(): void
    {
        $middleware = new BearerTokenAuthMiddleware(
            $this->scopeVerifier(['orders:*']),
            requiredScopes: ['orders:read']
        );

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(0);

        try {
            $middleware->handle($this->bearerContext(), static fn (): string => 'unreached');
            self::fail('Expected insufficient_scope.');
        } catch (ApiException $exception) {
            self::assertSame(403, $exception->status());
            self::assertSame('insufficient_scope', $exception->errorCode());
            throw $exception;
        }
    }

    public function testGrantedScopeWildcardAllowedWhenOptedIn(): void
    {
        $middleware = new BearerTokenAuthMiddleware(
            $this->scopeVerifier(['orders:*']),
            requiredScopes: ['orders:read'],
            allowGrantedScopeWildcards: true
        );

        $result = $middleware->handle($this->bearerContext(), static fn (): string => 'ok');
        self::assertSame('ok', $result);
    }

    public function testRequiredScopeWildcardStillMatchesLiteralGrant(): void
    {
        $middleware = new BearerTokenAuthMiddleware(
            $this->scopeVerifier(['orders:read']),
            requiredScopes: ['orders:*']
        );

        $result = $middleware->handle($this->bearerContext(), static fn (): string => 'ok');
        self::assertSame('ok', $result);
    }

    /**
     * @param list<string> $scopes
     */
    private function scopeVerifier(array $scopes): BearerTokenVerifierInterface
    {
        return new class ($scopes) implements BearerTokenVerifierInterface {
            /** @param list<string> $scopes */
            public function __construct(private readonly array $scopes)
            {
            }

            public function verify(string $token): array
            {
                return ['sub' => 'user-1', 'scopes' => $this->scopes];
            }
        };
    }

    private function bearerContext(): RequestContext
    {
        $request = new class () {
            public function get_header(string $name): ?string
            {
                return strtolower($name) === 'authorization' ? 'Bearer token-value' : null;
            }
        };

        return new RequestContext('req', '/orders', $request);
    }
}
