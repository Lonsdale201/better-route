<?php

declare(strict_types=1);

namespace BetterRoute\Http;

final class ResponseNormalizer
{
    public function __construct(
        private readonly ErrorNormalizer $errorNormalizer = new ErrorNormalizer()
    ) {
    }

    public function normalize(mixed $result, RequestContext $context): mixed
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($this->isWpRestResponse($result)) {
            return $result;
        }

        if ($this->isWpError($result)) {
            return $this->errorNormalizer->fromWpError($result, $context->requestId);
        }

        return new Response($result, 200);
    }

    public function throwable(\Throwable $throwable, RequestContext $context): Response
    {
        return $this->errorNormalizer->fromThrowable($throwable, $context->requestId);
    }

    private function isWpError(mixed $value): bool
    {
        return class_exists('WP_Error') && $value instanceof \WP_Error;
    }

    private function isWpRestResponse(mixed $value): bool
    {
        return class_exists('WP_REST_Response') && $value instanceof \WP_REST_Response;
    }
}
