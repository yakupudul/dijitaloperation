<?php

namespace App\Models;

use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceChannel;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BrandExperienceSupportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'brand_experience_id',
    'revision_number',
    'context_schema_version',
    'context_snapshot',
    'market_code',
    'market_label',
    'channel',
    'digital_asset_id',
    'situation_summary',
    'situation_period_start',
    'situation_period_end',
    'situation_finding_id',
    'situation_opportunity_id',
    'action_kind',
    'action_summary',
    'action_occurred_at',
    'action_task_id',
    'action_recommendation_id',
    'outcome_summary',
    'outcome_observed_at',
    'outcome_period_start',
    'outcome_period_end',
    'outcome_clarity',
    'support_status',
    'quality_assessment',
    'quality_policy_version',
    'quality_assessed_at',
    'causality_status',
    'business_outcome_observation_revision_id',
    'created_by',
    'idempotency_key',
])]
class BrandExperienceRevision extends Model
{
    /**
     * @return BelongsTo<BrandExperience, $this>
     */
    public function experience(): BelongsTo
    {
        return $this->belongsTo(BrandExperience::class, 'brand_experience_id');
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function actionTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'action_task_id');
    }

    /**
     * @return BelongsTo<Recommendation, $this>
     */
    public function actionRecommendation(): BelongsTo
    {
        return $this->belongsTo(Recommendation::class, 'action_recommendation_id');
    }

    /**
     * @return BelongsTo<Finding, $this>
     */
    public function situationFinding(): BelongsTo
    {
        return $this->belongsTo(Finding::class, 'situation_finding_id');
    }

    /**
     * @return BelongsTo<Opportunity, $this>
     */
    public function situationOpportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'situation_opportunity_id');
    }

    /**
     * @return HasMany<BrandExperienceGoal, $this>
     */
    public function goals(): HasMany
    {
        return $this->hasMany(BrandExperienceGoal::class);
    }

    /**
     * @return HasMany<BrandExperienceOffering, $this>
     */
    public function offerings(): HasMany
    {
        return $this->hasMany(BrandExperienceOffering::class);
    }

    /**
     * @return HasMany<BrandExperienceEvidenceLink, $this>
     */
    public function evidenceLinks(): HasMany
    {
        return $this->hasMany(BrandExperienceEvidenceLink::class);
    }

    /**
     * @return BelongsTo<BusinessOutcomeObservationRevision, $this>
     */
    public function businessOutcomeObservationRevision(): BelongsTo
    {
        return $this->belongsTo(
            BusinessOutcomeObservationRevision::class,
            'business_outcome_observation_revision_id',
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context_snapshot' => 'array',
            'quality_assessment' => 'array',
            'situation_period_start' => 'datetime',
            'situation_period_end' => 'datetime',
            'action_occurred_at' => 'datetime',
            'outcome_observed_at' => 'datetime',
            'outcome_period_start' => 'datetime',
            'outcome_period_end' => 'datetime',
            'quality_assessed_at' => 'datetime',
            'action_kind' => BrandExperienceActionKind::class,
            'channel' => BrandExperienceChannel::class,
            'outcome_clarity' => BrandExperienceOutcomeClarity::class,
            'support_status' => BrandExperienceSupportStatus::class,
            'causality_status' => BrandExperienceCausalityStatus::class,
        ];
    }
}
