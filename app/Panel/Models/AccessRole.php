<?php

namespace App\Panel\Models;

use App\Panel\Support\PanelPermissions;
use Illuminate\Database\Eloquent\Model;

/** A central role: a named set of permissions for MoxDOP users (scope moxdop) or for a brand's panel users (scope panel). */
class AccessRole extends Model
{
    protected $fillable = ['scope', 'name', 'description', 'permissions', 'is_system', 'seen_keys'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean', 'seen_keys' => 'array'];
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, (array) $this->permissions, true);
    }

    /**
     * The starting roles exist once; an admin's later edits are kept. A permission that joined the catalog after a
     * starting role was made is added to it once (the role's "seen" list remembers which keys it already met), so
     * a key the admin took away is not put back.
     */
    public static function ensureDefaults(): void
    {
        foreach (PanelPermissions::defaults() as $role) {
            $keys = PanelPermissions::keys($role['scope']);
            $row = self::query()->firstOrCreate(
                ['scope' => $role['scope'], 'name' => $role['name']],
                ['description' => $role['description'], 'permissions' => $role['permissions'], 'is_system' => true, 'seen_keys' => $keys],
            );
            if ($row->wasRecentlyCreated || ! $row->is_system) {
                continue;
            }
            $seen = $row->seen_keys ?? PanelPermissions::FIRST_KEYS[$role['scope']];
            $fresh = array_values(array_diff($keys, $seen));
            if ($fresh !== []) {
                $row->forceFill([
                    'permissions' => array_values(array_unique([...(array) $row->permissions, ...array_intersect($role['permissions'], $fresh)])),
                    'seen_keys' => $keys,
                ])->save();
            }
        }
    }
}
