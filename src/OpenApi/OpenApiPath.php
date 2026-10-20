<?php

declare(strict_types=1);

namespace BetterRoute\OpenApi;

use InvalidArgumentException;

/** Converts balanced named captures; arbitrary PCRE routing is not an OpenAPI path. */
final class OpenApiPath
{
    public static function fromRoute(string $namespace, string $path): string
    {
        $result = '';
        $length = strlen($path);
        for ($i = 0; $i < $length; ++$i) {
            if (substr($path, $i, 4) !== '(?P<') {
                if ($path[$i] === '\\' && $i + 1 < $length && str_contains('.^$()[]|*+?\\', $path[$i + 1])) {
                    $result .= $path[++$i];
                    continue;
                }
                if (str_contains('.^$()[]|*+?{}\\', $path[$i])) {
                    throw new InvalidArgumentException('Unsupported OpenAPI route pattern: ' . $path);
                }
                $result .= $path[$i];
                continue;
            }
            $endName = strpos($path, '>', $i + 4);
            if ($endName === false) {
                throw new InvalidArgumentException('Invalid named route capture.');
            }
            $name = substr($path, $i + 4, $endName - $i - 4);
            if (preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1) {
                throw new InvalidArgumentException('Invalid route parameter name.');
            }
            $depth = 1;
            $class = false;
            $escaped = false;
            for ($i = $endName + 1; $i < $length; ++$i) {
                $char = $path[$i];
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;
                } elseif ($char === '[') {
                    $class = true;
                } elseif ($char === ']') {
                    $class = false;
                } elseif (!$class && $char === '(') {
                    ++$depth;
                } elseif (!$class && $char === ')' && --$depth === 0) {
                    break;
                }
            }
            if ($depth !== 0) {
                throw new InvalidArgumentException('Unclosed named route capture.');
            }
            $result .= '{' . $name . '}';
        }
        return '/' . trim(trim($namespace, '/') . '/' . trim($result, '/'), '/');
    }
}
