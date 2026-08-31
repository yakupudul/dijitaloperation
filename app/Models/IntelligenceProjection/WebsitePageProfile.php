<?php

namespace App\Models\IntelligenceProjection;

use App\Models\DigitalAsset;
use App\Models\IntelligenceCore\IntelligencePageIdentity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'website_asset_id',
    'page_identity_id',
    'projection_run_id',
    'preferred_url',
    'source_states',
    'coverage_state',
    'profile_version',
    'last_observed_at',
    'projected_at',
])]
final class WebsitePageProfile extends Model
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

    /** @return BelongsTo<IntelligencePageIdentity, $this> */
    public function pageIdentity(): BelongsTo
    {
        return $this->belongsTo(IntelligencePageIdentity::class, 'page_identity_id');
    }

    /** @return BelongsTo<WebsiteIntelligenceProjectionRun, $this> */
    public function projectionRun(): BelongsTo
    {
        return $this->belongsTo(WebsiteIntelligenceProjectionRun::class, 'projection_run_id');
    }
}
