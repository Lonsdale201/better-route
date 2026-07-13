<?php

declare(strict_types=1);

namespace BetterRoute\Support;

use RuntimeException;

final class Canonicalizer
{
    public static function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::normalize(...), $value);
        }

        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalize($item);
        }

        return $value;
    }

    public static function json(mixed $value): string
    {
        $encoded = json_encode(self::normalize($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new RuntimeException('Unable to canonicalize request data.');
        }

        return $encoded;
    }
}
