<?php

declare(strict_types=1);

namespace BetterRouteOwnershipSmoke;

use BetterRoute\Resource\OwnedResourcePolicy;
use BetterRoute\Resource\Resource;
use BetterRoute\Resource\Table\TableListQuery;
use BetterRoute\Resource\Table\TableRepositoryInterface;
use RuntimeException;

if (!defined('WP_CLI') || !WP_CLI) {
    throw new RuntimeException('Run only through WP-CLI against disposable WordPress.');
}
require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

final class FixtureRepository implements TableRepositoryInterface
{
    /** @var list<int> */
    public array $effects = [];

    public function list(string $table, string $primaryKey, TableListQuery $query): array
    {
        return ['items' => [], 'total' => 0, 'page' => 1, 'perPage' => 20];
    }

    public function get(string $table, string $primaryKey, int $id, array $fields): ?array
    {
        $this->effects[] = $id;
        return ['id' => $id, 'title' => 'fixture'];
    }

    public function create(string $table, string $primaryKey, array $payload, array $fields): array
    {
        return ['id' => 11, 'title' => 'fixture'];
    }

    public function update(string $table, string $primaryKey, int $id, array $payload, array $fields): ?array
    {
        $this->effects[] = $id;
        return ['id' => $id, 'title' => 'fixture'];
    }

    public function delete(string $table, string $primaryKey, int $id): bool
    {
        $this->effects[] = $id;
        return true;
    }
}

$count = 0;
$check = static function (bool $condition, string $message) use (&$count): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
    ++$count;
};
$originalUser = get_current_user_id();
$userId = 0;
$suffix = bin2hex(random_bytes(5));
$mailGuard = static fn (): bool => true;
$httpGuard = static fn (): \WP_Error => new \WP_Error('fixture_no_egress', 'Fixture blocks HTTP.');
add_filter('pre_wp_mail', $mailGuard, PHP_INT_MAX);
add_filter('pre_http_request', $httpGuard, PHP_INT_MAX);
try {
    $created = wp_create_user('br_owner_' . $suffix, wp_generate_password(40, true, true), 'br_owner_' . $suffix . '@example.invalid');
    if (is_wp_error($created)) {
        throw new RuntimeException('Could not create fixture user.');
    }
    $userId = (int) $created;
    wp_set_current_user($userId);
    rest_get_server();
    $repository = new FixtureRepository();
    $checked = [];
    $namespace = 'br-ownership-' . $suffix . '/v1';
    Resource::make('records')->restNamespace($namespace)->sourceTable('br_ownership_fixture', 'id')
        ->allow(['get', 'update', 'delete'])->fields(['id', 'title'])
        ->policy(OwnedResourcePolicy::currentUserOwns(
            static function (int $id) use ($userId, &$checked): int {
                $checked[] = $id;
                return $id === 11 ? $userId : $userId + 1000;
            },
            bypassCapability: null
        ))->usingTableRepository($repository)->register();

    foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
        foreach (['query', 'form', 'json'] as $source) {
            $repository->effects = $checked = [];
            $request = new \WP_REST_Request($method, '/' . $namespace . '/records/22');
            if ($source === 'query') {
                $request->set_query_params(['id' => 11]);
            } elseif ($source === 'form') {
                $request->set_body_params(['id' => 11, 'title' => 'changed']);
            } else {
                $request->set_header('content-type', 'application/json');
                $request->set_body((string) json_encode(['id' => 11, 'title' => 'changed']));
            }
            $response = rest_do_request($request);
            $check($response->get_status() === 403, $method . '/' . $source . ': foreign URL must be denied');
            $check($checked === [22], 'Ownership must check the URL capture');
            $check($repository->effects === [], 'Denied request reached the repository');
        }

        $repository->effects = $checked = [];
        $request = new \WP_REST_Request($method, '/' . $namespace . '/records/11');
        $request->set_query_params(['id' => 22]);
        if ($method === 'PUT' || $method === 'PATCH') {
            $request->set_header('content-type', 'application/json');
            $request->set_body('{"title":"changed"}');
        }
        $response = rest_do_request($request);
        $check($response->get_status() === 200, $method . ': owned URL must remain accessible');
        $check($checked === [11], 'Owned request must authorize its URL');
        $check($repository->effects === [11], 'Owned handler must use the same URL ID');
    }
} finally {
    wp_set_current_user($originalUser);
    if ($userId > 0) {
        wp_delete_user($userId);
    }
    remove_filter('pre_wp_mail', $mailGuard, PHP_INT_MAX);
    remove_filter('pre_http_request', $httpGuard, PHP_INT_MAX);
}
\WP_CLI::success('Ownership smoke: ' . $count . ' assertions; fixture user removed.');
