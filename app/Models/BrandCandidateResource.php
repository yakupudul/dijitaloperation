<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One discovered account (or brandless website) inside one brand candidate. A subject is in at most one candidate. */
class BrandCandidateResource extends Model
{
    protected $fillable = ['brand_candidate_id', 'external_resource_id', 'website_asset_id', 'reason'];

    /** @return BelongsTo<BrandCandidate, $this> */
    public function candidate(): BelongsTo
    {
        return $this->belongsTo(BrandCandidate::class, 'brand_candidate_id');
    }

    /** @return BelongsTo<CoreExternalResource, $this> */
    public function resource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class, 'external_resource_id');
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function website(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class, 'website_asset_id');
    }
}
