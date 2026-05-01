<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Jwt;

final class StaticJwksProvider implements JwksProviderInterface
{
    /** @var list<array<string, string>> */
    private array $keys;

    /**
     * @param list<array<string, mixed>> $keys
     */
    public function __construct(array $keys)
    {
        $this->keys = JwksKeySanitizer::sanitizeKeys($keys);
    }

    public function keys(): array
    {
        return $this->keys;
    }

    public function refresh(): void
    {
    }
}
