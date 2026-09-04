<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'search_demand_improvement_run_id', 'origin', 'stable_key', 'severity', 'title',
    'summary', 'action_type', 'recommendation_title', 'recommendation_action',
    'rationale', 'content_brief', 'evidence_refs', 'verification_steps', 'confidence',
    'abstained', 'abstention_reason', 'review_status', 'review_note', 'reviewed_by',
    'reviewed_at', 'evidence_id', 'finding_id', 'recommendation_id',
])]
final class SearchDemandImprovementProposal extends Model
{
    protected function casts(): array
    {
        return [
            'content_brief' => 'array', 'evidence_refs' => 'array',
            'verification_steps' => 'array', 'confidence' => 'integer',
            'abstained' => 'boolean', 'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandImprovementRun::class, 'search_demand_improvement_run_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
    public function evidence(): BelongsTo { return $this->belongsTo(Evidence::class); }
    public function finding(): BelongsTo { return $this->belongsTo(Finding::class); }
    public function recommendation(): BelongsTo { return $this->belongsTo(Recommendation::class); }
}
