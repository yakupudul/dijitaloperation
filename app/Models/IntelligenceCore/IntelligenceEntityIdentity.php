<?php

namespace App\Models\IntelligenceCore;

use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Models\Brand;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'brand_id',
    'identity_hash',
    'entity_type',
    'canonical_name',
    'normalized_name',
    'country_code',
    'language_code',
    'resolution_status',
    'normalization_version',
    'first_seen_at',
    'last_seen_at',
])]
class IntelligenceEntityIdentity extends Model
{
    protected function casts(): array
    {
        return [
            'resolution_status' => IdentityResolutionStatus::class,
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<IntelligenceEntityAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(IntelligenceEntityAlias::class, 'entity_identity_id');
    }
}
