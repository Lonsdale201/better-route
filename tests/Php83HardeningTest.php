<?php

declare(strict_types=1);

namespace BetterRoute\Tests;

use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\Auth\ApplicationPasswordAuthMiddleware;
use BetterRoute\Middleware\Auth\BearerTokenAuthMiddleware;
use BetterRoute\Middleware\Auth\BearerTokenVerifierInterface;
use BetterRoute\Middleware\Auth\CookieNonceAuthMiddleware;
use BetterRoute\Middleware\Auth\JwtBearerTokenVerifierAdapter;
use BetterRoute\Middleware\Jwt\Hs256JwtVerifier;
use BetterRoute\Middleware\Jwt\JwtAuthMiddleware;
use BetterRoute\Middleware\Jwt\JwtVerifierInterface;
use BetterRoute\Middleware\Jwt\Rs256JwksJwtVerifier;
use BetterRoute\Middleware\Jwt\StaticJwksProvider;
use BetterRoute\Middleware\Write\ArraySingleUseTokenStore;
use BetterRoute\Middleware\Write\SingleUseTokenMiddleware;
use BetterRoute\Support\Crypto;
use Error;
use Override;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionFunction;
use ReflectionProperty;
use RuntimeException;
use SensitiveParameter;
use SensitiveParameterValue;
use stdClass;
use Throwable;

final class Php83HardeningTest extends TestCase
{
    private string|false $previousIgnoreArgs;

    protected function setUp(): void
    {
        $this->previousIgnoreArgs = ini_set('zend.exception_ignore_args', '0');
        self::assertSame('0', ini_get('zend.exception_ignore_args'));
    }

    protected function tearDown(): void
    {
        if ($this->previousIgnoreArgs !== false) {
            ini_set('zend.exception_ignore_args', $this->previousIgnoreArgs);
        }
    }

    public function testConstructorFailuresRedactValidSecretsPassedWithInvalidOptions(): void
    {
        $secret = 'constructor-secret-sentinel';
        $exception = $this->capture(static fn () => new Hs256JwtVerifier($secret, maxTokenLength: 0));
        self::assertSame('JWT max token length must be positive.', $exception->getMessage());
        $this->assertRedactedLibraryFrames($exception, [$secret], ['__construct']);

        $salt = 'token-salt-sentinel';
        $exception = $this->capture(static fn () => new SingleUseTokenMiddleware(
            new ArraySingleUseTokenStore(),
            static fn (): string => 'unused',
            hashSalt: $salt,
            ttlSeconds: 0
        ));
        self::assertSame('Single-use token TTL must be positive.', $exception->getMessage());
        $this->assertRedactedLibraryFrames($exception, [$salt], ['__construct']);
    }

    public function testMalformedTokensAreRedactedThroughBothVerifiersAndBearerAdapter(): void
    {
        $token = '***private-header-sentinel***.e30.c2ln';
        $verifiers = [
            new Hs256JwtVerifier('test-secret'),
            new Rs256JwksJwtVerifier(new StaticJwksProvider([])),
            new JwtBearerTokenVerifierAdapter(new Hs256JwtVerifier('test-secret')),
        ];

        foreach ($verifiers as $verifier) {
            $exception = $this->capture(static fn () => $verifier->verify($token));
            $this->assertRedactedLibraryFrames(
                $exception,
                [$token, '***private-header-sentinel***'],
                ['verify', 'decodePart', 'base64UrlDecode']
            );
        }
    }

    public function testInvalidAndExpiredSignedClaimsAreRedactedInNestedFrames(): void
    {
        $verifier = new Hs256JwtVerifier('test-secret', now: static fn (): int => 1700000000);
        $invalidToken = $this->signHs256(['sub' => 'private-subject-sentinel', 'exp' => 'private-exp-sentinel']);
        $exception = $this->capture(static fn () => $verifier->verify($invalidToken));
        self::assertSame('JWT claim exp must be numeric.', $exception->getMessage());
        $this->assertRedactedLibraryFrames(
            $exception,
            [$invalidToken, 'private-subject-sentinel', 'private-exp-sentinel'],
            ['verify', 'assertTimeClaims', 'parseNumericClaim']
        );

        $expiredToken = $this->signHs256(['sub' => 'expired-subject-sentinel', 'exp' => 1699999999]);
        $exception = $this->capture(static fn () => $verifier->verify($expiredToken));
        self::assertSame('JWT expired.', $exception->getMessage());
        $this->assertRedactedLibraryFrames(
            $exception,
            [$expiredToken, 'expired-subject-sentinel'],
            ['verify', 'assertTimeClaims']
        );
    }

