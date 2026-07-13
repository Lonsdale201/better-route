<?php

declare(strict_types=1);

namespace BetterRoute\Resource\Cpt;

use BetterRoute\Http\ApiException;
use RuntimeException;

final class WordPressCptRepository implements CptDeleteModeRepositoryInterface
{
    public function list(string $postType, CptListQuery $query): array
    {
        if (!class_exists('WP_Query')) {
            throw new RuntimeException('WP_Query is unavailable.');
        }

        $args = [
            'post_type' => $postType,
            'post_status' => 'publish',
            'has_password' => false,
            'posts_per_page' => $query->perPage,
            'paged' => $query->page,
            'no_found_rows' => false,
        ];

        foreach ($query->filters as $filter => $value) {
            $this->applyFilter($args, $filter, $value);
        }

        $direction = strtoupper($query->sortDirection) === 'ASC' ? 'ASC' : 'DESC';
        $sortField = $query->sortField !== null ? $this->mapSortField($query->sortField) : 'date';
        $args['orderby'] = [$sortField => $direction, 'ID' => $direction];

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
        if (!($post instanceof \WP_Post) || $post->post_type !== $postType) {
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
        $this->assertCanCreate($postType, $postData);
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
        $this->assertCanUpdate($postType, $id, $postData);
        $result = wp_update_post($postData, true);
        if ($this->isWpError($result)) {
            throw new RuntimeException((string) $result->get_error_message());
        }

        return $this->get($postType, $id, $fields);
    }

    public function delete(string $postType, int $id): bool
    {
        return $this->deleteWithMode($postType, $id, 'force');
    }

    public function deleteWithMode(string $postType, int $id, string $mode): bool
    {
        if (!function_exists('wp_delete_post')) {
            throw new RuntimeException('wp_delete_post is unavailable.');
        }

        $post = function_exists('get_post') ? get_post($id) : null;
        if (!($post instanceof \WP_Post) || $post->post_type !== $postType) {
            return false;
        }
        $this->assertCanDelete($id);

        if ($mode === 'trash') {
            if (!function_exists('wp_trash_post')) {
                throw new RuntimeException('wp_trash_post is unavailable.');
            }

            // Returns WP_Post|false|null — treat null (nothing deleted) as failure.
            return (bool) wp_trash_post($id);
        }

        return (bool) wp_delete_post($id, true);
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

        $reserved = [
            'post_type', 'posts_per_page', 'paged', 'no_found_rows', 'fields',
            'orderby', 'order', 'has_password', 'suppress_filters', 'perm',
        ];
        if (in_array($filter, $reserved, true)) {
            throw new \InvalidArgumentException(sprintf('CPT filter "%s" is reserved.', $filter));
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
            'content' => (string) ($post->post_content ?? ''),
            'date' => (string) ($post->post_date_gmt ?? ''),
            'status' => (string) ($post->post_status ?? ''),
            'author' => (int) ($post->post_author ?? 0),
            'password_protected' => (string) ($post->post_password ?? '') !== '',
            'publicly_queryable' => $this->isPostTypePubliclyViewable((string) ($post->post_type ?? '')),
            'can_read' => function_exists('current_user_can')
                ? (bool) current_user_can('read_post', (int) ($post->ID ?? 0))
                : false,
            default => isset($post->$field) ? $post->$field : null,
        };
    }

    private function isPostTypePubliclyViewable(string $postType): bool
    {
        if (!function_exists('get_post_type_object')) {
            return true;
        }

        $object = get_post_type_object($postType);
        if (!is_object($object)) {
            return false;
        }

        if (function_exists('is_post_type_viewable')) {
            return (bool) is_post_type_viewable($object);
        }

        return $object->publicly_queryable === true;
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

    /**
     * @param array<string, mixed> $postData
     */
    private function assertCanCreate(string $postType, array $postData): void
    {
        if (!function_exists('current_user_can')) {
            return;
        }

        $caps = $this->postTypeCapabilities($postType);
        if (!current_user_can($caps['edit_posts'])) {
            throw new ApiException('Forbidden.', 403, 'forbidden');
        }

        if ($this->requiresPublishCapability($postData) && !current_user_can($caps['publish_posts'])) {
            throw new ApiException('Forbidden.', 403, 'forbidden');
        }

        $author = $postData['post_author'] ?? null;
        if (is_int($author) && $author > 0 && function_exists('get_current_user_id')) {
            $currentUserId = (int) get_current_user_id();
            if ($currentUserId > 0 && $author !== $currentUserId && !current_user_can($caps['edit_others_posts'])) {
                throw new ApiException('Forbidden.', 403, 'forbidden');
            }
        }
    }

    /**
     * @param array<string, mixed> $postData
     */
    private function assertCanUpdate(string $postType, int $id, array $postData): void
    {
        if (!function_exists('current_user_can')) {
            return;
        }

        if (!current_user_can('edit_post', $id)) {
            throw new ApiException('Forbidden.', 403, 'forbidden');
        }

        $caps = $this->postTypeCapabilities($postType);
        if ($this->requiresPublishCapability($postData) && !current_user_can($caps['publish_posts'])) {
            throw new ApiException('Forbidden.', 403, 'forbidden');
        }

        $author = $postData['post_author'] ?? null;
        if (is_int($author) && $author > 0 && function_exists('get_current_user_id')) {
            $currentUserId = (int) get_current_user_id();
            if ($currentUserId > 0 && $author !== $currentUserId && !current_user_can($caps['edit_others_posts'])) {
                throw new ApiException('Forbidden.', 403, 'forbidden');
            }
        }
    }

    private function assertCanDelete(int $id): void
    {
        if (function_exists('current_user_can') && !current_user_can('delete_post', $id)) {
            throw new ApiException('Forbidden.', 403, 'forbidden');
        }
    }

    /**
     * @param array<string, mixed> $postData
     */
    private function requiresPublishCapability(array $postData): bool
    {
        $status = (string) ($postData['post_status'] ?? '');
        return in_array($status, ['publish', 'private', 'future'], true);
    }

    /**
     * @return array{edit_posts: string, publish_posts: string, edit_others_posts: string}
     */
    private function postTypeCapabilities(string $postType): array
    {
        $defaults = [
            'edit_posts' => 'edit_posts',
            'publish_posts' => 'publish_posts',
            'edit_others_posts' => 'edit_others_posts',
        ];

        if (!function_exists('get_post_type_object')) {
            return $defaults;
        }

        $object = get_post_type_object($postType);
        $cap = is_object($object) ? $object->cap : null;
        if (!is_object($cap)) {
            return $defaults;
        }

        foreach ($defaults as $key => $default) {
            if (isset($cap->{$key}) && is_string($cap->{$key}) && $cap->{$key} !== '') {
                $defaults[$key] = $cap->{$key};
            }
        }

        return $defaults;
    }

    private function isWpError(mixed $value): bool
    {
        return class_exists('WP_Error') && $value instanceof \WP_Error;
    }
}
