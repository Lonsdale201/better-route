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
        if ($this->isWpError($response)) {
            return $response;
        }

        $status = $this->status($response);
        if ($status < 200 || $status >= 300 || $status === 204) {
            return $response;
        }

        $etag = $this->etagFor($response, $context);
        if ($this->matches($context->request, $etag)) {
            return new Response(null, 304, $this->notModifiedHeaders($response, $etag));
        }

        if ($response instanceof Response) {
            return new Response(
                body: $response->body,
                status: $response->status,
                headers: array_merge($response->headers, ['ETag' => $etag])
            );
        }

        if ($this->isWpRestResponse($response)) {
            $response->header('ETag', $etag);
            return $response;
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

        $body = $this->body($response);
        $encoded = json_encode($body);
        if (!is_string($encoded)) {
            $encoded = serialize($body);
        }

        return $this->quote(sha1($encoded));
    }

    private function quote(string $value): string
    {
        $trimmed = trim($value);
        $weakPrefix = str_starts_with($trimmed, 'W/');
        $opaque = $weakPrefix ? trim(substr($trimmed, 2)) : $trimmed;
        if (str_starts_with($opaque, '"') && str_ends_with($opaque, '"') && strlen($opaque) >= 2) {
            $opaque = substr($opaque, 1, -1);
        }

        // RFC 9110 opaque-tag: reject quotes/control bytes supplied by a custom
        // resolver instead of forwarding them into a WordPress header call.
        if (preg_match('/^[\x21\x23-\x7E\x80-\xFF]*$/D', $opaque) !== 1) {
            $opaque = sha1($value);
            $weakPrefix = false;
        }

        return (($this->weak || $weakPrefix) ? 'W/' : '') . '"' . $opaque . '"';
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
            if ($candidate === '*' || $this->weakTag($candidate) === $this->weakTag($etag)) {
                return true;
            }
        }

        return false;
    }

    private function status(mixed $response): int
    {
        if ($response instanceof Response) {
            return $response->status;
        }

        if ($this->isWpRestResponse($response)) {
            return (int) $response->get_status();
        }

        return 200;
    }

    private function body(mixed $response): mixed
    {
        if ($response instanceof Response) {
            return $response->body;
        }

        if ($this->isWpRestResponse($response)) {
            return $response->get_data();
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function notModifiedHeaders(mixed $response, string $etag): array
    {
        $headers = ['ETag' => $etag];
        $source = [];

        if ($response instanceof Response) {
            $source = $response->headers;
        } elseif ($this->isWpRestResponse($response)) {
            $candidate = $response->get_headers();
            $source = is_array($candidate) ? $candidate : [];
        }

        $preserved = ['cache-control', 'content-location', 'expires', 'vary'];
        foreach ($source as $name => $value) {
            if (is_string($name) && is_string($value) && in_array(strtolower($name), $preserved, true)) {
                $headers[$name] = $value;
            }
        }

        return $headers;
    }

    private function weakTag(string $etag): string
    {
        $etag = trim($etag);
        if (str_starts_with($etag, 'W/')) {
            $etag = trim(substr($etag, 2));
        }

        return $etag;
    }

    private function isWpRestResponse(mixed $response): bool
    {
        return is_object($response)
            && method_exists($response, 'get_status')
            && method_exists($response, 'get_data')
            && method_exists($response, 'get_headers')
            && method_exists($response, 'header');
    }

    private function isWpError(mixed $response): bool
    {
        return class_exists('WP_Error') && $response instanceof \WP_Error;
    }
}
