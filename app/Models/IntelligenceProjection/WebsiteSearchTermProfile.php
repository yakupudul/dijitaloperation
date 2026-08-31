<?php

namespace App\Models\IntelligenceProjection;

use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligenceSearchTermIdentity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'website_asset_id',
    'search_term_identity_id',
    'projection_run_id',
    'canonical_text',
    'source_states',
    'coverage_state',
    'profile_version',
    'last_observed_at',
    'projected_at',
])]
final class WebsiteSearchTermProfile extends Model
{
    protected function casts(): array
    {
        return [
            'source_states' => 'array',
            'coverage_state' => 'array',
            'profile_version' => 'integer',
            'last_observed_at' => 'immutable_datetime',
            'projected_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function websiteAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }

    /** @return BelongsTo<IntelligenceSearchTermIdentity, $this> */
    public function searchTermIdentity(): BelongsTo
    {
        return $this->belongsTo(IntelligenceSearchTermIdentity::class, 'search_term_identity_id');
    }
}
