<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Cpt;

use RuntimeException;

final class WordPressCptRepository implements CptRepositoryInterface
{
    public function list(string $postType, CptListQuery $query): array
    {
        if (!class_exists('WP_Query')) {
            throw new RuntimeException('WP_Query is unavailable.');
        }

        $args = [
            'post_type' => $postType,
            'post_status' => 'publish',
            'posts_per_page' => $query->perPage,
            'paged' => $query->page,
            'no_found_rows' => false,
        ];

        foreach ($query->filters as $filter => $value) {
            $this->applyFilter($args, $filter, $value);
        }

        if ($query->sortField !== null) {
            $args['orderby'] = $this->mapSortField($query->sortField);
            $args['order'] = $query->sortDirection;
        }

        $wpQuery = new \WP_Query($args);
        $items = [];

        foreach ($wpQuery->posts as $post) {
            if (!is_object($post)) {
                continue;
            }
            $items[] = $this->projectPost($post, $query->fields);
        }

        return [
            'items' => $items,
            'total' => (int) $wpQuery->found_posts,
            'page' => $query->page,
            'perPage' => $query->perPage,
        ];
    }

    public function get(string $postType, int $id, array $fields): ?array
    {
        if (!function_exists('get_post')) {
            throw new RuntimeException('get_post is unavailable.');
        }

        $post = get_post($id);
        if (!is_object($post) || !isset($post->post_type) || $post->post_type !== $postType) {
            return null;
        }

        return $this->projectPost($post, $fields);
    }

    public function create(string $postType, array $payload, array $fields): array
    {
        if (!function_exists('wp_insert_post')) {
            throw new RuntimeException('wp_insert_post is unavailable.');
        }

        $postData = $this->mapPayloadToPostArray($postType, $payload);
        $result = wp_insert_post($postData, true);
        if ($this->isWpError($result)) {
            throw new RuntimeException((string) $result->get_error_message());
        }

        $id = (int) $result;
        $row = $this->get($postType, $id, $fields);

        return $row ?? ['id' => $id];
    }

    public function update(string $postType, int $id, array $payload, array $fields): ?array
    {
        if (!function_exists('wp_update_post')) {
            throw new RuntimeException('wp_update_post is unavailable.');
        }

        $existing = $this->get($postType, $id, $fields);
        if ($existing === null) {
            return null;
        }

        $postData = $this->mapPayloadToPostArray($postType, $payload);
        $postData['ID'] = $id;
        $result = wp_update_post($postData, true);
        if ($this->isWpError($result)) {
            throw new RuntimeException((string) $result->get_error_message());
        }

        return $this->get($postType, $id, $fields);
    }

    public function delete(string $postType, int $id): bool
    {
        if (!function_exists('wp_delete_post')) {
            throw new RuntimeException('wp_delete_post is unavailable.');
        }

        $post = function_exists('get_post') ? get_post($id) : null;
        if (!is_object($post) || !isset($post->post_type) || $post->post_type !== $postType) {
            return false;
        }

        return wp_delete_post($id, true) !== false;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function applyFilter(array &$args, string $filter, mixed $value): void
    {
        if ($filter === 'status') {
            if (is_array($value)) {
                $statuses = [];
                foreach ($value as $status) {
                    if (is_string($status) && $status !== '') {
                        $statuses[] = $status;
                    }
                }

                $args['post_status'] = $statuses !== [] ? $statuses : ['publish'];
                return;
            }

            $args['post_status'] = is_string($value) ? $value : 'publish';
            return;
        }

        if ($filter === 'author') {
            $args['author'] = is_numeric($value) ? (int) $value : 0;
            return;
        }

        if ($filter === 'after' || $filter === 'before') {
            if (!isset($args['date_query']) || !is_array($args['date_query'])) {
                $args['date_query'] = [];
            }
            $args['date_query'][$filter] = (string) $value;
            $args['date_query']['inclusive'] = true;
            return;
        }

        $args[$filter] = $value;
    }

    private function mapSortField(string $field): string
    {
        return match ($field) {
            'id' => 'ID',
            'slug' => 'name',
            default => $field,
        };
    }

    /**
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private function projectPost(object $post, array $fields): array
    {
        $row = [];
        foreach ($fields as $field) {
            $row[$field] = $this->mapField($post, $field);
        }

        return $row;
    }

    private function mapField(object $post, string $field): mixed
    {
        return match ($field) {
            'id' => (int) ($post->ID ?? 0),
            'title' => (string) ($post->post_title ?? ''),
            'slug' => (string) ($post->post_name ?? ''),
            'excerpt' => (string) ($post->post_excerpt ?? ''),
            'date' => (string) ($post->post_date_gmt ?? ''),
            'status' => (string) ($post->post_status ?? ''),
            'author' => (int) ($post->post_author ?? 0),
            default => isset($post->$field) ? $post->$field : null,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function mapPayloadToPostArray(string $postType, array $payload): array
    {
        $data = [
            'post_type' => $postType,
        ];

        foreach ($payload as $key => $value) {
            switch ($key) {
                case 'title':
                    $data['post_title'] = (string) $value;
                    break;
                case 'slug':
                    $data['post_name'] = (string) $value;
                    break;
                case 'excerpt':
                    $data['post_excerpt'] = (string) $value;
                    break;
                case 'content':
                    $data['post_content'] = (string) $value;
                    break;
                case 'status':
                    $data['post_status'] = (string) $value;
                    break;
                case 'date':
                    $data['post_date_gmt'] = (string) $value;
                    break;
                case 'author':
                    $data['post_author'] = is_numeric($value) ? (int) $value : 0;
                    break;
            }
        }

        if (!isset($data['post_status'])) {
            $data['post_status'] = 'publish';
        }

        return $data;
    }

    private function isWpError(mixed $value): bool
    {
        return class_exists('WP_Error') && $value instanceof \WP_Error;
    }
}
