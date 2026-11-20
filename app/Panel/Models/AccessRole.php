<?php

namespace App\Panel\Models;

use App\Panel\Support\PanelPermissions;
use Illuminate\Database\Eloquent\Model;

/** A central role: a named set of permissions for MoxDOP users (scope moxdop) or for a brand's panel users (scope panel). */
class AccessRole extends Model
{
    protected $fillable = ['scope', 'name', 'description', 'permissions', 'is_system'];

    protected function casts(): array
    {
        return ['permissions' => 'array', 'is_system' => 'boolean'];
    }

    public function allows(string $permission): bool
    {
        return in_array($permission, (array) $this->permissions, true);
    }

    /** The starting roles exist once; an admin's later edits are kept. */
    public static function ensureDefaults(): void
    {
        foreach (PanelPermissions::defaults() as $role) {
            self::query()->firstOrCreate(
                ['scope' => $role['scope'], 'name' => $role['name']],
                ['description' => $role['description'], 'permissions' => $role['permissions'], 'is_system' => true],
            );
        }
    }
}
