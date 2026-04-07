<?php

declare(strict_types=1);

namespace BetterRoute;

use BetterRoute\Integration\Woo\WooOpenApiComponents;
use BetterRoute\Integration\Woo\WooRouteRegistrar;
use BetterRoute\OpenApi\OpenApiExporter;
use BetterRoute\Router\Router;

final class BetterRoute
{
    public static function router(string $vendor, string $version): Router
    {
        return Router::make($vendor, $version);
    }

    public static function openApiExporter(): OpenApiExporter
    {
        return new OpenApiExporter();
    }

    public static function wooRouteRegistrar(): WooRouteRegistrar
    {
        return new WooRouteRegistrar();
    }

    /**
     * @return array<string, mixed>
     */
    public static function wooOpenApiComponents(): array
    {
        return WooOpenApiComponents::components();
    }
}
