<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Jwt;

use RuntimeException;

final class Hs256JwtVerifier implements JwtVerifierInterface
{
    /** @var callable(): int */
    private $now;

    /**
     * @param null|callable(): int $now
     */
    public function __construct(
        private readonly string $secret,
        private readonly int $leewaySeconds = 0,
        ?callable $now = null
    ) {
        if ($secret === '') {
            throw new RuntimeException('JWT secret must not be empty.');
        }

        $this->now = $now ?? static fn (): int => time();
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed JWT.');
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;

        $header = $this->decodePart($encodedHeader, 'header');
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Unsupported JWT alg.');
        }

        $payload = $this->decodePart($encodedPayload, 'payload');

        $expected = $this->sign($encodedHeader . '.' . $encodedPayload);
        $signature = $this->decodeRaw($encodedSignature);

        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid JWT signature.');
        }

        $this->assertTimeClaims($payload);

        return $payload;
    }

    private function sign(string $input): string
    {
        return hash_hmac('sha256', $input, $this->secret, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePart(string $part, string $name): array
    {
        $decoded = $this->decodeRaw($part);
        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new RuntimeException(sprintf('Invalid JWT %s JSON.', $name));
        }

        return $payload;
    }

    private function decodeRaw(string $part): string
    {
        $normalized = strtr($part, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if ($decoded === false) {
            throw new RuntimeException('Invalid JWT base64 payload.');
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function assertTimeClaims(array $claims): void
    {
        $now = ($this->now)();

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
        }
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
