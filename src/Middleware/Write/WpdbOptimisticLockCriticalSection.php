<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\RequestContext;
use BetterRoute\Support\Canonicalizer;
use RuntimeException;

final class WpdbOptimisticLockCriticalSection implements OptimisticLockCriticalSectionInterface
{
    public function __construct(private readonly int $timeoutSeconds = 2)
    {
        if ($timeoutSeconds < 0) {
            throw new \InvalidArgumentException('Lock timeout must not be negative.');
        }
    }

    public function execute(RequestContext $context, callable $callback): mixed
    {
        $wpdb = $this->wpdb();
        $lockName = 'better_route_lock_' . sha1($context->routePath . '|' . Canonicalizer::json(
            $this->routeParameters($context->request)
        ));
        $acquired = $wpdb->get_var($wpdb->prepare(
            'SELECT GET_LOCK(%s, %d)',
            $lockName,
            $this->timeoutSeconds
        ));
        if ((int) $acquired !== 1) {
            throw new RuntimeException('Unable to acquire optimistic-lock critical section.');
        }

        try {
            return $callback();
        } finally {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lockName));
        }
    }

    /** @return array<string, mixed> */
    private function routeParameters(mixed $request): array
    {
        if (is_object($request) && method_exists($request, 'get_url_params')) {
            $params = $request->get_url_params();
            return is_array($params) ? $params : [];
        }

        return [];
    }

    private function wpdb(): object
    {
        if (
            isset($GLOBALS['wpdb'])
            && is_object($GLOBALS['wpdb'])
            && method_exists($GLOBALS['wpdb'], 'prepare')
            && method_exists($GLOBALS['wpdb'], 'get_var')
        ) {
            return $GLOBALS['wpdb'];
        }

        throw new RuntimeException('Global $wpdb is required for atomic optimistic locking.');
    }
}
