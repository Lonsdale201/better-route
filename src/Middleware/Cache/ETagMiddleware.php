<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Cache;

use BetterRoute\Http\RequestContext;
use BetterRoute\Http\Response;
use BetterRoute\Middleware\MiddlewareInterface;

final class ETagMiddleware implements MiddlewareInterface
{
    /** @var null|callable(mixed, RequestContext): string */
    private $etagResolver;

    /**
     * @param null|callable(mixed, RequestContext): string $etagResolver
     */
    public function __construct(
        private readonly bool $weak = false,
        ?callable $etagResolver = null
    ) {
        $this->etagResolver = $etagResolver;
    }

    public function handle(RequestContext $context, callable $next): mixed
    {
        if (!$this->isGetOrHead($context->request)) {
            return $next($context);
        }

        $response = $next($context);
        $status = $response instanceof Response ? $response->status : 200;
        if ($status < 200 || $status >= 300 || $status === 204) {
            return $response;
        }

        $etag = $this->etagFor($response, $context);
        if ($this->matches($context->request, $etag)) {
            return new Response(null, 304, ['ETag' => $etag]);
        }

        if ($response instanceof Response) {
            return new Response(
                body: $response->body,
                status: $response->status,
                headers: array_merge($response->headers, ['ETag' => $etag])
            );
        }

        return new Response($response, 200, ['ETag' => $etag]);
    }

    private function isGetOrHead(mixed $request): bool
    {
        if (!is_object($request) || !method_exists($request, 'get_method')) {
            return true;
        }

        $method = $request->get_method();
        return is_string($method) && in_array(strtoupper($method), ['GET', 'HEAD'], true);
    }

    private function etagFor(mixed $response, RequestContext $context): string
    {
        if ($this->etagResolver !== null) {
            $value = ($this->etagResolver)($response, $context);
            return $this->quote($value);
        }

        $body = $response instanceof Response ? $response->body : $response;
        $encoded = json_encode($body);
        if (!is_string($encoded)) {
            $encoded = serialize($body);
        }

        return $this->quote(sha1($encoded));
    }

    private function quote(string $value): string
    {
        $trimmed = trim($value);
        if (str_starts_with($trimmed, 'W/"') || (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))) {
            return $trimmed;
        }

        return ($this->weak ? 'W/' : '') . '"' . trim($trimmed, '"') . '"';
    }

    private function matches(mixed $request, string $etag): bool
    {
        if (!is_object($request) || !method_exists($request, 'get_header')) {
            return false;
        }

        $header = $request->get_header('if-none-match');
        if (!is_string($header) || trim($header) === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $candidate = trim($candidate);
            if ($candidate === '*' || $candidate === $etag) {
                return true;
            }
        }

        return false;
    }
}
