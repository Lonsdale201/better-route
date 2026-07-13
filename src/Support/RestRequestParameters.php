<?php

declare(strict_types=1);

namespace BetterRoute\Support;

final class RestRequestParameters
{
    /** @var list<string> */
    private const GLOBAL_PARAMETERS = [
        '_locale',
        '_fields',
        '_embed',
        '_envelope',
        '_jsonp',
    ];

    /**
     * @param list<string> $endpointParameters
     * @return list<string>
     */
    public static function allowedWithGlobals(array $endpointParameters): array
    {
        return array_values(array_unique(array_merge($endpointParameters, self::GLOBAL_PARAMETERS)));
    }

    public static function isGlobal(string $parameter): bool
    {
        return in_array($parameter, self::GLOBAL_PARAMETERS, true);
    }
}
