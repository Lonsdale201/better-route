<?php

declare(strict_types=1);

namespace BetterRoute\Integration\Woo;

use BetterRoute\Http\ApiException;

/** One visibility boundary shared by list, detail and mutations. Never assigns roles. */
final readonly class CustomerRolePolicy
{
    public const array PRIVILEGED_CAPABILITIES = [
        'manage_options', 'manage_woocommerce', 'edit_users', 'create_users',
        'delete_users', 'promote_users', 'manage_network',
        'install_plugins', 'update_plugins', 'edit_plugins', 'activate_plugins', 'delete_plugins',
        'install_themes', 'update_themes', 'edit_themes', 'switch_themes', 'delete_themes', 'update_core',
        'manage_network_users', 'manage_network_plugins', 'manage_network_themes', 'manage_network_options',
        'manage_sites', 'create_sites', 'delete_sites', 'setup_network',
        'edit_theme_options', 'upload_plugins', 'upload_themes', 'manage_privacy_options',
        'export_others_personal_data', 'erase_others_personal_data',
    ];

    /** @var list<string> */
    public readonly array $allowedRoles;

    /** @var list<string> */
    public readonly array $excludedRoles;

    /**
     * @param list<string> $allowedRoles
     * @param list<string> $excludedRoles
     */
    public function __construct(
        array $allowedRoles = ['customer'],
        array $excludedRoles = [],
        public readonly bool $allowPrivilegedRoles = false
    ) {
        $this->allowedRoles = $this->validateConfig($allowedRoles);
        $this->excludedRoles = $this->validateConfig($excludedRoles, true);
        if (array_diff($this->allowedRoles, $this->excludedRoles) === []) {
            throw new \InvalidArgumentException('Customer role policy must allow at least one role.');
        }
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    public function narrow(array $roles): array
    {
        if ($roles === []) {
            return array_values(array_diff($this->allowedRoles, $this->excludedRoles));
        }
        foreach ($roles as $role) {
            if (!is_string($role) || !in_array($role, $this->allowedRoles, true) || in_array($role, $this->excludedRoles, true)) {
                throw new ApiException('Invalid request.', 400, 'validation_failed', [
                    'fieldErrors' => ['role' => ['role is outside the configured allowlist']],
                ]);
            }
        }
        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     * @return array<string, mixed>
     */
    public function queryArgs(array $roles): array
    {
        $args = ['role__in' => $this->narrow($roles), 'role__not_in' => $this->excludedRoles];
        if (!$this->allowPrivilegedRoles) {
            $args['role__not_in'] = array_values(array_unique(array_merge($this->excludedRoles, $this->privilegedRoles())));
            $args['capability__not_in'] = self::PRIVILEGED_CAPABILITIES;
            if (function_exists('is_multisite') && is_multisite()) {
                $ids = [];
                foreach (get_super_admins() as $login) {
                    $user = get_user_by('login', $login);
                    if ($user !== false) {
                        $ids[] = $user->ID;
                    }
                }
                $args['exclude'] = $ids;
            }
        }
        return $args;
    }

    public function isVisible(int $id): bool
    {
        if (!function_exists('get_userdata')) {
            return false;
        }
        $user = get_userdata($id);
        if (!$user || array_intersect($user->roles, $this->allowedRoles) === [] || array_intersect($user->roles, $this->excludedRoles) !== []) {
            return false;
        }
        if (!$this->allowPrivilegedRoles) {
            if (array_intersect($user->roles, $this->privilegedRoles()) !== []) {
                return false;
            }
            if (is_super_admin($id)) {
                return false;
            }
            foreach (self::PRIVILEGED_CAPABILITIES as $capability) {
                if (array_key_exists($capability, $user->caps) || user_can($user, $capability)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @return list<string>
     */
    private function privilegedRoles(): array
    {
        $roles = ['administrator', 'shop_manager'];
        if (function_exists('wp_roles')) {
            foreach (wp_roles()->roles as $name => $definition) {
                foreach (self::PRIVILEGED_CAPABILITIES as $capability) {
                    if (!empty($definition['capabilities'][$capability])) {
                        $roles[] = $name;
                        break;
                    }
                }
            }
        }
        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     * @return list<string>
     */
    private function validateConfig(array $roles, bool $allowEmpty = false): array
    {
        if ((!$allowEmpty && $roles === []) || !array_is_list($roles)) {
            throw new \InvalidArgumentException('Customer roles must be a non-empty list of role slugs.');
        }
        foreach ($roles as $role) {
            if (!is_string($role) || preg_match('/^[a-zA-Z0-9_-]+$/D', $role) !== 1) {
                throw new \InvalidArgumentException('Invalid customer role slug.');
            }
        }
        return array_values(array_unique($roles));
    }
}
