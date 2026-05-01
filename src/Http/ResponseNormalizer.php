<?php

declare(strict_types=1);

namespace BetterRoute\Http;

final class ResponseNormalizer
{
    public function __construct(
        private readonly ErrorNormalizer $errorNormalizer = new ErrorNormalizer(),
        private readonly OAuthErrorNormalizer $oauthErrorNormalizer = new OAuthErrorNormalizer()
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
            if ($this->usesOAuthErrorFormat($context)) {
                return $this->oauthErrorNormalizer->fromWpError($result, $context->requestId);
            }

            return $this->errorNormalizer->fromWpError($result, $context->requestId);
        }

        return new Response($result, 200);
    }

    public function throwable(\Throwable $throwable, RequestContext $context): Response
    {
        if ($this->usesOAuthErrorFormat($context)) {
            return $this->oauthErrorNormalizer->fromThrowable($throwable, $context->requestId);
        }

        return $this->errorNormalizer->fromThrowable($throwable, $context->requestId);
    }

    private function usesOAuthErrorFormat(RequestContext $context): bool
    {
        $routeMeta = $context->attributes['routeMeta'] ?? null;
        return is_array($routeMeta) && ($routeMeta['error_format'] ?? null) === 'oauth_rfc6749';
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
