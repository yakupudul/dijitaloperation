<?php

namespace App\Services\IntelligenceEvaluation;

use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceChannel;
use App\Enums\BrandExperienceOrigin;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Enums\GoalApplicabilityMode;
use App\Enums\GoalKind;
use App\Enums\GoalStatus;
use App\Enums\SectorLearningArtifactKind;
use App\Enums\SectorLearningArtifactStatus;
use App\Enums\SectorLearningCohortBand;
use App\Enums\SectorPrivacyDisposition;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\BrandExperienceRevision;
use App\Models\BrandGoal;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\SectorLearningArtifact;
use App\Models\SectorLearningRevision;
use App\Support\Ai\EvidencePack;
use App\Support\BrandExperiences\BrandExperienceContextSnapshot;
use App\Support\BrandExperiences\Dto\BrandExperienceEvidenceQualityAssessment;
use App\Support\IntelligenceEvaluation\Dto\IntelligenceEvaluationCaseDefinition;
use App\Support\IntelligenceEvaluation\IntelligenceEvaluationCanaries;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;

/**
 * Builds synthetic evaluation fixtures (Prompt 55).
 *
 * Never copies production Customer data. Never pollutes real Sector Learning
 * promotion or Brand Memory workflows beyond isolated synthetic rows.
 */
final class IntelligenceEvaluationSyntheticFixtureBuilder
{
    public const string EVAL_CUSTOMER_MARKER = 'MOXDOP_EVAL_CUSTOMER';

    /**
     * @return array{
     *     customer: Customer,
     *     brand: Brand,
     *     other_customer: Customer,
     *     other_brand: Brand,
     *     digital_asset: DigitalAsset,
     *     goals: array<string, BrandGoal>,
     *     evidence: array<string, Evidence>,
     *     evidence_pack: ?EvidencePack,
     *     experiences: list<BrandExperience>,
     *     sector_artifacts: array<string, SectorLearningArtifact>,
     *     canaries: list<string>,
     *     fixture_map: array<string, mixed>
     * }
     */
    public function build(IntelligenceEvaluationCaseDefinition $case): array
    {
        $hints = $case->fixtureHints;
        $sector = (string) ($hints['sector'] ?? 'dental');
        $market = (string) ($hints['current_market'] ?? $hints['market'] ?? 'DE');
        $channel = (string) ($hints['channel'] ?? 'paid_search');

        $customer = Customer::factory()->create([
            'name' => 'Eval Customer Alpha',
            'industry' => $sector,
            'services_received' => self::EVAL_CUSTOMER_MARKER,
        ]);

        $brand = Brand::factory()->create([
            'customer_id' => $customer->id,
            'name' => $this->brandName($case->subjectBrandKey),
            'sector' => $sector,
        ]);

        $otherCustomer = Customer::factory()->create([
            'name' => 'Eval Customer Beta',
            'industry' => $sector,
            'services_received' => self::EVAL_CUSTOMER_MARKER,
        ]);
        $otherBrand = Brand::factory()->create([
            'customer_id' => $otherCustomer->id,
            'name' => 'Eval Dental Brand Beta',
            'sector' => $sector,
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'name' => 'Eval Dental Asset Alpha',
            'type' => $channel === 'seo' ? 'website' : 'google_ads',
            'domain' => 'eval-dental-alpha.example',
        ]);

        $goals = $this->seedGoals($brand, $case);
        $evidence = $this->seedEvidence($asset, $case, $goals);
        $omitEvidence = (bool) ($hints['omit_required_evidence'] ?? false);
        $evidencePack = $omitEvidence
            ? null
            : $this->buildEvidencePack($customer, $brand, $asset, $evidence, $case);

        $experiences = [];
        $experienceCount = (int) ($hints['brand_experiences'] ?? ($case->expectBrandHistory ? 1 : 0));
        for ($i = 0; $i < $experienceCount; $i++) {
            $experiences[] = $this->createExperience(
                $brand,
                marketCode: (string) ($hints['historical_market'] ?? $market),
                channel: $channel === 'seo' ? BrandExperienceChannel::Website : BrandExperienceChannel::GoogleAds,
                situationSummary: (string) ($hints['inject_experience_text'] ?? 'Eval historical situation '.$i),
            );
        }

        if ($hints['seed_other_brand_canary'] ?? true) {
            $this->createExperience(
                $otherBrand,
                marketCode: 'DE',
                channel: BrandExperienceChannel::GoogleAds,
                situationSummary: 'Private note '.IntelligenceEvaluationCanaries::DENTAL_BRAND_B_EXPERIENCE,
                actionSummary: IntelligenceEvaluationCanaries::RAW_KEYWORD,
                outcomeSummary: IntelligenceEvaluationCanaries::RAW_CREATIVE.' '.IntelligenceEvaluationCanaries::RAW_URL,
            );
        }

        $sectorArtifacts = [];
        if ($hints['seed_sector_relevant'] ?? false) {
            $sectorArtifacts['dental_paid_search_relevant'] = $this->seedSectorArtifact(
                sectorCode: $sector,
                stableSuffix: 'dental_paid_search_relevant',
                channelDimension: 'paid_search',
                status: SectorLearningArtifactStatus::Active,
                disposition: SectorPrivacyDisposition::Eligible,
            );
        }
        if ($hints['seed_sector_wrong_channel'] ?? false) {
            $sectorArtifacts['dental_wrong_channel'] = $this->seedSectorArtifact(
                sectorCode: $sector,
                stableSuffix: 'dental_wrong_channel',
                channelDimension: 'organic_seo',
                status: SectorLearningArtifactStatus::Active,
                disposition: SectorPrivacyDisposition::Eligible,
            );
        }
        if ($hints['seed_sector_privacy_blocked'] ?? false) {
            $sectorArtifacts['dental_privacy_blocked'] = $this->seedSectorArtifact(
                sectorCode: $sector,
                stableSuffix: 'dental_privacy_blocked',
                channelDimension: 'paid_search',
                status: SectorLearningArtifactStatus::PrivacyBlocked,
                disposition: SectorPrivacyDisposition::BlockedSmallCohort,
            );
        }
        if ($hints['seed_sector_other'] ?? false) {
            $sectorArtifacts['other_sector'] = $this->seedSectorArtifact(
                sectorCode: 'legal',
                stableSuffix: 'legal_other_sector',
                channelDimension: 'paid_search',
                status: SectorLearningArtifactStatus::Active,
                disposition: SectorPrivacyDisposition::Eligible,
            );
        }

        return [
            'customer' => $customer,
            'brand' => $brand,
            'other_customer' => $otherCustomer,
            'other_brand' => $otherBrand,
            'digital_asset' => $asset,
            'goals' => $goals,
            'evidence' => $evidence,
            'evidence_pack' => $evidencePack,
            'experiences' => $experiences,
            'sector_artifacts' => $sectorArtifacts,
            'canaries' => IntelligenceEvaluationCanaries::allForbiddenOutsideOwner(),
            'fixture_map' => [
                'subject_brand_key' => $case->subjectBrandKey,
                'market' => $market,
                'channel' => $channel,
                'sector' => $sector,
                'eval_marker' => self::EVAL_CUSTOMER_MARKER,
            ],
        ];
    }

