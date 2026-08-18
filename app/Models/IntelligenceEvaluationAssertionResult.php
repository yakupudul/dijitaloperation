<?php

namespace App\Models;

use App\Enums\IntelligenceEvaluationAssertionStatus;
use App\Enums\IntelligenceEvaluationAssertionType;
use App\Enums\IntelligenceEvaluationDimension;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceEvaluationAssertionResult extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'evaluation_case_run_id',
        'assertion_type',
        'dimension',
        'status',
        'is_hard_safety',
        'source_phase',
        'authority',
        'expected',
        'actual',
        'reason_code',
        'diagnostic',
    ];

    /**
     * @return BelongsTo<IntelligenceEvaluationCaseRun, $this>
     */
    public function caseRun(): BelongsTo
    {
        return $this->belongsTo(IntelligenceEvaluationCaseRun::class, 'evaluation_case_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assertion_type' => IntelligenceEvaluationAssertionType::class,
            'dimension' => IntelligenceEvaluationDimension::class,
            'status' => IntelligenceEvaluationAssertionStatus::class,
            'is_hard_safety' => 'boolean',
            'expected' => 'array',
            'actual' => 'array',
        ];
    }
}
