<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Network;

use BetterRoute\Http\ApiException;
use BetterRoute\Http\RequestContext;
use BetterRoute\Middleware\MiddlewareInterface;

final class IpAllowlistMiddleware implements MiddlewareInterface
{
    /** @var list<string> */
    private array $allowedCidrs;

    /**
     * @param list<string> $allowedCidrs
     */
    public function __construct(
        array $allowedCidrs,
        private readonly ClientIpResolverInterface $ipResolver,
        private readonly bool $failClosed = true
    ) {
        foreach ($allowedCidrs as $cidr) {
            CidrMatcher::assertValid($cidr);
        }

        $this->allowedCidrs = array_values($allowedCidrs);
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        $clientIp = $this->ipResolver->resolve($context->request);
        if ($clientIp === null) {
            if ($this->failClosed) {
                throw new ApiException('Client IP is unavailable.', 403, 'client_ip_unavailable');
            }

            return $next($context);
        }

        foreach ($this->allowedCidrs as $cidr) {
            if (CidrMatcher::matches($clientIp, $cidr)) {
                return $next($context->withAttribute('clientIp', $clientIp));
            }
        }

        throw new ApiException('Client IP is not allowed.', 403, 'client_ip_not_allowed');
    }
}
