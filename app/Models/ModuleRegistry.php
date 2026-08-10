<?php

namespace App\Models;

use App\Support\Modules\ModuleCatalog;
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

    /**
     * Operator-facing Module Registry rows (excludes developer fixtures such as sample-module).
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeOperatorVisible(Builder $query): Builder
    {
        $fixtures = ModuleCatalog::DEVELOPER_FIXTURE_MODULE_IDS;

        if ($fixtures === []) {
            return $query;
        }

        return $query->whereNotIn('module_id', $fixtures);
    }

    public static function isEnabled(string $moduleId): bool
    {
        return static::query()
            ->where('module_id', $moduleId)
            ->enabled()
            ->exists();
    }
}
