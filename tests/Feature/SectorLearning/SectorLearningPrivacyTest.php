<?php

namespace Tests\Feature\SectorLearning;

use App\Contracts\IntelligenceMemory\SectorLearningPrivacyGate;
use App\Enums\BrandExperienceActionKind;
use App\Enums\BrandExperienceCausalityStatus;
use App\Enums\BrandExperienceChannel;
use App\Enums\BrandExperienceOrigin;
use App\Enums\BrandExperienceOutcomeClarity;
use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Enums\SectorLearningArtifactKind;
use App\Enums\SectorLearningArtifactStatus;
use App\Enums\SectorLearningCohortBand;
use App\Enums\SectorLearningPrivacyReasonCode;
use App\Enums\SectorPrivacyDisposition;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Models\BrandExperienceRevision;
use App\Models\Customer;
use App\Models\SectorLearningArtifact;
use App\Models\User;
use App\Services\BrandExperiences\BrandExperienceService;
use App\Services\SectorLearning\ProductionSectorLearningPrivacyGate;
use App\Services\SectorLearning\SectorLearningArtifactService;
use App\Services\SectorLearning\SectorLearningAuditService;
use App\Services\SectorLearning\SectorLearningContributionBounder;
use App\Services\SectorLearning\SectorLearningContributionProjector;
use App\Services\SectorLearning\SectorLearningContributionRepository;
use App\Services\SectorLearning\SectorMemoryReadService;
use App\Support\BrandExperiences\BrandExperienceContextSnapshot;
use App\Support\BrandExperiences\Dto\BrandExperienceEvidenceQualityAssessment;
use App\Support\IntelligenceMemory\Dto\SectorIdentityRef;
use App\Support\SectorLearning\Dto\InternalSectorContribution;
use App\Support\SectorLearning\Dto\SafeSectorContributionProjection;
use App\Support\SectorLearning\Dto\SectorMemoryConsumerDto as ConsumerDto;
use App\Support\SectorLearning\SectorLearningMetricRegistry;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;
use App\Support\SectorLearning\SectorLearningSafeDimensionRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectorLearningPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_privacy_policy_defaults_are_centralized(): void
    {
        $snap = SectorLearningPrivacyPolicy::snapshot();
        $this->assertSame(5, $snap['min_distinct_brands']);
        $this->assertSame(5, $snap['min_distinct_customers']);
        $this->assertSame(3, $snap['min_categorical_cell_brands']);
        $this->assertSame(10, $snap['min_numeric_aggregate_brands']);
        $this->assertSame(0.20, $snap['max_single_brand_effective_share']);
        $this->assertFalse($snap['formal_k_anonymity_claim']);
        $this->assertFalse($snap['differential_privacy_claim']);
        $this->assertNull($snap['privacy_score']);
    }

    public function test_gate_rejects_one_customer_many_brands(): void
    {
        $gate = app(SectorLearningPrivacyGate::class);
        $decision = $gate->qualify(new SectorIdentityRef('dental', 'brand.sector'), [
            'contributing_brand_count' => 10,
            'contributing_customer_count' => 1,
            'dimensions' => ['sector_code', 'action_kind'],
            'metric_family' => 'outcome_clarity_distribution',
        ]);
        $this->assertFalse($decision->isEligible());
        $this->assertContains(SectorLearningPrivacyReasonCode::InsufficientDistinctCustomers->value, $decision->reasons);
    }

    public function test_gate_rejects_five_customers_four_brands(): void
    {
        $gate = app(SectorLearningPrivacyGate::class);
        $decision = $gate->qualify(new SectorIdentityRef('dental', 'brand.sector'), [
            'contributing_brand_count' => 4,
            'contributing_customer_count' => 5,
            'dimensions' => ['sector_code'],
            'metric_family' => 'outcome_clarity_distribution',
        ]);
        $this->assertFalse($decision->isEligible());
        $this->assertContains(SectorLearningPrivacyReasonCode::InsufficientDistinctBrands->value, $decision->reasons);
    }

    public function test_numeric_cohort_requires_ten(): void
    {
        $gate = app(SectorLearningPrivacyGate::class);
        $decision = $gate->qualify(new SectorIdentityRef('dental', 'brand.sector'), [
            'contributing_brand_count' => 5,
            'contributing_customer_count' => 5,
            'requires_numeric_cohort' => true,
            'dimensions' => ['sector_code'],
            'metric_family' => 'outcome_clarity_distribution',
        ]);
        $this->assertFalse($decision->isEligible());
        $this->assertContains(SectorLearningPrivacyReasonCode::InsufficientNumericCohort->value, $decision->reasons);
    }

    public function test_raw_identifiers_blocked(): void
    {
        $gate = app(SectorLearningPrivacyGate::class);
        foreach (['brand_id' => 1, 'keyword' => 'implant', 'url' => 'https://x.test', 'campaign_name' => 'X'] as $key => $val) {
            $decision = $gate->qualify(new SectorIdentityRef('dental', 'brand.sector'), [
                $key => $val,
                'contributing_brand_count' => 20,
                'contributing_customer_count' => 20,
            ]);
            $this->assertFalse($decision->isEligible(), $key);
            $this->assertSame(SectorPrivacyDisposition::BlockedRawCustomerData, $decision->disposition);
        }
    }

    public function test_projection_strips_free_text_and_ids(): void
    {
        [$experiences] = $this->seedConfirmedExperiences(1, 1);
        $projector = app(SectorLearningContributionProjector::class);
        $result = $projector->project($experiences[0]);
        $this->assertTrue($result['ok']);
        $safe = $result['contribution']->projection->toConsumerSafeArray();
        $this->assertArrayNotHasKey('customer_id', $safe);
        $this->assertArrayNotHasKey('brand_id', $safe);
        $this->assertArrayNotHasKey('situation_summary', $safe);
        $this->assertArrayNotHasKey('action_summary', $safe);
        $this->assertArrayNotHasKey('outcome_summary', $safe);
        $this->assertArrayNotHasKey('goal_id', $safe);
        $this->assertArrayNotHasKey('offering_id', $safe);
        $this->assertSame(SectorLearningPrivacyPolicy::PROJECTION_VERSION, $safe['projection_version']);
    }

    public function test_draft_experience_not_projected(): void
    {
        $customer = Customer::factory()->create(['industry' => 'dental']);
        $brand = Brand::factory()->create(['customer_id' => $customer->id, 'sector' => 'dental']);
        $experience = BrandExperience::factory()->create([
            'brand_id' => $brand->id,
            'customer_id' => $customer->id,
            'status' => BrandExperienceStatus::Draft,
        ]);
        $result = app(SectorLearningContributionProjector::class)->project($experience);
        $this->assertFalse($result['ok']);
    }

    public function test_brand_experience_count_does_not_dominate(): void
    {
        $bounder = app(SectorLearningContributionBounder::class);
        $contributions = [];
        for ($i = 1; $i <= 100; $i++) {
            $contributions[] = $this->fakeContribution(brandId: 1, customerId: 1, fingerprint: "a{$i}");
        }
        for ($i = 1; $i <= 2; $i++) {
            $contributions[] = $this->fakeContribution(brandId: 2, customerId: 2, fingerprint: "b{$i}");
        }
        // need more brands/customers for a realistic bound — just verify brand 1 reduces to 1 unit
        $result = $bounder->bound($contributions);
        $brand1 = array_filter($result['contributions'], fn ($c) => $c->brandId === 1);
        $this->assertCount(1, $brand1);
    }

    public function test_customer_with_many_brands_is_rebalanced(): void
    {
        $bounder = app(SectorLearningContributionBounder::class);
        $contributions = [];
        // Customer 1 owns 4 of 5 brands → raw share 0.8 > 0.20
        for ($b = 1; $b <= 4; $b++) {
            $contributions[] = $this->fakeContribution(brandId: $b, customerId: 1, fingerprint: "c1b{$b}");
        }
        $contributions[] = $this->fakeContribution(brandId: 5, customerId: 2, fingerprint: 'c2b5');
        $result = $bounder->bound($contributions);
        $this->assertLessThanOrEqual(
            SectorLearningPrivacyPolicy::MAX_SINGLE_CUSTOMER_EFFECTIVE_SHARE + 1e-6,
            $result['customer_shares'][1]
        );
    }

    public function test_safe_artifact_release_and_consumer_dto_privacy(): void
    {
        $this->seedConfirmedExperiences(5, 5);
        $service = app(SectorLearningArtifactService::class);
        $result = $service->buildAndReleaseActionOutcomeAssociation('dental');
        $this->assertTrue($result['released'], implode(',', $result['reasons']));
        $this->assertNotNull($result['revision']);

        $reader = app(SectorMemoryReadService::class);
        $dtos = $reader->listReleasedForSector('dental');
        $this->assertNotEmpty($dtos);
        $array = $dtos[0]->toArray();
        $this->assertArrayNotHasKey('customer_id', $array);
        $this->assertArrayNotHasKey('brand_id', $array);
        $this->assertArrayNotHasKey('experience_id', $array);
        $this->assertSame('moxdop_cohort_observation', $array['source_label']);
        $this->assertFalse($array['industry_benchmark_claim']);
        $this->assertSame('causality_not_established', $array['causality_status']);
        $this->assertStringNotContainsString('causes', strtolower($array['summary_text']));
        $this->assertStringNotContainsString('winning', strtolower($array['summary_text']));
        $json = json_encode($array);
        $this->assertStringNotContainsString('"keyword"', (string) $json);
        $this->assertStringNotContainsString('situation_summary', (string) $json);
    }

    public function test_idempotent_same_fingerprint(): void
    {
        $this->seedConfirmedExperiences(5, 5);
        $service = app(SectorLearningArtifactService::class);
        $a = $service->buildAndReleaseActionOutcomeAssociation('dental');
        $b = $service->buildAndReleaseActionOutcomeAssociation('dental');
        $this->assertTrue($a['released']);
        $this->assertTrue($b['released']);
        $this->assertSame($a['revision']->id, $b['revision']->id);
    }

    public function test_experience_invalidation_marks_artifact_stale(): void
    {
        [$experiences] = $this->seedConfirmedExperiences(5, 5);
        $service = app(SectorLearningArtifactService::class);
        $result = $service->buildAndReleaseActionOutcomeAssociation('dental');
        $this->assertTrue($result['released']);

        $user = User::factory()->create();
        app(BrandExperienceService::class)->invalidate($experiences[0], $user);

        $artifact = SectorLearningArtifact::query()->findOrFail($result['artifact']->id);
        $this->assertSame(SectorLearningArtifactStatus::Stale, $artifact->status);
        $this->assertEmpty(app(SectorMemoryReadService::class)->listReleasedForSector('dental'));
    }

    public function test_lineage_restricted_from_consumer_but_auditable(): void
    {
        $this->seedConfirmedExperiences(5, 5);
        $result = app(SectorLearningArtifactService::class)->buildAndReleaseActionOutcomeAssociation('dental');
        $this->assertTrue($result['released']);
        $lineage = app(SectorLearningAuditService::class)->lineageForRevision((int) $result['revision']->id);
        $this->assertGreaterThanOrEqual(5, $lineage->count());
        $this->assertTrue(isset($lineage->first()->brand_id));
        $dto = app(SectorMemoryReadService::class)->listReleasedForSector('dental')[0]->toArray();
        $this->assertArrayNotHasKey('brand_id', $dto);
        $this->assertArrayNotHasKey('lineage', $dto);
    }

    public function test_metric_compatibility_blocks_blind_cpc_mix(): void
    {
        $this->assertFalse(SectorLearningMetricRegistry::areCompatible(
            'google_ads_cpc_with_meta_cpc',
            'google_ads_cpc_with_meta_cpc'
        ));
        $this->assertFalse(SectorLearningMetricRegistry::isAllowed('exact_spend'));
        $this->assertTrue(SectorLearningMetricRegistry::isAllowed('outcome_clarity_distribution'));
    }

    public function test_city_dimension_forbidden(): void
    {
        $this->assertFalse(SectorLearningSafeDimensionRegistry::isAllowed('city'));
        $this->assertFalse(SectorLearningSafeDimensionRegistry::isAllowed('goal_id'));
        $gate = app(SectorLearningPrivacyGate::class);
        $decision = $gate->qualify(new SectorIdentityRef('dental', 'brand.sector'), [
            'contributing_brand_count' => 20,
            'contributing_customer_count' => 20,
            'city' => 'Manisa',
            'dimensions' => ['sector_code'],
            'metric_family' => 'outcome_clarity_distribution',
        ]);
        $this->assertFalse($decision->isEligible());
    }

    public function test_consumer_dto_rejects_contributor_ids(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ConsumerDto(
            artifactStableKey: 'x',
            artifactId: 1,
            revisionId: 1,
            revisionNumber: 1,
            sectorCode: 'dental',
            artifactKind: SectorLearningArtifactKind::ActionOutcomeAssociation,
            dimensionContract: ['brand_id' => 9],
            timeScope: [],
            actionCategory: null,
            metricFamily: null,
            aggregateResult: [],
            cohortBand: SectorLearningCohortBand::Band5To9,
            limitations: [],
            privacyPolicyVersion: 'v',
            aggregationMethodVersion: 'v',
            projectionVersion: 'v',
            observationalLabel: 'x',
            summaryText: 'x',
            privacyDisposition: SectorPrivacyDisposition::Eligible,
            privacyReasonCodes: [],
            updatedAt: '',
        );
    }

    public function test_cross_brand_repository_is_dedicated_not_generic(): void
    {
        $this->assertTrue(class_exists(SectorLearningContributionRepository::class));
        $this->assertFalse(class_exists('App\\Services\\CrossTenantQueryService'));
        $this->assertFalse(class_exists('App\\Services\\SectorLearning\\SectorLearningV2'));
        $this->assertInstanceOf(
            ProductionSectorLearningPrivacyGate::class,
            app(SectorLearningPrivacyGate::class)
        );
    }

    public function test_insufficient_cohort_does_not_release_artifact(): void
    {
        $this->seedConfirmedExperiences(2, 2);
        $result = app(SectorLearningArtifactService::class)->buildAndReleaseActionOutcomeAssociation('dental');
        $this->assertFalse($result['released']);
        $this->assertSame(0, SectorLearningArtifact::query()->where('status', SectorLearningArtifactStatus::Active)->count());
    }

    public function test_no_embeddings_or_vector_classes_in_sector_learning(): void
    {
        $dir = app_path('Services/SectorLearning');
        foreach (scandir($dir) ?: [] as $file) {
            if (! str_ends_with($file, '.php')) {
                continue;
            }
            $contents = file_get_contents($dir.'/'.$file) ?: '';
            $this->assertStringNotContainsString('embedding', strtolower($contents));
            $this->assertStringNotContainsString('pgvector', strtolower($contents));
            $this->assertStringNotContainsString('OpenAI', $contents);
        }
    }

    /**
     * @return array{0: list<BrandExperience>, 1: list<Brand>, 2: list<Customer>}
     */
    private function seedConfirmedExperiences(int $customers, int $brandsPerRun): array
    {
        $experiences = [];
        $brands = [];
        $customerModels = [];

        for ($i = 0; $i < $customers; $i++) {
            $customer = Customer::factory()->create(['industry' => 'dental']);
            $customerModels[] = $customer;
            $brand = Brand::factory()->create([
                'customer_id' => $customer->id,
                'sector' => 'dental',
            ]);
            $brands[] = $brand;

            $quality = new BrandExperienceEvidenceQualityAssessment(
                supportStatus: BrandExperienceSupportStatus::Sufficient,
                reasonCodes: ['causality_not_established', 'temporal_order_valid', 'action_external_confirmed'],
            );
            $context = new BrandExperienceContextSnapshot(
                brandId: (int) $brand->id,
                customerId: (int) $customer->id,
            );

            $experience = BrandExperience::query()->create([
                'customer_id' => $customer->id,
                'brand_id' => $brand->id,
                'status' => BrandExperienceStatus::Confirmed,
                'origin' => BrandExperienceOrigin::OperatorCaptured,
            ]);

            $revision = BrandExperienceRevision::query()->create([
                'brand_experience_id' => $experience->id,
                'revision_number' => 1,
                'context_schema_version' => $context->schemaVersion,
                'context_snapshot' => $context->toArray(),
                'market_code' => 'DE',
                'channel' => BrandExperienceChannel::GoogleAds,
                'situation_summary' => 'Confidential situation text must not leak.',
                'action_kind' => BrandExperienceActionKind::ExternalOperatorConfirmed,
                'action_summary' => 'Confidential action text must not leak.',
                'action_occurred_at' => now()->subDays(20),
                'outcome_summary' => 'Confidential outcome text must not leak.',
                'outcome_observed_at' => now()->subDays(5),
                'outcome_clarity' => $i < 3
                    ? BrandExperienceOutcomeClarity::Favorable
                    : BrandExperienceOutcomeClarity::Unfavorable,
                'support_status' => $quality->supportStatus,
                'quality_assessment' => $quality->toArray(),
                'quality_policy_version' => $quality->policyVersion,
                'quality_assessed_at' => now(),
                'causality_status' => BrandExperienceCausalityStatus::CausalityNotEstablished,
            ]);

            $experience->forceFill(['current_revision_id' => $revision->id])->save();
            $experiences[] = $experience->fresh(['currentRevision', 'brand.customer']);
        }

        return [$experiences, $brands, $customerModels];
    }

    private function fakeContribution(int $brandId, int $customerId, string $fingerprint): InternalSectorContribution
    {
        return new InternalSectorContribution(
            projection: new SafeSectorContributionProjection(
                projectionVersion: SectorLearningPrivacyPolicy::PROJECTION_VERSION,
                sectorCode: 'dental',
                channel: 'google_ads',
                marketCode: 'DE',
                actionKind: BrandExperienceActionKind::ExternalOperatorConfirmed->value,
                outcomeClarity: BrandExperienceOutcomeClarity::Favorable->value,
                timeBucket: '2026-08',
                supportStatus: BrandExperienceSupportStatus::Sufficient->value,
                qualityPolicyVersion: 'brand_experience_quality_v1',
                causalityStatus: BrandExperienceCausalityStatus::CausalityNotEstablished->value,
                contributionFingerprint: $fingerprint,
            ),
            brandExperienceId: crc32($fingerprint),
            brandExperienceRevisionId: crc32($fingerprint) + 1,
            brandId: $brandId,
            customerId: $customerId,
        );
    }
}
