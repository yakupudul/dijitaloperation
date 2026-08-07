<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ModuleRegistry extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'module_id',
        'enabled',
        'installed_version',
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeDisabled(Builder $query): Builder
    {
        return $query->where('enabled', false);
    }

    public static function isEnabled(string $moduleId): bool
    {
        return static::query()
            ->where('module_id', $moduleId)
            ->enabled()
            ->exists();
    }
}
