<?php

namespace App\Models\IntelligenceCore;

use App\Enums\IntelligenceCore\IdentityResolutionStatus;
use App\Models\DigitalAsset;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid',
    'website_asset_id',
    'identity_hash',
    'preferred_url',
    'preferred_url_hash',
    'scheme',
    'host',
    'path',
    'resolution_status',
    'normalization_version',
    'first_seen_at',
    'last_seen_at',
])]
class IntelligencePageIdentity extends Model
{
    protected function casts(): array
    {
        return [
            'resolution_status' => IdentityResolutionStatus::class,
            'first_seen_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function websiteAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }

    /** @return HasMany<IntelligencePageAlias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(IntelligencePageAlias::class, 'page_identity_id');
    }
}
