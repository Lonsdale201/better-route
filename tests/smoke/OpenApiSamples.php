<?php

declare(strict_types=1);

namespace BetterRouteSmoke;

/** Test-only opt-in wire capture; never enable on production data. */
function writeOpenApiSamples(string $name, array $samples): void
{
    $directory = getenv('BR_OPENAPI_SAMPLE_DIR');
    if ($directory === false || $directory === '') {
        return;
    }
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new \RuntimeException('Cannot create OpenAPI sample directory.');
    }
    $written = file_put_contents($directory . '/' . $name . '.json', json_encode($samples, JSON_THROW_ON_ERROR));
    if ($written === false) {
        throw new \RuntimeException('Cannot write OpenAPI wire samples.');
    }
}
