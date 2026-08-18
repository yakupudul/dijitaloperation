<?php

namespace App\Models;

use App\Enums\ProspectEvidenceProvenance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'prospect_id',
    'prospect_research_run_id',
    'prospect_evidence_id',
    'fingerprint',
    'candidate_kind',
    'candidate_type',
    'target_field',
    'proposed_value',
    'support_json',
    'support_label',
    'provenance',
])]
class ProspectDiscoveryCandidate extends Model
{
    public const string KIND_FACT = 'fact';

    public const string KIND_INFERENCE = 'inference';

    protected function casts(): array
    {
        return [
            'provenance' => ProspectEvidenceProvenance::class,
            'support_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Prospect, $this>
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /**
     * @return BelongsTo<ProspectResearchRun, $this>
     */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(ProspectResearchRun::class, 'prospect_research_run_id');
    }

    /**
     * @return BelongsTo<ProspectEvidence, $this>
     */
    public function evidence(): BelongsTo
    {
        return $this->belongsTo(ProspectEvidence::class, 'prospect_evidence_id');
    }
}
