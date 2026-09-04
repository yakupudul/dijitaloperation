<?php

namespace App\Models;

use App\Models\Collection\CollectionRun;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'uuid', 'brand_id', 'digital_asset_id', 'search_demand_cluster_id',
    'search_demand_improvement_proposal_id', 'finding_id', 'recommendation_id', 'task_id',
    'change_summary', 'affected_urls', 'affected_cluster_ids', 'baseline_html_fingerprints',
    'latest_html_fingerprints', 'verification_urls', 'applied_at', 'review_after_at',
    'targeted_collection_run_id', 'status', 'result_status', 'component_results',
    'metric_comparison', 'technical_result', 'semantic_result', 'recorded_by',
    'reviewed_by', 'reviewed_at', 'review_note',
])]
final class SearchDemandChangeTracking extends Model
{
    protected function casts(): array
    {
        return [
            'affected_urls' => 'array', 'affected_cluster_ids' => 'array',
            'baseline_html_fingerprints' => 'array', 'latest_html_fingerprints' => 'array',
            'verification_urls' => 'array', 'component_results' => 'array',
            'metric_comparison' => 'array', 'technical_result' => 'array',
            'semantic_result' => 'array', 'applied_at' => 'immutable_datetime',
            'review_after_at' => 'immutable_datetime', 'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function website(): BelongsTo { return $this->belongsTo(DigitalAsset::class, 'digital_asset_id'); }
    public function cluster(): BelongsTo { return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id'); }
    public function proposal(): BelongsTo { return $this->belongsTo(SearchDemandImprovementProposal::class, 'search_demand_improvement_proposal_id'); }
    public function finding(): BelongsTo { return $this->belongsTo(Finding::class); }
    public function recommendation(): BelongsTo { return $this->belongsTo(Recommendation::class); }
    public function task(): BelongsTo { return $this->belongsTo(Task::class); }
    public function collectionRun(): BelongsTo { return $this->belongsTo(CollectionRun::class, 'targeted_collection_run_id'); }
    public function runs(): HasMany { return $this->hasMany(SearchDemandChangeVerificationRun::class); }
    public function recorder(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
