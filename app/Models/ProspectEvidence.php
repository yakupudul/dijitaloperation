<?php

namespace App\Models;

use App\Enums\ProspectEvidenceProvenance;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'prospect_id',
    'prospect_research_run_id',
    'type',
    'title',
    'source_url',
    'fingerprint',
    'provenance',
    'payload',
    'observed_at',
])]
class ProspectEvidence extends Model
{
    protected $table = 'prospect_evidence';

    protected function casts(): array
    {
        return [
            'provenance' => ProspectEvidenceProvenance::class,
            'payload' => 'array',
            'observed_at' => 'datetime',
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
     * @return HasMany<ProspectDiscoveryCandidate, $this>
     */
    public function discoveryCandidates(): HasMany
    {
        return $this->hasMany(ProspectDiscoveryCandidate::class);
    }
}
