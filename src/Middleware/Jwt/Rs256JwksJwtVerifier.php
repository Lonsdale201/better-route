<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Jwt;

use BetterRoute\Support\Crypto;
use RuntimeException;

final class Rs256JwksJwtVerifier implements JwtVerifierInterface
{
    /** @var callable(): int */
    private $now;

    /** @var list<string> */
    private array $allowedAlgorithms;

    /**
     * @param null|callable(): int $now
     * @param list<string> $allowedAlgorithms
     */
    public function __construct(
        private readonly JwksProviderInterface $jwks,
        private readonly int $leewaySeconds = 60,
        ?callable $now = null,
        private readonly ?string $expectedIssuer = null,
        private readonly ?string $expectedAudience = null,
        private readonly bool $requireExpiration = true,
        private readonly ?int $maxLifetimeSeconds = null,
        private readonly int $maxTokenLength = 8192,
        array $allowedAlgorithms = ['RS256']
    ) {
        if ($leewaySeconds < 0) {
            throw new RuntimeException('JWT leeway must not be negative.');
        }
        if ($maxTokenLength < 1) {
            throw new RuntimeException('JWT max token length must be positive.');
        }
        if ($maxLifetimeSeconds !== null && $maxLifetimeSeconds < 1) {
            throw new RuntimeException('JWT max lifetime must be positive.');
        }

        $this->allowedAlgorithms = $this->normalizeAllowedAlgorithms($allowedAlgorithms);
        $this->now = $now ?? static fn (): int => time();
    }

