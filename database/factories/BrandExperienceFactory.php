<?php

namespace Database\Factories;

use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceOrigin;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\BrandExperienceRevision;
use App\Support\BrandExperiences\BrandExperienceContextSnapshot;
use App\Support\BrandExperiences\Dto\BrandExperienceEvidenceQualityAssessment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandExperience>
 */
class BrandExperienceFactory extends Factory
{
    protected $model = BrandExperience::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'customer_id' => 0, // replaced in afterMaking from Brand tenancy
            'status' => BrandExperienceStatus::Draft,
            'origin' => BrandExperienceOrigin::OperatorCaptured,
            'recorded_by' => null,
            'idempotency_key' => null,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (BrandExperience $experience): void {
            $brand = $experience->brand_id
                ? Brand::query()->find($experience->brand_id)
                : null;
            if ($brand instanceof Brand) {
                $experience->customer_id = (int) $brand->customer_id;
            }
        })->afterCreating(function (BrandExperience $experience): void {
            if ($experience->current_revision_id !== null) {
                return;
            }

            $brand = Brand::query()->findOrFail($experience->brand_id);
            $actionAt = now()->subDays(14);
            $outcomeAt = now()->subDays(2);
            $quality = new BrandExperienceEvidenceQualityAssessment(
                supportStatus: BrandExperienceSupportStatus::Partial,
                reasonCodes: ['causality_not_established', 'operator_only_observation', 'temporal_order_valid', 'action_external_confirmed'],
            );
            $context = new BrandExperienceContextSnapshot(
                brandId: (int) $brand->id,
                customerId: (int) $brand->customer_id,
            );

            $revision = BrandExperienceRevision::query()->create([
                'brand_experience_id' => $experience->id,
                'revision_number' => 1,
                'context_schema_version' => $context->schemaVersion,
                'context_snapshot' => $context->toArray(),
                'situation_summary' => 'Observed situation for factory Experience.',
                'action_kind' => BrandExperienceActionKind::ExternalOperatorConfirmed,
                'action_summary' => 'Operator-confirmed external action.',
                'action_occurred_at' => $actionAt,
                'outcome_summary' => 'Later observation after action.',
                'outcome_observed_at' => $outcomeAt,
                'outcome_clarity' => BrandExperienceOutcomeClarity::Unclear,
                'support_status' => $quality->supportStatus,
                'quality_assessment' => $quality->toArray(),
                'quality_policy_version' => $quality->policyVersion,
                'quality_assessed_at' => now(),
                'causality_status' => BrandExperienceCausalityStatus::CausalityNotEstablished,
            ]);

            $experience->forceFill(['current_revision_id' => $revision->id])->save();
        });
    }

    public function confirmed(): static
    {
        return $this->state(fn (): array => [
            'status' => BrandExperienceStatus::Confirmed,
        ]);
    }
}
