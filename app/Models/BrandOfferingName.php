<?php

namespace App\Models;

use App\Enums\OfferingNameKind;
use App\Enums\OfferingNameProvenance;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandOfferingName extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'brand_offering_id',
        'raw_label',
        'normalized_key',
        'locale',
        'name_kind',
        'is_primary',
        'is_active',
        'provenance',
        'normalization_version',
    ];

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<BrandOffering, $this>
     */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'name_kind' => OfferingNameKind::class,
            'provenance' => OfferingNameProvenance::class,
            'is_primary' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
