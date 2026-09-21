<?php

declare(strict_types=1);

namespace BetterRoute\Support;

use BetterRoute\Http\RequestContext;

/** @internal Request identity for response caches and write replay records. */
final class RequestRoute
{
    /** @return array{namespace: string, template: string, path: string, urlParams: array<string, mixed>} */
    public static function scope(RequestContext $context): array
    {
        $namespace = $context->attributes['routeNamespace'] ?? '';
        $path = $context->routePath;
        $urlParams = [];
        if (is_object($context->request)) {
            if (method_exists($context->request, 'get_route')) {
                $route = $context->request->get_route();
                if (is_string($route) && $route !== '') {
                    $path = $route;
                }
            }
            if (method_exists($context->request, 'get_url_params')) {
                $params = $context->request->get_url_params();
                if (is_array($params)) {
                    $urlParams = $params;
                }
            }
        }

        return [
            'namespace' => is_string($namespace) ? $namespace : '',
            'template' => $context->routePath,
            'path' => $path,
            'urlParams' => $urlParams,
        ];
    }
}
