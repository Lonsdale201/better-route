<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Audit;

interface AuditLoggerInterface
{
    /**
     * @param array<string, mixed> $event
     */
    public function log(array $event): void;
}
