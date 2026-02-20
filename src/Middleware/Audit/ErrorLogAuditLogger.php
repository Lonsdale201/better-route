<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Audit;

final class ErrorLogAuditLogger implements AuditLoggerInterface
{
    /** @var callable(string): void */
    private $writer;

    /** @var callable(mixed): (string|false) */
    private $encoder;

    /**
     * @param null|callable(string): void $writer
     * @param null|callable(mixed): (string|false) $encoder
     */
    public function __construct(
        ?callable $writer = null,
        ?callable $encoder = null
    ) {
        $this->writer = $writer ?? static function (string $line): void {
            error_log($line);
        };

        $this->encoder = $encoder ?? static fn (mixed $value): string|false => json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    public function log(array $event): void
    {
        $encoded = ($this->encoder)($event);
        $line = $encoded === false ? '{"error":"audit_encode_failed"}' : $encoded;
        ($this->writer)('[better-route] ' . $line);
    }
}
