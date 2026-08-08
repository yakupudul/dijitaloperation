<?php

namespace App\Models;

use App\Enums\DigitalAssetStatus;
use Database\Factories\DigitalAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'brand_id',
    'name',
    'type',
    'status',
    'module_id',
    'domain',
    'primary_url',
    'cms',
    'languages',
    'target_countries',
    'site_type',
    'hosting_context',
])]
class DigitalAsset extends Model
{
    /** @use HasFactory<DigitalAssetFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return HasMany<CoreConnection, $this>
     */
    public function connections(): HasMany
    {
        return $this->hasMany(CoreConnection::class);
    }

    /**
     * Provider External Resource bindings (agency Integration credentials are not stored here).
     *
     * @return HasMany<CoreAssetBinding, $this>
     */
    public function assetBindings(): HasMany
    {
        return $this->hasMany(CoreAssetBinding::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DigitalAssetStatus::class,
            'languages' => 'array',
            'target_countries' => 'array',
        ];
    }
}
