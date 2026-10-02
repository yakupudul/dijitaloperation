<?php

namespace App\Models;

use App\Enums\FindingConditionState;
use App\Enums\FindingLifecycleAction;
use Database\Factories\FindingEvaluationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'finding_id',
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
])]
class FindingEvaluation extends Model
{
    /** @use HasFactory<FindingEvaluationFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Finding, $this>
     */
    public function finding(): BelongsTo
    {
        return $this->belongsTo(Finding::class);
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
        return $this->belongsToMany(Evidence::class, 'finding_evaluation_evidence')
            ->withPivot('evidence_observation_fingerprint')
            ->withTimestamps();
    }

    public function conditionState(): FindingConditionState
    {
        return FindingConditionState::from((string) $this->condition_result);
    }

    public function lifecycleAction(): FindingLifecycleAction
    {
        return FindingLifecycleAction::from((string) $this->lifecycle_action);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operand_snapshot' => 'array',
            'threshold_snapshot' => 'array',
            'evaluated_at' => 'datetime',
            'rule_version' => 'integer',
        ];
    }
}
