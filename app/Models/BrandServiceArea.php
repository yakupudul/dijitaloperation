<?php

namespace App\Models;

use App\Jobs\Brand\RefreshBrandFilesJob;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_id',
    'name',
    'physical_branch',
    'country_code',
    'country_name',
    'city_name',
    'district_name',
    'normalized_key',
    'status',
    'priority_rank',
    'lat',
    'lng',
    'geocode_status',
    'geocoded_at',
])]
class BrandServiceArea extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (BrandServiceArea $area) => RefreshBrandFilesJob::soon((int) $area->brand_id));
        static::deleted(fn (BrandServiceArea $area) => RefreshBrandFilesJob::soon((int) $area->brand_id));
    }

    protected function casts(): array
    {
        return ['physical_branch' => 'boolean', 'priority_rank' => 'integer', 'lat' => 'float', 'lng' => 'float', 'geocoded_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** Operator name of the area ("Çankaya şubesi"), else its place label. */
    public function displayName(): string
    {
        return filled($this->name) ? (string) $this->name : $this->label();
    }

    public function label(): string
    {
        return collect([$this->district_name, $this->city_name, $this->country_name ?: $this->country_code])
            ->filter()
            ->implode(', ');
    }
}
