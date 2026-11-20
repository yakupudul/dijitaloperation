<?php

namespace App\Panel\Models;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A panel user's place in one brand: the role there, and whether they are the brand's manager. */
class PanelMembership extends Model
{
    protected $fillable = ['panel_user_id', 'brand_id', 'access_role_id', 'is_manager', 'is_active', 'preferences'];

    protected function casts(): array
    {
        return ['is_manager' => 'boolean', 'is_active' => 'boolean', 'preferences' => 'array'];
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

    /** A manager may do everything in their brand; anyone else what their role names. No role: nothing. */
    public function allows(string $permission): bool
    {
        return $this->is_manager || (bool) $this->role?->allows($permission);
    }

    public function preference(string $key, mixed $default = null): mixed
    {
        return data_get($this->preferences, $key, $default);
    }
}
