<?php

namespace App\Models;

use App\Enums\DigitalAssetStatus;
use Database\Factories\DigitalAssetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'brand_id',
    'name',
    'type',
    'status',
    'module_id',
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
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DigitalAssetStatus::class,
        ];
    }
}
