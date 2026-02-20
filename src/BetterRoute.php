<?php

declare(strict_types=1);

namespace BetterRoute;

use BetterRoute\Router\Router;

final class BetterRoute
{
    public static function router(string $vendor, string $version): Router
    {
        return Router::make($vendor, $version);
    }
}
