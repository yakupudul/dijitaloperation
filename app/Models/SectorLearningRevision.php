<?php

namespace App\Models;

use App\Enums\SectorLearningArtifactStatus;
use App\Enums\SectorLearningCohortBand;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'artifact_id',
    'revision_number',
    'status',
    'dimension_contract',
    'time_scope',
    'metric_family',
    'action_category',
    'aggregate_result',
    'cohort_band',
    'limitations',
    'privacy_policy_version',
    'aggregation_method_version',
    'projection_version',
    'aggregate_fingerprint',
    'observational_label',
    'summary_text',
    'privacy_assessment',
    'internal_distinct_brands',
    'internal_distinct_customers',
    'superseded_at',
])]
class SectorLearningRevision extends Model
{
    /**
     * @return BelongsTo<SectorLearningArtifact, $this>
     */
    public function artifact(): BelongsTo
    {
        return $this->belongsTo(SectorLearningArtifact::class, 'artifact_id');
    }

    /**
     * Restricted lineage — never eager-load into consumer DTO serializers.
     *
     * @return HasMany<SectorLearningLineageEntry, $this>
     */
    public function lineageEntries(): HasMany
    {
        return $this->hasMany(SectorLearningLineageEntry::class, 'revision_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SectorLearningArtifactStatus::class,
            'cohort_band' => SectorLearningCohortBand::class,
            'dimension_contract' => 'array',
            'time_scope' => 'array',
            'aggregate_result' => 'array',
            'limitations' => 'array',
            'privacy_assessment' => 'array',
            'superseded_at' => 'datetime',
        ];
    }
}
