<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntelligenceEvaluationHumanReview extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'evaluation_case_run_id',
        'rubric_version',
        'reviewer_id',
        'dimension_outcomes',
        'notes',
        'attempted_privacy_override',
        'privacy_override_accepted',
        'reviewed_at',
    ];

    /**
     * @return BelongsTo<IntelligenceEvaluationCaseRun, $this>
     */
    public function caseRun(): BelongsTo
    {
        return $this->belongsTo(IntelligenceEvaluationCaseRun::class, 'evaluation_case_run_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dimension_outcomes' => 'array',
            'attempted_privacy_override' => 'boolean',
            'privacy_override_accepted' => 'boolean',
            'reviewed_at' => 'datetime',
        ];
    }
}
