<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceEvaluationBaseline extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'baseline_key',
        'label',
        'evaluation_policy_version',
        'suite_key',
        'suite_version',
        'dataset_version',
        'agent_definition_signature',
        'skill_definition_signature',
        'ai_route_version',
        'retrieval_policy_version',
        'baseline_run_id',
        'dimension_snapshot',
        'is_explicit',
    ];

    /**
     * @return BelongsTo<IntelligenceEvaluationRun, $this>
     */
    public function baselineRun(): BelongsTo
    {
        return $this->belongsTo(IntelligenceEvaluationRun::class, 'baseline_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimension_snapshot' => 'array',
            'is_explicit' => 'boolean',
        ];
    }
}
