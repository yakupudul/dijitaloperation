<?php

namespace App\Panel\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;

/** A person who signs in to the client panel. Never an operator: a separate table and a separate guard. */
class PanelUser extends Authenticatable
{
    protected $fillable = ['name', 'email', 'phone', 'password', 'is_active', 'must_change_password', 'locale', 'last_login_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_active' => 'boolean', 'must_change_password' => 'boolean', 'last_login_at' => 'datetime'];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(PanelMembership::class);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr(count($parts) > 1 ? (string) end($parts) : '', 0, 1));
    }
}
