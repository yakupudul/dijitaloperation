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
    'canonical_text',
    'folded_text',
    'language_code',
    'locale',
    'market_code',
    'resolution_status',
    'normalization_version',
    'first_seen_at',
    'last_seen_at',
])]
class IntelligenceSearchTermIdentity extends Model
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

    /** @return HasMany<IntelligenceSearchTermAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(IntelligenceSearchTermAlias::class, 'search_term_identity_id');
    }
}
