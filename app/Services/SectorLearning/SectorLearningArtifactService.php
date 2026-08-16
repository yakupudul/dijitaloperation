<?php

namespace App\Services\SectorLearning;

use App\Enums\SectorLearningArtifactKind;
use App\Enums\SectorLearningArtifactStatus;
use App\Enums\SectorLearningPrivacyReasonCode;
use App\Enums\SectorPrivacyDisposition;
use App\Models\SectorLearningArtifact;
use App\Models\SectorLearningLineageEntry;
use App\Models\SectorLearningRevision;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\SectorLearning\Dto\InternalSectorContribution;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Build / release privacy-qualified Sector Learning artifacts.
 *
 * Only this service + contribution repository perform cross-brand Experience reads.
 * No provider calls. No AI. No Skill/Recommendation/Task writes.
 */
final class SectorLearningArtifactService
{
    public function __construct(
        private readonly SectorLearningContributionRepository $contributionRepository,
        private readonly SectorLearningContributionBounder $bounder,
        private readonly ProductionSectorLearningPrivacyGate $privacyGate,
        private readonly SectorLearningAggregatorService $aggregator,
    ) {}

    /**
     * @return array{
     *     released: bool,
     *     artifact: SectorLearningArtifact|null,
     *     revision: SectorLearningRevision|null,
     *     reasons: list<string>,
     *     disposition: string
     * }
     */
    public function buildAndReleaseActionOutcomeAssociation(string $sectorCode): array
    {
        $identity = new SectorIdentityRef($sectorCode, 'operator_catalog');
        $contributions = $this->contributionRepository->eligibleContributionsForSector($sectorCode);

        if ($contributions === []) {
            return $this->blockedResult([
                SectorLearningPrivacyReasonCode::ContributionNotQualified->value,
            ]);
        }

        $bounded = $this->bounder->bound($contributions);
        $gateCandidate = [
            'contributing_brand_count' => $bounded['distinct_brands'],
            'contributing_customer_count' => $bounded['distinct_customers'],
            'max_brand_effective_share' => $bounded['brand_shares'] !== []
                ? max($bounded['brand_shares'])
                : 1.0,
            'max_customer_effective_share' => $bounded['customer_shares'] !== []
                ? max($bounded['customer_shares'])
                : 1.0,
            'dimensions' => ['sector_code', 'action_kind', 'outcome_clarity', 'time_bucket'],
            'metric_family' => 'outcome_clarity_distribution',
            'requires_numeric_cohort' => false,
        ];

        $preGate = $this->privacyGate->qualify($identity, $gateCandidate);
        if (! $preGate->isEligible() || ! $bounded['ok']) {
            $reasons = array_values(array_unique(array_merge(
                $preGate->reasons,
                $bounded['reasons'],
            )));

            return $this->blockedResult($reasons, $preGate->disposition->value);
        }

        $aggregate = $this->aggregator->aggregateActionOutcomeAssociation(
            sectorCode: $sectorCode,
            boundedContributions: $bounded['contributions'],
            distinctBrands: $bounded['distinct_brands'],
            distinctCustomers: $bounded['distinct_customers'],
        );

        $postCandidate = array_merge($gateCandidate, [
            'small_cell' => in_array(
                SectorLearningPrivacyReasonCode::SmallCategoricalCell->value,
                $aggregate['reasons'],
                true
            ) && ($aggregate['ok'] ?? false) === false,
            'complementary_disclosure_risk' => in_array(
                SectorLearningPrivacyReasonCode::ComplementaryDisclosureRisk->value,
                $aggregate['reasons'],
                true
            ) && ($aggregate['ok'] ?? false) === false,
            'expose_min_max' => ($aggregate['aggregate_result']['expose_min'] ?? false)
                || ($aggregate['aggregate_result']['expose_max'] ?? false),
        ]);

        if (($aggregate['ok'] ?? false) !== true) {
            $postGate = $this->privacyGate->qualify($identity, array_merge($postCandidate, [
                'complementary_disclosure_risk' => true,
            ]));

            return $this->blockedResult(
                array_values(array_unique(array_merge($aggregate['reasons'], $postGate->reasons))),
                SectorPrivacyDisposition::BlockedPrivacyNotQualified->value,
            );
        }

        // Post-aggregation: ensure no forbidden fields in result
        $serialized = json_encode($aggregate['aggregate_result']);
        foreach (['customer_id', 'brand_id', 'experience_id', 'situation_summary', 'action_summary', 'keyword', 'url'] as $needle) {
            if (is_string($serialized) && str_contains($serialized, '"'.$needle.'"')) {
                return $this->blockedResult([
                    SectorLearningPrivacyReasonCode::ForbiddenConsumerField->value,
                    SectorLearningPrivacyReasonCode::PostAggregationDisclosure->value,
                ]);
            }
        }

        $fingerprint = $this->fingerprint(
            sectorCode: $sectorCode,
            contributions: $bounded['contributions'],
        );

        $stableKey = $this->stableKey($sectorCode, SectorLearningArtifactKind::ActionOutcomeAssociation);

        return DB::transaction(function () use (
            $sectorCode,
            $stableKey,
            $fingerprint,
            $bounded,
            $aggregate,
            $preGate,
        ): array {
            $artifact = SectorLearningArtifact::query()->firstOrCreate(
                ['stable_key' => $stableKey],
                [
                    'sector_code' => $sectorCode,
                    'artifact_kind' => SectorLearningArtifactKind::ActionOutcomeAssociation,
                    'status' => SectorLearningArtifactStatus::PrivacyBlocked,
                ]
            );

            $existing = SectorLearningRevision::query()
                ->where('artifact_id', $artifact->id)
                ->where('aggregate_fingerprint', $fingerprint)
                ->where('status', SectorLearningArtifactStatus::Active)
                ->first();

            if ($existing !== null) {
                return [
                    'released' => true,
                    'artifact' => $artifact->fresh(),
                    'revision' => $existing,
                    'reasons' => ['idempotent_existing_revision'],
                    'disposition' => SectorPrivacyDisposition::Eligible->value,
                ];
            }

            if ($artifact->current_revision_id !== null) {
                SectorLearningRevision::query()
                    ->whereKey($artifact->current_revision_id)
                    ->update([
                        'status' => SectorLearningArtifactStatus::Superseded,
                        'superseded_at' => now(),
                    ]);
            }

            $nextNumber = (int) SectorLearningRevision::query()
                ->where('artifact_id', $artifact->id)
                ->max('revision_number') + 1;

            $revision = SectorLearningRevision::query()->create([
                'artifact_id' => $artifact->id,
                'revision_number' => $nextNumber,
                'status' => SectorLearningArtifactStatus::Active,
                'dimension_contract' => [
                    'sector_code' => $sectorCode,
                    'dimensions' => ['sector_code', 'action_kind', 'outcome_clarity', 'time_bucket'],
                ],
                'time_scope' => [
                    'granularity' => 'month',
                    'family' => 'outcome_observed_month_buckets',
                ],
                'metric_family' => 'outcome_clarity_distribution',
                'action_category' => null,
                'aggregate_result' => $aggregate['aggregate_result'],
                'cohort_band' => $aggregate['cohort_band'],
                'limitations' => $aggregate['limitations'],
                'privacy_policy_version' => SectorLearningPrivacyPolicy::VERSION,
                'aggregation_method_version' => SectorLearningAggregatorService::VERSION,
                'projection_version' => SectorLearningContributionProjector::VERSION,
                'aggregate_fingerprint' => $fingerprint,
                'observational_label' => 'MOXDOP_COHORT_OBSERVATION',
                'summary_text' => $aggregate['summary_text'],
                'privacy_assessment' => [
                    'disposition' => SectorPrivacyDisposition::Eligible->value,
                    'reason_codes' => $preGate->reasons,
                    'policy_version' => SectorLearningPrivacyPolicy::VERSION,
                    'privacy_score' => null,
                ],
                'internal_distinct_brands' => $bounded['distinct_brands'],
                'internal_distinct_customers' => $bounded['distinct_customers'],
            ]);

            foreach ($bounded['contributions'] as $contribution) {
                SectorLearningLineageEntry::query()->create([
                    'revision_id' => $revision->id,
                    'brand_experience_id' => $contribution->brandExperienceId,
                    'brand_experience_revision_id' => $contribution->brandExperienceRevisionId,
                    'brand_id' => $contribution->brandId,
                    'customer_id' => $contribution->customerId,
                    'contribution_fingerprint' => $contribution->projection->contributionFingerprint,
                    'effective_weight' => $contribution->effectiveWeight,
                ]);
            }

            $artifact->forceFill([
                'status' => SectorLearningArtifactStatus::Active,
                'current_revision_id' => $revision->id,
                'sector_code' => $sectorCode,
                'artifact_kind' => SectorLearningArtifactKind::ActionOutcomeAssociation,
            ])->save();

            return [
                'released' => true,
                'artifact' => $artifact->fresh(),
                'revision' => $revision,
                'reasons' => $preGate->reasons,
                'disposition' => SectorPrivacyDisposition::Eligible->value,
            ];
        });
    }

