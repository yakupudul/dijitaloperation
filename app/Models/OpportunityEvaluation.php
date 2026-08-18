<?php

namespace App\Models;

use App\Enums\OpportunityConditionState;
use App\Enums\OpportunityLifecycleAction;
use Database\Factories\OpportunityEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'opportunity_id',
    'rule_id',
    'rule_version',
    'evaluation_fingerprint',
    'condition_result',
    'eligibility_disposition',
    'block_reason',
    'evaluated_at',
    'operand_snapshot',
    'threshold_snapshot',
    'freshness_state',
    'integrity_state',
    'completeness_state',
    'lifecycle_action',
    'run_id',
    'service_context_snapshot',
    'goal_ids_snapshot',
    'offering_ids_snapshot',
    'market_context_snapshot',
    'commercial_scope_state',
    'qualitative_priority',
])]
class OpportunityEvaluation extends Model
{
    /** @use HasFactory<OpportunityEvaluationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    /**
     * @return BelongsTo<Run, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(Run::class);
    }

    /**
     * @return BelongsToMany<Evidence, $this>
     */
    public function evidence(): BelongsToMany
    {
        return $this->belongsToMany(Evidence::class, 'opportunity_evaluation_evidence', 'evaluation_id', 'evidence_id')
            ->withPivot('evidence_observation_fingerprint')
            ->withTimestamps();
    }

    /**
     * @return BelongsToMany<Finding, $this>
     */
    public function findings(): BelongsToMany
    {
        return $this->belongsToMany(Finding::class, 'opportunity_evaluation_finding', 'evaluation_id', 'finding_id')
            ->withPivot('finding_evaluation_id')
            ->withTimestamps();
    }

    public function conditionState(): OpportunityConditionState
    {
        return OpportunityConditionState::from((string) $this->condition_result);
    }

    public function lifecycleAction(): OpportunityLifecycleAction
    {
        return OpportunityLifecycleAction::from((string) $this->lifecycle_action);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operand_snapshot' => 'array',
            'threshold_snapshot' => 'array',
            'service_context_snapshot' => 'array',
            'goal_ids_snapshot' => 'array',
            'offering_ids_snapshot' => 'array',
            'market_context_snapshot' => 'array',
            'evaluated_at' => 'datetime',
            'rule_version' => 'integer',
        ];
    }
}
