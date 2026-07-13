<?php

declare(strict_types=1);

namespace BetterRoute\Middleware\Write;

use BetterRoute\Http\Response;
use RuntimeException;

final class StoredResponseCodec
{
    public static function normalizeForStorage(mixed $response): mixed
    {
        if ($response instanceof Response) {
            return $response;
        }

        if (self::isWpRestResponse($response)) {
            return new Response(
                $response->get_data(),
                (int) $response->get_status(),
                self::stringHeaders($response->get_headers())
            );
        }

        return $response;
    }

    public static function encode(mixed $response): string
    {
        $response = self::normalizeForStorage($response);
        if ($response instanceof Response) {
            self::assertDataOnly($response->body);
            return serialize([
                'kind' => 'response',
                'body' => $response->body,
                'status' => $response->status,
                'headers' => $response->headers,
            ]);
        }

        self::assertDataOnly($response);
        return serialize(['kind' => 'raw', 'body' => $response]);
    }

    public static function decode(string $encoded): mixed
    {
        if ($encoded === '') {
            return null;
        }

        $value = unserialize($encoded, ['allowed_classes' => false]);
        if (!is_array($value) || !is_string($value['kind'] ?? null)) {
            throw new RuntimeException('Invalid stored idempotency response.');
        }

        if ($value['kind'] === 'raw') {
            $body = $value['body'] ?? null;
            self::assertDataOnly($body);
            return $body;
        }

        if ($value['kind'] !== 'response') {
            throw new RuntimeException('Unsupported stored idempotency response.');
        }

        $status = $value['status'] ?? null;
        $headers = $value['headers'] ?? null;
        if (!is_int($status) || !is_array($headers)) {
            throw new RuntimeException('Invalid stored idempotency response metadata.');
        }

        $body = $value['body'] ?? null;
        self::assertDataOnly($body);

        return new Response($body, $status, self::stringHeaders($headers));
    }

    private static function assertDataOnly(mixed $value): void
    {
        if (is_object($value) || is_resource($value)) {
            throw new RuntimeException('Idempotency responses must contain data or an HTTP response object.');
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                self::assertDataOnly($item);
            }
        }
    }

    /** @return array<string, string> */
    private static function stringHeaders(mixed $headers): array
    {
        if (!is_array($headers)) {
            return [];
        }

        $result = [];
        foreach ($headers as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $result[$name] = $value;
            }
        }

        return $result;
    }

    private static function isWpRestResponse(mixed $response): bool
    {
        return is_object($response)
            && method_exists($response, 'get_data')
            && method_exists($response, 'get_status')
            && method_exists($response, 'get_headers');
    }
}
