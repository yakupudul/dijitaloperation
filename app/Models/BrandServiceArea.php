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
])]
class BrandServiceArea extends Model
{
    protected function casts(): array
    {
        return ['priority_rank' => 'integer'];
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
