<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_id',
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
    protected function casts(): array
    {
        return ['priority_rank' => 'integer', 'lat' => 'float', 'lng' => 'float', 'geocoded_at' => 'datetime'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function label(): string
    {
        return collect([$this->district_name, $this->city_name, $this->country_name ?: $this->country_code])
            ->filter()
            ->implode(', ');
    }
}
