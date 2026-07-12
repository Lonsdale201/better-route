<?php

declare(strict_types=1);

namespace BetterRoute\Http;

use Throwable;

final class ErrorNormalizer
{
    public function fromThrowable(Throwable $throwable, string $requestId): Response
    {
        if ($throwable instanceof ApiException) {
            $status = $throwable->status();
            $code = $throwable->errorCode();
            $details = $throwable->details();
            $message = $throwable->getMessage() !== '' ? $throwable->getMessage() : 'Invalid request.';
        } else {
            // Never leak internal exception class names or raw messages to the
            // client for uncaught throwables — only intentional ApiExceptions
            // carry client-facing detail.
            $status = $throwable instanceof \InvalidArgumentException ? 400 : 500;
            $code = $status === 400 ? 'invalid_request' : 'internal_error';
            $details = [];
            $message = $status === 400 ? 'Invalid request.' : 'Unexpected error.';
        }

        return new Response(
            body: [
                'error' => [
                    'code' => $code,
                    'message' => $message,
                    'requestId' => $requestId,
                    'details' => $details,
                ],
            ],
            status: $status
        );
    }

    public function fromWpError(object $wpError, string $requestId): Response
    {
        $code = method_exists($wpError, 'get_error_code')
            ? (string) $wpError->get_error_code()
            : 'wp_error';
        $message = method_exists($wpError, 'get_error_message')
            ? (string) $wpError->get_error_message()
            : 'WordPress error.';
        $data = method_exists($wpError, 'get_error_data')
            ? $wpError->get_error_data()
            : null;

        $status = 500;
        $details = [];

        if (is_array($data)) {
            if (isset($data['status']) && is_int($data['status'])) {
                $status = $data['status'];
            }
            $details = $data;
            unset($details['status']);
        }

        return new Response(
            body: [
                'error' => [
                    'code' => $code !== '' ? $code : 'wp_error',
                    'message' => $message !== '' ? $message : 'WordPress error.',
                    'requestId' => $requestId,
                    'details' => $details,
                ],
            ],
            status: $status
        );
    }
}
