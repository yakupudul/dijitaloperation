<?php

namespace App\Panel\Support;

use App\Models\User;
use App\Panel\Models\AccessRole;
use App\Support\Demo\DemoMenu;
use App\Support\Roles;

/**
 * What a MoxDOP user may open, from the central role given on Ayarlar › Kullanıcılar. An Admin opens everything; a
 * user without a role keeps the access they always had; a user with a role opens only that role's menus.
 */
final class OperatorAccess
{
    private const array SECTIONS = ['operator.dashboard' => 'today', 'operator.brands' => 'brands', 'operator.sales' => 'agency', 'operator.integrations' => 'system'];

    public static function role(User $user): ?AccessRole
    {
        if ($user->hasRole(Roles::ADMIN) || empty($user->access_role_id)) {
            return null;
        }
        $role = AccessRole::query()->find($user->access_role_id);

        return $role?->scope === PanelPermissions::SCOPE_MOXDOP ? $role : null;
    }

    public static function allowsSection(User $user, string $section): bool
    {
        if ($user->hasRole(Roles::ADMIN)) {
            return true;
        }
        if (in_array($section, ['roles', 'client_panel'], true) && empty($user->access_role_id)) {
            return false;
        }
        $role = self::role($user);

        return $role === null ? $section !== 'roles' : $role->allows($section);
    }

    public static function allows(User $user, ?string $routeName): bool
    {
        $section = self::sectionOf($routeName);

        return $section === null || self::allowsSection($user, $section);
    }

    /** The menu a screen belongs to; null for screens outside the menu (profile, notifications, share links). */
    public static function sectionOf(?string $routeName): ?string
    {
        if ($routeName === null) {
            return null;
        }
        if ($routeName === 'operator.settings.roles') {
            return 'roles';
        }
        if (str_starts_with($routeName, 'operator.settings.panel-users') || str_starts_with($routeName, 'operator.panel.')) {
            return 'client_panel';
        }
        foreach (DemoMenu::groups() as $group) {
            foreach ($group['items'] as $item) {
                $routes = [$item['route'], ...array_column($item['children'] ?? [], 'route'), ...($item['active'] ?? [])];
                foreach ($routes as $route) {
                    if ($routeName === $route || str_starts_with($routeName, $route.'.')) {
                        return self::SECTIONS[$item['route']] ?? null;
                    }
                }
            }
        }

        return null;
    }

    public static function sectionOfMenuRoute(string $route): ?string
    {
        return self::SECTIONS[$route] ?? null;
    }

    /** The first menu the user may open, for a user whose role does not include Bugün. */
    public static function home(User $user): ?string
    {
        foreach (self::SECTIONS as $route => $section) {
            if (self::allowsSection($user, $section)) {
                return $route;
            }
        }

        return null;
    }
}
