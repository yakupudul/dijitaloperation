<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_ai_run_id',
    'source_search_query_library_item_id',
    'service_catalog_item_id',
    'candidate_fingerprint',
    'original_text',
    'proposed_text',
    'service_alias',
    'demand_family',
    'search_intent',
    'user_problem',
    'decision_stage',
    'serp_intent_group',
    'content_target_cluster',
    'location_scope',
    'location_value',
    'is_branded_suspected',
    'confidence',
    'abstained',
    'abstention_reason',
    'rationale',
    'status',
    'reviewed_by',
    'reviewed_at',
    'applied_item_id',
    'raw_output',
])]
class SearchDemandAiCandidate extends Model
{
    protected function casts(): array
    {
        return [
            'is_branded_suspected' => 'boolean',
            'abstained' => 'boolean',
            'confidence' => 'integer',
            'reviewed_at' => 'immutable_datetime',
            'raw_output' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(SearchDemandAiRun::class, 'search_demand_ai_run_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(SearchQueryLibraryItem::class, 'source_search_query_library_item_id');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(ServiceCatalogItem::class, 'service_catalog_item_id');
    }

    public function appliedItem(): BelongsTo
    {
        return $this->belongsTo(SearchQueryLibraryItem::class, 'applied_item_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
