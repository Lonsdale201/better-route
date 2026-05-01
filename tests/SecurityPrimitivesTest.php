<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\ConflictException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\Auth\ArrayHmacSecretProvider;
use BetterRoute\Middleware\Auth\BearerTokenAuthMiddleware;
use BetterRoute\Middleware\Auth\BearerTokenVerifierInterface;
use BetterRoute\Middleware\Auth\HmacSignatureMiddleware;
use BetterRoute\Middleware\Jwt\HttpJwksProvider;
use BetterRoute\Middleware\Jwt\JwksProviderInterface;
use BetterRoute\Middleware\Jwt\Rs256JwksJwtVerifier;
use BetterRoute\Middleware\Jwt\StaticJwksProvider;
use BetterRoute\Middleware\Network\IpAllowlistMiddleware;
use BetterRoute\Middleware\Network\TrustedProxyClientIpResolver;
use BetterRoute\Middleware\Write\ArraySingleUseTokenStore;
use BetterRoute\Middleware\Write\SingleUseTokenMiddleware;
use BetterRoute\Router\DispatcherInterface;
use BetterRoute\Router\RouteDefinition;
use BetterRoute\Router\Router;
use BetterRoute\Support\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SecurityPrimitivesTest extends TestCase
{
    public function testCryptoTokensAndBase64UrlRoundTrip(): void
    {
        $token = Crypto::token(16);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token);
        self::assertSame('payload', Crypto::base64UrlDecode(Crypto::base64UrlEncode('payload')));
        self::assertTrue(Crypto::equals('known', 'known'));
    }

    public function testRs256JwksVerifierRefreshesOnceOnKidMissAndValidatesToken(): void
    {
        $this->requireOpenSsl();

        [$privateKey, $jwk] = $this->rsaKeyPair('key-a');
        $now = 1700000000;
        $token = $this->signRs256Token(
            ['alg' => 'RS256', 'kid' => 'key-a', 'typ' => 'JWT'],
            ['sub' => 'user-1', 'iss' => 'issuer-a', 'aud' => 'client-a', 'iat' => $now, 'exp' => $now + 120],
            $privateKey
        );

        $provider = new RefreshingJwksProvider([], [$jwk]);
        $verifier = new Rs256JwksJwtVerifier(
            $provider,
            now: static fn (): int => $now,
            expectedIssuer: 'issuer-a',
            expectedAudience: 'client-a'
        );

        self::assertSame('user-1', $verifier->verify($token)['sub']);
        self::assertSame(1, $provider->refreshes);
    }

    public function testRs256JwksVerifierDoesNotTryKeysWithDifferentKid(): void
    {
        $this->requireOpenSsl();

        [$privateKey, $jwk] = $this->rsaKeyPair('other-key');
        $token = $this->signRs256Token(
            ['alg' => 'RS256', 'kid' => 'missing-key', 'typ' => 'JWT'],
            ['sub' => 'user-1', 'exp' => 1700000120],
            $privateKey
        );

        $verifier = new Rs256JwksJwtVerifier(
            new StaticJwksProvider([$jwk]),
            now: static fn (): int => 1700000000
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('signing key not found');
        $verifier->verify($token);
    }

    public function testEs256JwksVerifierValidatesTokenWhenAllowed(): void
    {
        $this->requireOpenSsl();

        [$privateKey, $jwk] = $this->ecKeyPair('ec-a');
        $now = 1700000000;
        $token = $this->signEs256Token(
            ['alg' => 'ES256', 'kid' => 'ec-a', 'typ' => 'JWT'],
            ['sub' => 'user-1', 'iat' => $now, 'exp' => $now + 120],
            $privateKey
        );

        $verifier = new Rs256JwksJwtVerifier(
            new StaticJwksProvider([$jwk]),
            now: static fn (): int => $now,
            allowedAlgorithms: ['ES256']
        );

        self::assertSame('user-1', $verifier->verify($token)['sub']);
    }

    public function testRs256JwksVerifierRejectsSymmetricAlgorithmsEvenIfConfigured(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Insecure JWT algorithms');

        new Rs256JwksJwtVerifier(new StaticJwksProvider([]), allowedAlgorithms: ['HS256']);
    }

    public function testJwksProvidersRequireHttpsAndStripPrivateFields(): void
    {
        $this->expectException(RuntimeException::class);
        new HttpJwksProvider('http://issuer.example.test/jwks.json');
    }

    public function testStaticJwksProviderStripsPrivateFields(): void
    {
        $provider = new StaticJwksProvider([[
            'kty' => 'RSA',
            'kid' => 'key-a',
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => 'abc',
            'e' => 'AQAB',
            'd' => 'private',
        ]]);

        self::assertArrayNotHasKey('d', $provider->keys()[0]);
    }

    public function testHmacSignatureMiddlewareValidatesCanonicalRequest(): void
    {
        $timestamp = 1700000000;
        $body = '{"ok":true}';
        $canonical = implode("\n", [
            (string) $timestamp,
            'POST',
            '/webhook',
            hash('sha256', $body),
        ]);
        $signature = hash_hmac('sha256', $canonical, 'secret-a');

        $request = new SecurityRequest([
            'x-signature' => $signature,
            'x-timestamp' => (string) $timestamp,
            'x-key-id' => 'primary',
        ], 'POST', [], $body, '/webhook');
        $middleware = new HmacSignatureMiddleware(
            new ArrayHmacSecretProvider(['primary' => 'secret-a']),
            now: static fn (): int => $timestamp
        );

        $result = $middleware->handle(
            new RequestContext('req_hmac', '/webhook', $request),
            static fn (RequestContext $context): string => (string) $context->attributes['hmac']['keyId']
        );

        self::assertSame('primary', $result);
    }

    public function testIpAllowlistUsesTrustedProxyCidrs(): void
    {
        $resolver = new TrustedProxyClientIpResolver(
            trustedProxyCidrs: ['10.0.0.0/24'],
            forwardedHeaders: ['X-Forwarded-For'],
            serverResolver: static fn (): array => [
                'REMOTE_ADDR' => '10.0.0.10',
                'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 10.0.0.10',
            ]
        );
        $middleware = new IpAllowlistMiddleware(['203.0.113.0/24'], $resolver);

        $result = $middleware->handle(
            new RequestContext('req_ip', '/back-channel', new SecurityRequest([])),
            static fn (RequestContext $context): string => (string) $context->attributes['clientIp']
        );

        self::assertSame('203.0.113.9', $result);
    }

    public function testSingleUseTokenMiddlewareConsumesTokenOnce(): void
    {
        $store = new ArraySingleUseTokenStore();
        $middleware = new SingleUseTokenMiddleware(
            store: $store,
            tokenSource: static fn (SecurityRequest $request): ?string => $request->get_param('code'),
            hashSalt: 'single-use-secret'
        );
        $middleware->storeToken('code-123', ['subject' => 'user-1']);
        $request = new SecurityRequest([], 'POST', ['code' => 'code-123']);
        $context = new RequestContext('req_single', '/token', $request);

        $result = $middleware->handle(
            $context,
            static fn (RequestContext $next): string => (string) $next->attributes['singleUseToken']['subject']
        );

        self::assertSame('user-1', $result);

        $this->expectException(ConflictException::class);
        $middleware->handle($context, static fn (): null => null);
    }

    public function testBearerTokenAuthMiddlewareSplitsScopeString(): void
    {
        $middleware = new BearerTokenAuthMiddleware(
            new SecurityBearerVerifier(['sub' => 'user-1', 'scope' => 'openid profile wallet:read']),
            requiredScopes: ['wallet:read']
        );

        $result = $middleware->handle(
            new RequestContext('req_scope', '/me', new SecurityRequest(['authorization' => 'Bearer token'])),
            static fn (RequestContext $context): array => $context->attributes['scopes']
        );

        self::assertSame(['openid', 'profile', 'wallet:read'], $result);
    }

    public function testOAuthErrorFormatCanBeSelectedByRouteMeta(): void
    {
        $router = Router::make('better-route', 'v1');
        $router->post('/token', static function (): never {
            throw new ApiException('Authorization code is invalid.', 400, 'invalid_grant');
        })->meta(['error_format' => 'oauth_rfc6749']);

        $dispatcher = new SecurityDispatcher();
        $router->register($dispatcher);
        $response = ($dispatcher->registrations[0]['callback'])(new SecurityRequest(['x-request-id' => 'req_oauth'], 'POST'));

        self::assertSame(400, $response['status']);
        self::assertSame([
            'error' => 'invalid_grant',
            'error_description' => 'Authorization code is invalid.',
        ], $response['body']);
    }

    /**
     * @return array{0: mixed, 1: array<string, string>}
     */
    private function rsaKeyPair(string $kid): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($privateKey === false) {
            self::markTestSkipped('Unable to generate RSA test key.');
        }

        $details = openssl_pkey_get_details($privateKey);
        if (!is_array($details) || !isset($details['rsa']['n'], $details['rsa']['e'])) {
            self::markTestSkipped('Unable to read RSA test key details.');
        }

        return [
            $privateKey,
            [
                'kty' => 'RSA',
                'kid' => $kid,
                'alg' => 'RS256',
                'use' => 'sig',
                'n' => Crypto::base64UrlEncode($details['rsa']['n']),
                'e' => Crypto::base64UrlEncode($details['rsa']['e']),
            ],
        ];
    }

    /**
     * @return array{0: mixed, 1: array<string, string>}
     */
    private function ecKeyPair(string $kid): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
        ]);
        if ($privateKey === false) {
            self::markTestSkipped('Unable to generate EC test key.');
        }

        $details = openssl_pkey_get_details($privateKey);
        if (!is_array($details) || !isset($details['ec']['x'], $details['ec']['y'])) {
            self::markTestSkipped('Unable to read EC test key details.');
        }

        return [
            $privateKey,
            [
                'kty' => 'EC',
                'kid' => $kid,
                'alg' => 'ES256',
                'use' => 'sig',
                'crv' => 'P-256',
                'x' => Crypto::base64UrlEncode($details['ec']['x']),
                'y' => Crypto::base64UrlEncode($details['ec']['y']),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     * @param mixed $privateKey
     */
    private function signRs256Token(array $header, array $payload, mixed $privateKey): string
    {
        $encodedHeader = Crypto::base64UrlEncode((string) json_encode($header));
        $encodedPayload = Crypto::base64UrlEncode((string) json_encode($payload));
        $input = $encodedHeader . '.' . $encodedPayload;
        $signed = openssl_sign($input, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            self::fail('Unable to sign RS256 test token.');
        }

        return $input . '.' . Crypto::base64UrlEncode($signature);
    }

    /**
     * @param array<string, mixed> $header
     * @param array<string, mixed> $payload
     * @param mixed $privateKey
     */
    private function signEs256Token(array $header, array $payload, mixed $privateKey): string
    {
        $encodedHeader = Crypto::base64UrlEncode((string) json_encode($header));
        $encodedPayload = Crypto::base64UrlEncode((string) json_encode($payload));
        $input = $encodedHeader . '.' . $encodedPayload;
        $signed = openssl_sign($input, $derSignature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            self::fail('Unable to sign ES256 test token.');
        }

        return $input . '.' . Crypto::base64UrlEncode($this->ecdsaDerSignatureToJose($derSignature));
    }

    private function ecdsaDerSignatureToJose(string $signature): string
    {
        $offset = 0;
        if ($signature[$offset++] !== "\x30") {
            self::fail('Invalid ECDSA DER signature sequence.');
        }

        $this->readDerLength($signature, $offset);
        $r = $this->readDerInteger($signature, $offset);
        $s = $this->readDerInteger($signature, $offset);

        return str_pad(ltrim($r, "\x00"), 32, "\x00", STR_PAD_LEFT)
            . str_pad(ltrim($s, "\x00"), 32, "\x00", STR_PAD_LEFT);
    }

    private function readDerInteger(string $signature, int &$offset): string
    {
        if (($signature[$offset++] ?? '') !== "\x02") {
            self::fail('Invalid ECDSA DER integer.');
        }

        $length = $this->readDerLength($signature, $offset);
        $value = substr($signature, $offset, $length);
        $offset += $length;

        return $value;
    }

    private function readDerLength(string $signature, int &$offset): int
    {
        $length = ord($signature[$offset++]);
        if (($length & 0x80) === 0) {
            return $length;
        }

        $bytes = $length & 0x7f;
        $length = 0;
        for ($i = 0; $i < $bytes; $i++) {
            $length = ($length << 8) | ord($signature[$offset++]);
        }

        return $length;
    }

    private function requireOpenSsl(): void
    {
        if (!function_exists('openssl_pkey_new') || !function_exists('openssl_sign')) {
            self::markTestSkipped('OpenSSL is unavailable.');
        }
    }
}

final class RefreshingJwksProvider implements JwksProviderInterface
{
    public int $refreshes = 0;

    /**
     * @param list<array<string, string>> $keys
     * @param list<array<string, string>> $refreshedKeys
     */
    public function __construct(
        private array $keys,
        private readonly array $refreshedKeys
    ) {
    }

    public function keys(): array
    {
        return $this->keys;
    }

    public function refresh(): void
    {
        $this->refreshes++;
        $this->keys = $this->refreshedKeys;
    }
}

final class SecurityBearerVerifier implements BearerTokenVerifierInterface
{
    /**
     * @param array<string, mixed> $claims
     */
    public function __construct(private readonly array $claims)
    {
    }

    public function verify(string $token): array
    {
        return $this->claims;
    }
}

final class SecurityDispatcher implements DispatcherInterface
{
    /** @var list<array{namespace: string, route: RouteDefinition, callback: callable, permissionCallback: callable}> */
    public array $registrations = [];

    public function register(
        string $namespace,
        RouteDefinition $route,
        callable $callback,
        callable $permissionCallback
    ): void {
        $this->registrations[] = [
            'namespace' => $namespace,
            'route' => $route,
            'callback' => $callback,
            'permissionCallback' => $permissionCallback,
        ];
    }
}

final class SecurityRequest
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $params
     */
    public function __construct(
        array $headers,
        private readonly string $method = 'GET',
        private array $params = [],
        private readonly string $body = '',
        private readonly string $route = '/'
    ) {
        $normalized = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_scalar($value)) {
                $normalized[strtolower($name)] = (string) $value;
            }
        }
        $this->headers = $normalized;
    }

    public function get_header(string $name): string
    {
        return $this->headers[strtolower($name)] ?? '';
    }

    public function get_method(): string
    {
        return $this->method;
    }

    public function get_body(): string
    {
        return $this->body;
    }

    public function get_route(): string
    {
        return $this->route;
    }

    public function get_param(string $name): ?string
    {
        $value = $this->params[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    public function set_param(string $name, mixed $value): void
    {
        $this->params[$name] = $value;
    }
}