    /**
     * Mark artifacts stale when a contributing Experience revision is invalidated.
     */
    public function markStaleForExperience(int $brandExperienceId): int
    {
        $revisionIds = SectorLearningLineageEntry::query()
            ->where('brand_experience_id', $brandExperienceId)
            ->pluck('revision_id')
            ->unique()
            ->all();

        if ($revisionIds === []) {
            return 0;
        }

        SectorLearningRevision::query()
            ->whereIn('id', $revisionIds)
            ->where('status', SectorLearningArtifactStatus::Active)
            ->update(['status' => SectorLearningArtifactStatus::Stale]);

        $artifactIds = SectorLearningRevision::query()
            ->whereIn('id', $revisionIds)
            ->pluck('artifact_id')
            ->unique()
            ->all();

        return SectorLearningArtifact::query()
            ->whereIn('id', $artifactIds)
            ->where('status', SectorLearningArtifactStatus::Active)
            ->update(['status' => SectorLearningArtifactStatus::Stale]);
    }

    /**
     * @param  list<InternalSectorContribution>  $contributions
     */
    private function fingerprint(string $sectorCode, array $contributions): string
    {
        $parts = array_map(
            static fn ($c) => $c->projection->contributionFingerprint,
            $contributions
        );
        sort($parts);

        return hash('sha256', implode('|', [
            $sectorCode,
            SectorLearningArtifactKind::ActionOutcomeAssociation->value,
            SectorLearningPrivacyPolicy::VERSION,
            SectorLearningAggregatorService::VERSION,
            SectorLearningContributionProjector::VERSION,
            SectorLearningContributionBounder::VERSION,
            ...$parts,
        ]));
    }

    private function stableKey(string $sectorCode, SectorLearningArtifactKind $kind): string
    {
        return hash('sha256', implode('|', [
            'sector_learning',
            $sectorCode,
            $kind->value,
            'action_outcome_association_v1',
        ]));
    }

    /**
     * @param  list<string>  $reasons
     * @return array{
     *     released: bool,
     *     artifact: null,
     *     revision: null,
     *     reasons: list<string>,
     *     disposition: string
     * }
     */
    private function blockedResult(array $reasons, string $disposition = 'blocked_privacy_not_qualified'): array
    {
        return [
            'released' => false,
            'artifact' => null,
            'revision' => null,
            'reasons' => $reasons,
            'disposition' => $disposition,
        ];
    }
}
