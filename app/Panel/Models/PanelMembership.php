<?php

namespace App\Panel\Models;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A panel user's place in one brand: the role there, and whether they are the brand's manager. */
class PanelMembership extends Model
{
    protected $fillable = ['panel_user_id', 'brand_id', 'access_role_id', 'is_manager', 'is_active', 'preferences', 'overrides'];

    protected function casts(): array
    {
        return ['is_manager' => 'boolean', 'is_active' => 'boolean', 'preferences' => 'array', 'overrides' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'panel_user_id');
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(AccessRole::class, 'access_role_id');
    }

    /**
     * A manager may do everything in their brand; anyone else what their role names, changed by what the manager
     * opened or closed for this user alone. No role: nothing but what was opened by hand.
     */
    public function allows(string $permission): bool
    {
        if ($this->is_manager) {
            return true;
        }
        if (in_array($permission, (array) data_get($this->overrides, 'deny', []), true)) {
            return false;
        }

        return in_array($permission, (array) data_get($this->overrides, 'allow', []), true) || (bool) $this->role?->allows($permission);
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }
}
