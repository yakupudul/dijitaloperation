<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'run_id', 'search_demand_cluster_id', 'search_demand_competitor_id',
    'search_demand_competitor_url_id', 'requested_url', 'normalized_url_hash',
    'selection_order', 'best_observed_rank', 'status', 'error_summary', 'started_at', 'finished_at',
])]
class SearchDemandCompetitorPageRunItem extends Model
{
    protected function casts(): array
    {
        return [
            'selection_order' => 'integer',
            'best_observed_rank' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    public function cluster(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCluster::class, 'search_demand_cluster_id');
    }

    public function competitor(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCompetitor::class, 'search_demand_competitor_id');
    }

    public function competitorUrl(): BelongsTo
    {
        return $this->belongsTo(SearchDemandCompetitorUrl::class, 'search_demand_competitor_url_id');
    }

    public function observation(): HasOne
    {
        return $this->hasOne(SearchDemandCompetitorPageObservation::class, 'run_item_id');
    }
}
