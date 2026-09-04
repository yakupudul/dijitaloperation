<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'competitive_intelligence_run_id', 'search_demand_competitor_id', 'competitor_page_observation_id',
    'proposed_entity_kind', 'proposed_competitive_roles', 'page_intent', 'topics', 'subtopics',
    'user_questions', 'content_structure', 'local_trust_signals', 'missing_coverage',
    'unnecessary_content', 'do_not_copy', 'differentiation_ideas', 'evidence_explanation',
    'confidence', 'abstained', 'abstention_reason', 'review_status', 'review_note',
    'reviewed_by', 'reviewed_at',
])]
final class SearchDemandCompetitivePageAnalysis extends Model
{
    protected function casts(): array
    {
        return [
            'proposed_competitive_roles' => 'array', 'topics' => 'array', 'subtopics' => 'array',
            'user_questions' => 'array', 'content_structure' => 'array', 'local_trust_signals' => 'array',
            'missing_coverage' => 'array', 'unnecessary_content' => 'array', 'do_not_copy' => 'array',
            'differentiation_ideas' => 'array', 'evidence_explanation' => 'array',
            'confidence' => 'integer', 'abstained' => 'boolean', 'reviewed_at' => 'immutable_datetime',
        ];
    }

    public function run(): BelongsTo { return $this->belongsTo(SearchDemandCompetitiveIntelligenceRun::class, 'competitive_intelligence_run_id'); }
    public function competitor(): BelongsTo { return $this->belongsTo(SearchDemandCompetitor::class, 'search_demand_competitor_id'); }
    public function observation(): BelongsTo { return $this->belongsTo(SearchDemandCompetitorPageObservation::class, 'competitor_page_observation_id'); }
    public function reviewer(): BelongsTo { return $this->belongsTo(User::class, 'reviewed_by'); }
}
