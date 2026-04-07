<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

final class MetaDataHelper
{
    /**
     * @return list<array{key: string, value: mixed}>
     */
    public static function normalizeIncoming(mixed $metaData, string $field = 'meta_data'): array
    {
        if ($metaData === null) {
            return [];
        }

        if (!is_array($metaData)) {
            throw new ApiException('Invalid request.', 400, 'validation_failed', [
                'fieldErrors' => [$field => ['must be an array']],
            ]);
        }

        if (!array_is_list($metaData)) {
            $normalized = [];
            foreach ($metaData as $key => $value) {
                if (is_string($key) && $key !== '') {
                    $normalized[] = ['key' => $key, 'value' => $value];
                }
            }

            return $normalized;
        }

        $normalized = [];
        foreach ($metaData as $index => $entry) {
            if (!is_array($entry)) {
                throw new ApiException('Invalid request.', 400, 'validation_failed', [
                    'fieldErrors' => [$field . '.' . $index => ['must be an object with key/value']],
                ]);
            }

            $key = $entry['key'] ?? null;
            if (!is_string($key) || $key === '') {
                throw new ApiException('Invalid request.', 400, 'validation_failed', [
                    'fieldErrors' => [$field . '.' . $index . '.key' => ['must be a non-empty string']],
                ]);
            }

            $normalized[] = [
                'key' => $key,
                'value' => $entry['value'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @return list<array{key: string, value: mixed, id?: int}>
     */
    public static function serialize(mixed $metaData): array
    {
        if (!is_array($metaData)) {
            return [];
        }

        $result = [];
        foreach ($metaData as $entry) {
            if (is_object($entry) && method_exists($entry, 'get_data')) {
                $data = $entry->get_data();
                if (is_array($data) && isset($data['key']) && is_string($data['key']) && $data['key'] !== '') {
                    $row = [
                        'key' => $data['key'],
                        'value' => $data['value'] ?? null,
                    ];

                    if (isset($data['id']) && is_numeric($data['id'])) {
                        $row['id'] = (int) $data['id'];
                    }

                    $result[] = $row;
                }

                continue;
            }

            if (is_array($entry) && isset($entry['key']) && is_string($entry['key']) && $entry['key'] !== '') {
                $row = [
                    'key' => $entry['key'],
                    'value' => $entry['value'] ?? null,
                ];

                if (isset($entry['id']) && is_numeric($entry['id'])) {
                    $row['id'] = (int) $entry['id'];
                }

                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @param list<array{key: string, value: mixed}> $metaData
     */
    public static function applyToTarget(object $target, array $metaData): void
    {
        if (!method_exists($target, 'update_meta_data')) {
            return;
        }

        foreach ($metaData as $entry) {
            $target->update_meta_data($entry['key'], $entry['value']);
        }
    }
}
