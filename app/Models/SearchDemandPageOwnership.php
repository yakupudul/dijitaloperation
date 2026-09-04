<?php

namespace App\Models;

use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'brand_id', 'digital_asset_id', 'search_demand_cluster_id', 'website_page_profile_id',
    'page_identity_id', 'target_url', 'status', 'decision_source', 'is_locked', 'rationale',
    'evidence_snapshot', 'version', 'verified_by', 'verified_at', 'updated_by',
])]
class SearchDemandPageOwnership extends Model
{
    protected function casts(): array
    {
        return [
            'is_locked' => 'boolean',
            'evidence_snapshot' => 'array',
            'version' => 'integer',
            'verified_at' => 'immutable_datetime',
        ];
    }

    public function brand(): BelongsTo { return $this->belongsTo(Brand::class); }
    public function website(): BelongsTo { return $this->belongsTo(DigitalAsset::class, 'digital_asset_id'); }
    public function cluster(): BelongsTo { return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id'); }
    public function pageProfile(): BelongsTo { return $this->belongsTo(WebsitePageProfile::class, 'website_page_profile_id'); }
    public function pageIdentity(): BelongsTo { return $this->belongsTo(IntelligencePageIdentity::class, 'page_identity_id'); }
    public function versions(): HasMany { return $this->hasMany(SearchDemandPageOwnershipVersion::class); }
}