    public function testDefaultWordPressAuthClosuresRedactTheirOwnCredentialParameters(): void
    {
        $cases = [
            [new ApplicationPasswordAuthMiddleware(), 'authenticate', ['username', 'password']],
            [new CookieNonceAuthMiddleware(), 'verifyNonce', ['nonce']],
        ];

        foreach ($cases as [$middleware, $propertyName, $parameterNames]) {
            $callback = (new ReflectionProperty($middleware, $propertyName))->getValue($middleware);
            self::assertInstanceOf(\Closure::class, $callback);
            $parameters = (new ReflectionFunction($callback))->getParameters();
            foreach ($parameters as $parameter) {
                if (in_array($parameter->getName(), $parameterNames, true)) {
                    self::assertCount(1, $parameter->getAttributes(SensitiveParameter::class));
                }
            }
        }
    }

    public function testScopeTypeErrorsDoNotExposeGrantedClaimScopes(): void
    {
        $request = new class () {
            public function get_header(string $name): string
            {
                return 'Bearer scope-test-token';
            }
        };
        foreach ([BearerTokenAuthMiddleware::class, JwtAuthMiddleware::class] as $class) {
            // Exercise a server configuration mistake, outside the declared list<string> contract.
            // @phpstan-ignore-next-line
            $middleware = new $class(new ScopeTraceVerifier(), requiredScopes: [new stdClass()]);
            $exception = $this->capture(static fn () => $middleware->handle(
                new RequestContext('scope-trace', '/scope-trace', $request),
                static fn (): null => null
            ));
            self::assertInstanceOf(\TypeError::class, $exception);
            $this->assertRedactedLibraryFrames(
                $exception,
                ['scope-private-sentinel'],
                ['hasRequiredScopes', 'scopeMatches']
            );
        }
    }

    public function testInterfaceAnnotationAloneDoesNotRedactAnImplementation(): void
    {
        $marker = 'interface-only-sentinel';
        $unannotated = new UnannotatedTraceFixture();
        $exception = $this->capture(static fn () => $unannotated->fail($marker));
        self::assertSame($marker, $exception->getTrace()[0]['args'][0]);

        $annotated = new AnnotatedTraceFixture();
        $exception = $this->capture(static fn () => $annotated->fail($marker));
        self::assertInstanceOf(SensitiveParameterValue::class, $exception->getTrace()[0]['args'][0]);
    }

    public function testProductionAttributesAreValidAndInterfaceSecretsAreCoveredInImplementations(): void
    {
        $root = dirname(__DIR__) . '/src/';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        $overrides = 0;
        $sensitiveParameters = 0;

        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relativePath = substr($file->getPathname(), strlen($root), -4);
            $className = 'BetterRoute\\' . str_replace(['/', '\\'], '\\', $relativePath);
            self::assertTrue(class_exists($className) || interface_exists($className) || enum_exists($className));
            $class = new ReflectionClass($className);

            foreach ($class->getMethods() as $method) {
                foreach ($method->getAttributes(Override::class) as $attribute) {
                    self::assertFalse($method->isConstructor());
                    self::assertTrue($method->hasPrototype(), $className . '::' . $method->getName());
                    self::assertInstanceOf(Override::class, $attribute->newInstance());
                    $overrides++;
                }
                foreach ($method->getParameters() as $parameter) {
                    foreach ($parameter->getAttributes(SensitiveParameter::class) as $attribute) {
                        self::assertInstanceOf(SensitiveParameter::class, $attribute->newInstance());
                        $sensitiveParameters++;
                    }
                }
            }

            if ($class->isInterface()) {
                continue;
            }
            foreach ($class->getInterfaces() as $interface) {
                foreach ($interface->getMethods() as $method) {
                    $implementation = $class->getMethod($method->getName())->getParameters();
                    foreach ($method->getParameters() as $position => $parameter) {
                        if ($parameter->getAttributes(SensitiveParameter::class) !== []) {
                            self::assertCount(1, $implementation[$position]->getAttributes(SensitiveParameter::class));
                        }
                    }
                }
            }
        }

