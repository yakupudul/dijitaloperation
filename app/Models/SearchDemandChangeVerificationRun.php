<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'uuid', 'run_id', 'search_demand_change_tracking_id', 'status', 'input_payload',
    'response_payload', 'input_fingerprint', 'agent_signature', 'skill_signature',
    'skill_fingerprint', 'route_key', 'route_signature', 'provider', 'model',
    'technical_result', 'metric_comparison', 'semantic_result', 'proposed_result_status',
    'abstained', 'abstention_reason', 'review_status', 'requested_by', 'started_at',
    'completed_at', 'failed_at', 'error_code', 'error_summary',
])]
final class SearchDemandChangeVerificationRun extends Model
{
    protected function casts(): array
    {
        return [
            'input_payload' => 'array', 'response_payload' => 'array',
            'technical_result' => 'array', 'metric_comparison' => 'array',
            'semantic_result' => 'array', 'abstained' => 'boolean',
            'started_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    public function activityRun(): BelongsTo { return $this->belongsTo(Run::class, 'run_id'); }
    public function tracking(): BelongsTo { return $this->belongsTo(SearchDemandChangeTracking::class, 'search_demand_change_tracking_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
}