    private function brandName(string $key): string
    {
        return match ($key) {
            'eval_dental_brand_alpha' => 'Eval Dental Brand Alpha',
            'eval_dental_brand_mature' => 'Eval Dental Brand Mature',
            'eval_dental_brand_truth' => 'Eval Dental Brand Truth',
            'eval_dental_brand_pair_a' => 'Eval Dental Brand Pair A',
            'eval_dental_brand_pair_b' => 'Eval Dental Brand Pair B',
            default => 'Eval Brand '.str_replace('_', ' ', $key),
        };
    }

    /**
     * @return array<string, BrandGoal>
     */
    private function seedGoals(Brand $brand, IntelligenceEvaluationCaseDefinition $case): array
    {
        $goals = [];
        $defs = [
            'qualified_consultation_demand' => 'Qualified consultation demand',
            'qualified_consultation_demand_nl' => 'Qualified consultation demand NL',
            'organic_visibility' => 'Organic visibility',
        ];

        foreach ($case->expectedGoalKeys as $key) {
            $label = $defs[$key] ?? $key;
            $goals[$key] = BrandGoal::query()->create([
                'brand_id' => $brand->id,
                'kind' => GoalKind::Conversion,
                'label' => $label,
                'normalized_key' => $key,
                'note' => 'Synthetic evaluation goal',
                'status' => GoalStatus::Active,
                'applicability_mode' => GoalApplicabilityMode::BrandWide,
                'sort_order' => 1,
            ]);
        }

        if ($goals === [] && $case->expectedGoalKeys === []) {
            // still seed one default for brand context when not goal-focused
            $goals['qualified_consultation_demand'] = BrandGoal::query()->create([
                'brand_id' => $brand->id,
                'kind' => GoalKind::Conversion,
                'label' => 'Qualified consultation demand',
                'normalized_key' => 'qualified_consultation_demand',
                'note' => 'Synthetic evaluation goal',
                'status' => GoalStatus::Active,
                'applicability_mode' => GoalApplicabilityMode::BrandWide,
                'sort_order' => 1,
            ]);
        }

        return $goals;
    }