        self::assertGreaterThan(0, $overrides);
        self::assertGreaterThan(0, $sensitiveParameters);
    }

    public function testReadonlyContextPreservesCopyBehaviorAndShallowObjectSemantics(): void
    {
        $request = new stdClass();
        $request->state = 'before';
        $context = new RequestContext('request-id', '/items', $request);
        $copy = $context->withAttribute('key', 'value');
        self::assertNotSame($context, $copy);
        self::assertSame([], $context->attributes);
        self::assertSame(['key' => 'value'], $copy->attributes);
        self::assertSame($request, $copy->request);

        $request->state = 'after';
        self::assertSame('after', $context->request->state);
        self::assertTrue((new ReflectionClass(RequestContext::class))->isReadOnly());

        $this->expectException(Error::class);
        // Deliberately exercise the consumer error that readonly classes prohibit.
        // @phpstan-ignore-next-line
        $context->undeclared = 'forbidden';
    }

    /** @param array<string, mixed> $claims */
    private function signHs256(array $claims): string
    {
        $header = Crypto::base64UrlEncode('{"alg":"HS256"}');
        $payload = Crypto::base64UrlEncode(json_encode($claims, JSON_THROW_ON_ERROR));
        $input = $header . '.' . $payload;

        return $input . '.' . Crypto::base64UrlEncode(hash_hmac('sha256', $input, 'test-secret', true));
    }

    private function capture(callable $operation): Throwable
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            return $exception;
        }

        self::fail('Expected the failing security operation to throw.');
    }

    /**
     * @param list<string> $markers
     * @param list<string> $expectedMethods
     */
    private function assertRedactedLibraryFrames(Throwable $exception, array $markers, array $expectedMethods): void
    {
        $methods = [];
        foreach ($exception->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            if (!str_starts_with($class, 'BetterRoute\\') || str_starts_with($class, __NAMESPACE__ . '\\')) {
                continue;
            }
            $methods[] = $frame['function'];
            self::assertArrayHasKey('args', $frame, 'The test must collect exception arguments.');
            $this->assertNoSensitiveScalars($frame['args'], $markers);
        }
        foreach ($expectedMethods as $method) {
            self::assertContains($method, $methods);
        }
        foreach ($markers as $marker) {
            self::assertStringNotContainsString($marker, $exception->getTraceAsString());
        }
    }

    /** @param list<string> $markers */
    private function assertNoSensitiveScalars(mixed $argument, array $markers): void
    {
        if (is_array($argument)) {
            foreach ($argument as $value) {
                $this->assertNoSensitiveScalars($value, $markers);
            }
        } elseif (is_string($argument)) {
            foreach ($markers as $marker) {
                self::assertStringNotContainsString($marker, $argument);
            }
        }
    }
}

interface SensitiveTraceFixtureInterface
{
    public function fail(#[SensitiveParameter] string $secret): never;
}

final class UnannotatedTraceFixture implements SensitiveTraceFixtureInterface
{
    public function fail(string $secret): never
    {
        throw new RuntimeException('Unannotated fixture.');
    }
}

final class AnnotatedTraceFixture implements SensitiveTraceFixtureInterface
{
    public function fail(#[SensitiveParameter] string $secret): never
    {
        throw new RuntimeException('Annotated fixture.');
    }
}

final class ScopeTraceVerifier implements JwtVerifierInterface, BearerTokenVerifierInterface
{
    public function verify(string $token): array
    {
        return ['scope' => 'scope-private-sentinel'];
    }
}
