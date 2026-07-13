<?php

declare(strict_types=1);

namespace BetterRoute\Http;

use Throwable;

final class OAuthErrorNormalizer
{
    public function fromThrowable(Throwable $throwable, string $requestId): Response
    {
        $status = $throwable instanceof ApiException
            ? $throwable->status()
            : ($throwable instanceof \InvalidArgumentException ? 400 : 500);
        $code = $throwable instanceof ApiException
            ? $throwable->errorCode()
            : ($status === 400 ? 'invalid_request' : 'server_error');
        $details = $throwable instanceof ApiException ? $throwable->details() : [];
        $message = $throwable instanceof ApiException
            ? ($throwable->getMessage() !== '' ? $throwable->getMessage() : 'Invalid request.')
            : ($status === 400 ? 'Invalid request.' : 'Unexpected error.');

        return $this->response(
            $code,
            $message,
            $status,
            $requestId,
            $details,
            $throwable instanceof ApiException ? $throwable->headers() : []
        );
    }

    public function fromWpError(object $wpError, string $requestId): Response
    {
        $code = method_exists($wpError, 'get_error_code')
            ? (string) $wpError->get_error_code()
            : 'invalid_request';
        $message = method_exists($wpError, 'get_error_message')
            ? (string) $wpError->get_error_message()
            : 'Invalid request.';
        $data = method_exists($wpError, 'get_error_data')
            ? $wpError->get_error_data()
            : null;

        $status = 400;
        $details = [];
        if (is_array($data)) {
            if (isset($data['status']) && is_int($data['status'])) {
                $status = $data['status'];
            }
            if (is_array($data['params'] ?? null)) {
                $details['params'] = $data['params'];
            }
        }

        if ($status < 400 || $status > 599) {
            $status = 400;
        }

        return $this->response(
            $code !== '' ? $code : 'invalid_request',
            $message !== '' ? $message : 'Invalid request.',
            $status,
            $requestId,
            $details,
            []
        );
    }

    /**
     * @param array<string, mixed> $details
     * @param array<string, string> $headers
     */
    private function response(
        string $code,
        string $message,
        int $status,
        string $requestId,
        array $details,
        array $headers
    ): Response {
        $body = [
            'error' => $this->normalizeCode($code, $status),
            'error_description' => $message,
        ];

        $errorUri = $details['error_uri'] ?? ($details['errorUri'] ?? null);
        if (is_string($errorUri) && $errorUri !== '') {
            $body['error_uri'] = $errorUri;
        }

        if (isset($details['requestId']) && $details['requestId'] === true) {
            $body['request_id'] = $requestId;
        }

        return new Response($body, $status, $headers);
    }

    private function normalizeCode(string $code, int $status): string
    {
        if ($status >= 500 && $code === 'internal_error') {
            return 'server_error';
        }

        return $code !== '' ? $code : 'invalid_request';
    }
}