    public function verify(string $token): array
    {
        if (strlen($token) > $this->maxTokenLength) {
            throw new RuntimeException('JWT is too large.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = $this->decodePart($encodedHeader, 'header');
        $alg = $this->headerString($header, 'alg');
        if (!in_array($alg, $this->allowedAlgorithms, true)) {
            throw new RuntimeException('Unsupported JWT alg.');
        }

        $kid = $this->headerString($header, 'kid');
        $key = $this->resolveKey($kid, $alg);
        if ($key === null) {
            $this->jwks->refresh();
            $key = $this->resolveKey($kid, $alg);
        }

        if ($key === null) {
            throw new RuntimeException('JWT signing key not found.');
        }

        $signature = Crypto::base64UrlDecode($encodedSignature);
        $this->assertValidSignature($alg, $encodedHeader . '.' . $encodedPayload, $signature, $key);

        $payload = $this->decodePart($encodedPayload, 'payload');
        $this->assertTimeClaims($payload);
        $this->assertIssuer($payload);
        $this->assertAudience($payload);

        return $payload;
    }

    /**
     * @param list<string> $allowedAlgorithms
     * @return list<string>
     */
    private function normalizeAllowedAlgorithms(array $allowedAlgorithms): array
    {
        $result = [];

        foreach ($allowedAlgorithms as $algorithm) {
            if (!is_string($algorithm) || $algorithm === '') {
                throw new RuntimeException('JWT allowed algorithms must be non-empty strings.');
            }

            if ($algorithm === 'none' || str_starts_with($algorithm, 'HS')) {
                throw new RuntimeException('Insecure JWT algorithms are not allowed.');
            }

            if (!in_array($algorithm, ['RS256', 'ES256'], true)) {
                throw new RuntimeException('Unsupported JWT verification algorithm.');
            }

            $result[] = $algorithm;
        }

        $result = array_values(array_unique($result));
        if ($result === []) {
            throw new RuntimeException('At least one JWT algorithm must be allowed.');
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePart(string $part, string $name): array
    {
        $decoded = Crypto::base64UrlDecode($part);
        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new RuntimeException(sprintf('Invalid JWT %s JSON.', $name));
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $header
     */
    private function headerString(array $header, string $field): string
    {
        $value = $header[$field] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('JWT %s header is required.', $field));
        }

        return $value;
    }

    /**
     * @return array<string, string>|null
     */
    private function resolveKey(string $kid, string $alg): ?array
    {
        $matches = [];
        foreach ($this->jwks->keys() as $key) {
            if (($key['kid'] ?? null) !== $kid) {
                continue;
            }

            if ($this->isUsableKeyForAlgorithm($key, $alg)) {
                $matches[] = $key;
            }
        }

        if (count($matches) > 1) {
            throw new RuntimeException('Ambiguous JWT signing key.');
        }

        return $matches[0] ?? null;
    }

    /**
     * @param array<string, string> $key
     */
    private function isUsableKeyForAlgorithm(array $key, string $alg): bool
    {
        if (($key['use'] ?? 'sig') !== 'sig') {
            return false;
        }

        if (isset($key['alg']) && $key['alg'] !== $alg) {
            return false;
        }

        if ($alg === 'RS256') {
            return ($key['kty'] ?? null) === 'RSA' && isset($key['n'], $key['e']);
        }

        if ($alg === 'ES256') {
            return ($key['kty'] ?? null) === 'EC'
                && ($key['crv'] ?? null) === 'P-256'
                && isset($key['x'], $key['y']);
        }

        return false;
    }

    /**
     * @param array<string, string> $key
     */
    private function assertValidSignature(string $alg, string $input, string $signature, array $key): void
    {
        if (!function_exists('openssl_verify')) {
            throw new RuntimeException('OpenSSL is required for JWT verification.');
        }

        $publicKey = $alg === 'RS256'
            ? $this->rsaPublicKeyPem($key)
            : $this->ecPublicKeyPem($key);

        $signatureForOpenSsl = $alg === 'ES256'
            ? $this->ecdsaJoseSignatureToDer($signature)
            : $signature;

        $result = openssl_verify($input, $signatureForOpenSsl, $publicKey, OPENSSL_ALGO_SHA256);
        if ($result !== 1) {
            throw new RuntimeException('Invalid JWT signature.');
        }
    }

    /**
     * @param array<string, string> $key
     */
    private function rsaPublicKeyPem(array $key): string
    {
        $modulus = Crypto::base64UrlDecode($key['n']);
        $exponent = Crypto::base64UrlDecode($key['e']);
        if ($modulus === '' || $exponent === '') {
            throw new RuntimeException('Invalid RSA JWK.');
        }

        $rsaPublicKey = $this->derSequence(
            $this->derInteger($modulus),
            $this->derInteger($exponent)
        );
        $algorithm = $this->derSequence(
            $this->derOid('1.2.840.113549.1.1.1'),
            "\x05\x00"
        );

        return $this->pem('PUBLIC KEY', $this->derSequence(
            $algorithm,
            $this->derBitString($rsaPublicKey)
        ));
    }

    /**
     * @param array<string, string> $key
     */
    private function ecPublicKeyPem(array $key): string
    {
        $x = Crypto::base64UrlDecode($key['x']);
        $y = Crypto::base64UrlDecode($key['y']);
        if (strlen($x) !== 32 || strlen($y) !== 32) {
            throw new RuntimeException('Invalid P-256 JWK.');
        }

        $algorithm = $this->derSequence(
            $this->derOid('1.2.840.10045.2.1'),
            $this->derOid('1.2.840.10045.3.1.7')
        );

        return $this->pem('PUBLIC KEY', $this->derSequence(
            $algorithm,
            $this->derBitString("\x04" . $x . $y)
        ));
    }

    private function ecdsaJoseSignatureToDer(string $signature): string
    {
        if (strlen($signature) !== 64) {
            throw new RuntimeException('Invalid ES256 JWT signature.');
        }

        return $this->derSequence(
            $this->derInteger(substr($signature, 0, 32)),
            $this->derInteger(substr($signature, 32, 32))
        );
    }

    private function pem(string $label, string $der): string
    {
        return sprintf(
            "-----BEGIN %s-----\n%s-----END %s-----\n",
            $label,
            chunk_split(base64_encode($der), 64, "\n"),
            $label
        );
    }

    private function derSequence(string ...$parts): string
    {
        $body = implode('', $parts);
        return "\x30" . $this->derLength(strlen($body)) . $body;
    }

    private function derInteger(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '') {
            $bytes = "\x00";
        }

        if ((ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . $this->derLength(strlen($bytes)) . $bytes;
    }

    private function derBitString(string $bytes): string
    {
        $body = "\x00" . $bytes;
        return "\x03" . $this->derLength(strlen($body)) . $body;
    }

    private function derOid(string $oid): string
    {
        $parts = array_map('intval', explode('.', $oid));
        if (count($parts) < 2) {
            throw new RuntimeException('Invalid DER OID.');
        }

        $body = chr(($parts[0] * 40) + $parts[1]);
        foreach (array_slice($parts, 2) as $part) {
            $encoded = chr($part & 0x7f);
            $part >>= 7;
            while ($part > 0) {
                $encoded = chr(($part & 0x7f) | 0x80) . $encoded;
                $part >>= 7;
            }
            $body .= $encoded;
        }

        return "\x06" . $this->derLength(strlen($body)) . $body;
    }

    private function derLength(int $length): string
    {
        if ($length < 128) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff) . $bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertTimeClaims(array $claims): void
    {
        $now = ($this->now)();

        if ($this->requireExpiration && !isset($claims['exp'])) {
            throw new RuntimeException('JWT exp is required.');
        }

        if (isset($claims['nbf'])) {
            $notBefore = $this->parseNumericClaim($claims['nbf'], 'nbf');
            if ($now + $this->leewaySeconds < $notBefore) {
                throw new RuntimeException('JWT not active yet.');
            }
        }

        if (isset($claims['iat'])) {
            $issuedAt = $this->parseNumericClaim($claims['iat'], 'iat');
            if ($issuedAt > $now + $this->leewaySeconds) {
                throw new RuntimeException('JWT iat is in the future.');
            }
        }

        if (isset($claims['exp'])) {
            $expiresAt = $this->parseNumericClaim($claims['exp'], 'exp');
            if ($now - $this->leewaySeconds >= $expiresAt) {
                throw new RuntimeException('JWT expired.');
            }

            if ($this->maxLifetimeSeconds !== null && isset($claims['iat'])) {
                $issuedAt = $this->parseNumericClaim($claims['iat'], 'iat');
                if ($expiresAt - $issuedAt > $this->maxLifetimeSeconds) {
                    throw new RuntimeException('JWT lifetime is too long.');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertIssuer(array $claims): void
    {
        if ($this->expectedIssuer === null) {
            return;
        }

        if (($claims['iss'] ?? null) !== $this->expectedIssuer) {
            throw new RuntimeException('JWT issuer mismatch.');
        }
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertAudience(array $claims): void
    {
        if ($this->expectedAudience === null) {
            return;
        }

        $audience = $claims['aud'] ?? null;
        if (is_string($audience) && $audience === $this->expectedAudience) {
            return;
        }

        if (is_array($audience) && in_array($this->expectedAudience, $audience, true)) {
            return;
        }

        throw new RuntimeException('JWT audience mismatch.');
    }

    private function parseNumericClaim(mixed $value, string $name): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw new RuntimeException(sprintf('JWT claim %s must be numeric.', $name));
    }
}
