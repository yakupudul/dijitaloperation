<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_enrichment_run_id', 'provider', 'endpoint', 'request_fingerprint',
    'request_payload', 'response_payload', 'reported_cost_usd', 'captured_at',
])]
class SearchDemandProviderPayload extends Model
{
    protected function casts(): array
    {
        return ['request_payload' => 'array', 'response_payload' => 'array', 'reported_cost_usd' => 'decimal:6', 'captured_at' => 'immutable_datetime'];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandEnrichmentRun::class, 'search_demand_enrichment_run_id'); }
}
