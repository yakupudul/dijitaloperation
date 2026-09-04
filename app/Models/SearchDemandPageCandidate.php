<?php

namespace App\Models;

use App\Models\IntelligenceCore\IntelligencePageIdentity;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_page_relevance_run_id', 'website_page_profile_id', 'page_identity_id', 'url',
    'url_key_hash', 'candidate_sources', 'technical_eligibility', 'technical_gate', 'matched_terms',
    'gsc_clicks', 'gsc_impressions', 'gsc_impression_share', 'comparison_impressions',
    'comparison_impression_share', 'serp_supporting_queries', 'serp_observed_queries', 'semantic_fit',
    'semantic_confidence', 'semantic_rationale', 'supported_query_ids', 'ai_recommended',
    'review_status', 'reviewed_by', 'reviewed_at',
])]
class SearchDemandPageCandidate extends Model
{
    protected function casts(): array
    {
        return [
            'candidate_sources' => 'array', 'technical_gate' => 'array', 'matched_terms' => 'array',
            'gsc_clicks' => 'integer', 'gsc_impressions' => 'integer',
            'gsc_impression_share' => 'float', 'comparison_impressions' => 'integer',
            'comparison_impression_share' => 'float', 'serp_supporting_queries' => 'integer',
            'serp_observed_queries' => 'integer', 'semantic_confidence' => 'integer',
            'supported_query_ids' => 'array', 'ai_recommended' => 'boolean',
            'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandPageRelevanceRun::class, 'search_demand_page_relevance_run_id'); }
    public function pageProfile(): BelongsTo { return $this->belongsTo(WebsitePageProfile::class, 'website_page_profile_id'); }
    public function pageIdentity(): BelongsTo { return $this->belongsTo(IntelligencePageIdentity::class, 'page_identity_id'); }
}
