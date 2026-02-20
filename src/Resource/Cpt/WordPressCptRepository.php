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

    /**
     * @param array<string, mixed> $args
     */
    private function applyFilter(array &$args, string $filter, mixed $value): void
    {
        if ($filter === 'status') {
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
}