    /**
     * @param  array<string, BrandGoal>  $goals
     * @return array<string, Evidence>
     */
    private function seedEvidence(DigitalAsset $asset, IntelligenceEvaluationCaseDefinition $case, array $goals): array
    {
        if ((bool) ($case->fixtureHints['omit_required_evidence'] ?? false)) {
            return [];
        }

        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
        ]);

        $payloads = [
            'google_ads_search_term_waste' => [
                'type' => 'google_ads_search_term_report',
                'title' => 'Search term waste snapshot',
                'payload' => [
                    'irrelevant_spend_share' => 0.42,
                    'top_irrelevant_terms_redacted' => true,
                    'canary_forbidden' => false,
                ],
            ],
            'google_ads_conversions' => [
                'type' => 'google_ads_conversions',
                'title' => 'Conversions snapshot',
                'payload' => ['conversions' => 20, 'qualified_lead_mapping' => null],
            ],
            'gsc_average_position' => [
                'type' => 'gsc_query_metrics',
                'title' => 'GSC average position',
                'payload' => ['average_position' => 4.2, 'impressions' => 1200],
            ],
            'dataforseo_etv' => [
                'type' => 'dataforseo_etv',
                'title' => 'DataForSEO ETV',
                'payload' => ['etv' => 1500],
            ],
            'ga4_key_event' => [
                'type' => 'ga4_key_event',
                'title' => 'GA4 key event',
                'payload' => ['key_events' => 12],
            ],
            'meta_action_type' => [
                'type' => 'meta_ads_actions',
                'title' => 'Meta actions',
                'payload' => ['action_type' => 'lead', 'value' => 8],
            ],
            'wordpress_canonical_tag' => [
                'type' => 'wordpress_canonical',
                'title' => 'Canonical tag state',
                'payload' => ['configured' => 'https://a.example', 'rendered' => 'https://b.example'],
            ],
            'website_indexing_gap' => [
                'type' => 'website_indexing',
                'title' => 'Indexing gap',
                'payload' => ['indexed' => 12, 'submitted' => 40],
            ],
        ];

        $keys = $case->requiredEvidenceKeys !== []
            ? $case->requiredEvidenceKeys
            : ['google_ads_search_term_waste'];

        $out = [];
        foreach ($keys as $key) {
            $def = $payloads[$key] ?? [
                'type' => 'eval_synthetic_'.$key,
                'title' => 'Synthetic '.$key,
                'payload' => ['key' => $key],
            ];
            $goal = $goals[array_key_first($goals)] ?? null;
            $out[$key] = Evidence::factory()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => 'intelligence-evaluation',
                'type' => $def['type'],
                'title' => $def['title'],
                'payload' => $def['payload'],
                'is_canonical' => true,
                'definition_id' => 'eval.'.$key,
                'evidence_fingerprint' => hash('sha256', 'eval-'.$key.'-'.$asset->id),
                'brand_goal_id' => $goal?->id,
                'observed_at' => now(),
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, Evidence>  $evidence
     */
    private function buildEvidencePack(
        Customer $customer,
        Brand $brand,
        DigitalAsset $asset,
        array $evidence,
        IntelligenceEvaluationCaseDefinition $case,
    ): EvidencePack {
        $items = [];
        foreach ($evidence as $key => $row) {
            $items[] = [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'definition_key' => $key,
                'fingerprint' => (string) $row->evidence_fingerprint,
                'integrity' => 'ok',
                'freshness' => 'fresh',
            ];
        }

        $ids = array_map(static fn (Evidence $e) => (int) $e->id, array_values($evidence));
        $fp = hash('sha256', json_encode($ids) ?: '');

        return new EvidencePack(
            customerId: (int) $customer->id,
            brandId: (int) $brand->id,
            digitalAssetId: (int) $asset->id,
            subjectType: 'brand',
            agentSlug: 'eval-agent',
            agentVersion: '1.0.0',
            skillSignatures: ['eval.skill@1.0.0'],
            routeKey: 'eval_route',
            routeSignature: 'eval_route@1',
            evidenceItems: $items,
            contextFingerprint: $fp,
            inputFingerprint: $fp,
            packedAt: now()->toIso8601String(),
        );
    }

    private function createExperience(
        Brand $brand,
        string $marketCode,
        BrandExperienceChannel $channel,
        string $situationSummary,
        string $actionSummary = 'Eval action',
        string $outcomeSummary = 'Eval outcome',
    ): BrandExperience {
        $quality = new BrandExperienceEvidenceQualityAssessment(
            supportStatus: BrandExperienceSupportStatus::Sufficient,
            reasonCodes: ['causality_not_established', 'temporal_order_valid'],
        );
        $context = new BrandExperienceContextSnapshot(
            brandId: (int) $brand->id,
            customerId: (int) $brand->customer_id,
        );
        $experience = BrandExperience::query()->create([
            'customer_id' => $brand->customer_id,
            'brand_id' => $brand->id,
            'status' => BrandExperienceStatus::Confirmed,
            'origin' => BrandExperienceOrigin::OperatorCaptured,
            'idempotency_key' => 'eval-'.hash('sha256', $brand->id.'|'.$situationSummary.'|'.uniqid('', true)),
        ]);
        $revision = BrandExperienceRevision::query()->create([
            'brand_experience_id' => $experience->id,
            'revision_number' => 1,
            'context_schema_version' => $context->schemaVersion,
            'context_snapshot' => $context->toArray(),
            'market_code' => $marketCode,
            'channel' => $channel,
            'situation_summary' => $situationSummary,
            'action_kind' => BrandExperienceActionKind::ExternalOperatorConfirmed,
            'action_summary' => $actionSummary,
            'action_occurred_at' => now()->subDays(30),
            'outcome_summary' => $outcomeSummary,
            'outcome_observed_at' => now()->subDays(10),
            'outcome_clarity' => BrandExperienceOutcomeClarity::Favorable,
            'support_status' => $quality->supportStatus,
            'quality_assessment' => $quality->toArray(),
            'quality_policy_version' => $quality->policyVersion,
            'quality_assessed_at' => now(),
            'causality_status' => BrandExperienceCausalityStatus::CausalityNotEstablished,
        ]);
        $experience->forceFill(['current_revision_id' => $revision->id])->save();

        return $experience->fresh(['currentRevision']);
    }

    private function seedSectorArtifact(
        string $sectorCode,
        string $stableSuffix,
        string $channelDimension,
        SectorLearningArtifactStatus $status,
        SectorPrivacyDisposition $disposition,
    ): SectorLearningArtifact {
        $artifact = SectorLearningArtifact::query()->create([
            'sector_code' => $sectorCode,
            'stable_key' => hash('sha256', 'eval-sector-'.$stableSuffix.'|'.uniqid('', true).'|'.microtime(true)),
            'artifact_kind' => SectorLearningArtifactKind::ActionOutcomeAssociation,
            'status' => $status,
        ]);
        $revision = SectorLearningRevision::query()->create([
            'artifact_id' => $artifact->id,
            'revision_number' => 1,
            'status' => $status,
            'dimension_contract' => [
                'sector_code' => $sectorCode,
                'channel' => $channelDimension,
                'dimensions' => ['sector_code', 'channel'],
            ],
            'time_scope' => ['granularity' => 'month'],
            'metric_family' => 'outcome_clarity_distribution',
            'action_category' => null,
            'aggregate_result' => [
                'schema' => 'sector_aggregate_action_outcome_v1',
                'causality' => 'causality_not_established',
                'industry_benchmark_claim' => false,
                'cells' => [],
                'eval_key' => $stableSuffix,
            ],
            'cohort_band' => SectorLearningCohortBand::Band5To9,
            'limitations' => ['MOXDOP_COHORT_OBSERVATION', 'OBSERVATIONAL_ONLY'],
            'privacy_policy_version' => SectorLearningPrivacyPolicy::VERSION,
            'aggregation_method_version' => 'sector_aggregation_v1',
            'projection_version' => 'sector_projection_v1',
            'aggregate_fingerprint' => hash('sha256', 'eval-fp-'.$stableSuffix),
            'observational_label' => 'MOXDOP_COHORT_OBSERVATION',
            'summary_text' => 'Privacy-qualified MoxDOP cohort observation (eval).',
            'privacy_assessment' => [
                'disposition' => $disposition->value,
                'reason_codes' => $disposition->isEligible() ? [] : ['blocked'],
                'privacy_score' => null,
            ],
            'internal_distinct_brands' => 5,
            'internal_distinct_customers' => 5,
        ]);
        $artifact->forceFill(['current_revision_id' => $revision->id])->save();

        return $artifact->fresh(['currentRevision']);
    }
}
