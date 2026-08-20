<?php

namespace Tests\Feature\Evidence;

use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Enums\GoalKind;
use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DataIntegrityCheckResult;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Services\BrandIntelligence\BrandGoalService;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\Evidence\CanonicalEvidencePipeline;
use App\Services\Evidence\CanonicalEvidenceReadService;
use App\Services\Evidence\EvidenceDefinitionRegistry;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Support\Evidence\EvidencePeriod;
use App\Support\Integrations\Google\GoogleResourceType;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceCanonicalizationTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private Brand $brand;

    /**
     * @var list<string>
     */
    private array $dates;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'gsc',
            'module_id' => 'search_console',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => 'sc-domain:example.com',
            'display_name' => 'Bound GSC Property',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['reporting_timezone' => 'UTC'],
        ]);

        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => GscSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $this->dates = $this->contiguousDates('2026-06-18', 56);
    }

    public function test_eligible_gsc_facts_become_canonical_evidence_with_stable_fingerprint(): void
    {
        $this->seedGscReady($this->dates);

        $period = new EvidencePeriod('2026-07-16', '2026-08-12', '2026-06-18', '2026-07-15', 28);
        $pipeline = app(CanonicalEvidencePipeline::class);
        $first = $pipeline->canonicalizeAsset($this->asset, period: $period, definitionIds: ['gsc.property.period_comparison']);

        $this->assertSame(1, $first->created);
        $this->assertCount(1, $first->written);
        $evidence = $first->written[0];
        $this->assertTrue($evidence->isCanonical());
        $this->assertSame('gsc.property.period_comparison', $evidence->definition_id);
        $this->assertNotNull($evidence->evidence_fingerprint);
        $this->assertFalse($evidence->generated_by_ai);
        $this->assertSame(CanonicalEvidencePipeline::MODULE_ID, $evidence->run->module_id);
        $this->assertArrayHasKey('period', $evidence->payload);
        $this->assertSame('2026-07-16', $evidence->payload['period']['current']['start']);
        $this->assertSame('FORMULA_PERIOD_RELATIVE_CHANGE', $evidence->payload['metrics']['clicks']['formula_id']);
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Recommendation::query()->count());
        $this->assertSame(0, Task::query()->count());

        $id = $evidence->id;
        $fingerprint = $evidence->evidence_fingerprint;
        $second = $pipeline->canonicalizeAsset($this->asset, period: $period, definitionIds: ['gsc.property.period_comparison']);
        $this->assertSame(0, $second->created);
        $this->assertSame(1, $second->updated);
        $this->assertSame($id, $second->written[0]->id);
        $this->assertSame($fingerprint, $second->written[0]->evidence_fingerprint);
        $this->assertSame(1, Evidence::query()->where('is_canonical', true)->count());
    }

    public function test_integrity_failure_creates_zero_canonical_evidence(): void
    {
        $this->seedGscReady($this->dates, IntegrityCheckStatus::Fail, true);
        $period = new EvidencePeriod('2026-07-16', '2026-08-12', '2026-06-18', '2026-07-15', 28);

        $result = app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $this->asset,
            period: $period,
            definitionIds: ['gsc.property.period_comparison'],
        );

        $this->assertSame(0, $result->created);
        $this->assertSame(0, Evidence::query()->where('is_canonical', true)->count());
        $this->assertSame('ineligible_integrity', $result->ineligible[0]['report']['status']);
    }

    public function test_partial_coverage_is_ineligible(): void
    {
        $this->seedGscReady($this->contiguousDates('2026-07-16', 28));
        $period = new EvidencePeriod('2026-07-16', '2026-08-12', '2026-06-18', '2026-07-15', 28);

        $result = app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $this->asset,
            period: $period,
            definitionIds: ['gsc.property.period_comparison'],
        );

        $this->assertSame(0, $result->created);
        $this->assertSame('ineligible_coverage', $result->ineligible[0]['report']['status']);
    }

    public function test_missing_binding_is_ineligible_scope(): void
    {
        $unbound = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'gsc',
            'status' => DigitalAssetStatus::Active,
        ]);
        $this->seedGscReady($this->dates);

        $period = new EvidencePeriod('2026-07-16', '2026-08-12', '2026-06-18', '2026-07-15', 28);
        $result = app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $unbound,
            period: $period,
            definitionIds: ['gsc.property.period_comparison'],
        );

        $this->assertSame('ineligible_scope', $result->ineligible[0]['report']['status']);
        $this->assertSame(0, Evidence::query()->where('digital_asset_id', $unbound->id)->count());
    }

    public function test_forged_cross_brand_goal_is_rejected(): void
    {
        $this->seedGscReady($this->dates);
        $foreignBrand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $foreignGoal = app(BrandGoalService::class)->create($foreignBrand, GoalKind::Business, 'Increase sales');
        $period = new EvidencePeriod('2026-07-16', '2026-08-12', '2026-06-18', '2026-07-15', 28);

        $result = app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $this->asset,
            period: $period,
            brandGoalId: $foreignGoal->id,
            definitionIds: ['gsc.property.period_comparison'],
        );

        $this->assertSame('ineligible_scope', $result->ineligible[0]['report']['status']);
        $this->assertSame(0, Evidence::query()->where('is_canonical', true)->count());
    }

    public function test_same_brand_goal_and_offering_may_be_referenced(): void
    {
        $this->seedGscReady($this->dates);
        $goal = app(BrandGoalService::class)->create($this->brand, GoalKind::Business, 'Grow consults');
        $offering = app(BrandOfferingService::class)->resolveOrCreate($this->brand, 'Dental Implant')['offering'];
        $period = new EvidencePeriod('2026-07-16', '2026-08-12', '2026-06-18', '2026-07-15', 28);

        $result = app(CanonicalEvidencePipeline::class)->canonicalizeAsset(
            $this->asset,
            period: $period,
            brandGoalId: $goal->id,
            brandOfferingId: $offering->id,
            definitionIds: ['gsc.property.period_comparison'],
        );

        $this->assertSame(1, $result->created);
        $this->assertSame($goal->id, $result->written[0]->brand_goal_id);
        $this->assertSame($offering->id, $result->written[0]->brand_offering_id);
        $this->assertSame($goal->id, $result->written[0]->payload['brand_goal_id']);
    }

    public function test_legacy_json_evidence_is_not_canonical_and_read_has_no_demo_fallback(): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'source_module' => 'website-diagnosis',
            'type' => 'http_fetch',
        ]);

        $read = app(CanonicalEvidenceReadService::class)->forAsset($this->asset);
        $this->assertSame([], $read);
        $this->assertSame(1, Evidence::query()->count());
        $this->assertSame(0, Evidence::query()->where('is_canonical', true)->count());
    }

    public function test_pool_facts_without_pipeline_create_zero_canonical_evidence(): void
    {
        $this->seedGscReady($this->dates);

        $this->assertSame(0, Evidence::query()->count());
        $this->assertSame(0, Finding::query()->count());
        $this->assertTrue(BrandIntelligenceContext::query()->doesntExist());
    }

    public function test_definitions_exist_and_formulas_are_registered(): void
    {
        $registry = app(EvidenceDefinitionRegistry::class);
        $this->assertSame('v1', $registry->version());
        $this->assertSame('gsc.property.period_comparison', $registry->get('gsc.property.period_comparison')->id);
        $this->assertSame('ga4.property.period_comparison', $registry->get('ga4.property.period_comparison')->id);
        $this->assertSame('google_ads.account.period_comparison', $registry->get('google_ads.account.period_comparison')->id);
        $this->assertFalse(class_exists('App\\Models\\EvidenceV2'));
        $this->assertFalse(class_exists('App\\Models\\CanonicalEvidence'));
        $this->assertTrue(class_exists(BrandIntelligenceContext::class));
    }

    /**
     * @param  list<string>  $dates
     */
    private function seedGscReady(
        array $dates,
        IntegrityCheckStatus $status = IntegrityCheckStatus::Pass,
        bool $blocksMigration = false,
    ): void {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);

        DatasetMaterialization::query()->create([
            'dataset_id' => 'gsc_property_daily',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => now(),
            'coverage_start_date' => $set->bounds()['start'],
            'coverage_end_date' => $set->bounds()['end'],
            'row_count_approx' => count($dates),
            'row_count_semantics' => 'approximate_from_batches',
            'partial' => $set->internalGaps() !== [],
            'freshness_metadata' => [
                'successful_coverage_dates' => $dates,
                'coverage_intervals' => $set->intervals,
                'internal_gaps' => $set->internalGaps(),
                'verified_contiguous_watermark' => $set->verifiedContiguousWatermark(),
                'latest_observed_reporting_date' => $set->bounds()['end'],
                'last_successful_reporting_date' => $set->verifiedContiguousWatermark(),
            ],
        ]);

        $audit = DataIntegrityAuditRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'status' => IntegrityAuditStatus::Completed,
            'mode' => IntegrityAuditMode::LocalIntegrity,
            'scope_type' => 'dataset',
            'scope' => ['dataset_id' => 'gsc_property_daily'],
            'contract_registry_version' => 1,
            'storage_contract_version' => 1,
            'formula_registry_version' => 1,
            'integrity_registry_version' => 1,
            'audit_rules_version' => 1,
            'started_at' => now(),
            'completed_at' => now(),
            'checks_total' => 1,
            'checks_pass' => $status === IntegrityCheckStatus::Pass ? 1 : 0,
            'checks_fail' => $status === IntegrityCheckStatus::Fail ? 1 : 0,
        ]);

        DataIntegrityCheckResult::query()->create([
            'audit_run_id' => $audit->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'dataset_id' => 'gsc_property_daily',
            'check_id' => 'natural_key_uniqueness',
            'category' => 'integrity',
            'severity' => 'info',
            'status' => $status,
            'message' => 'test check',
            'blocks_migration' => $blocksMigration,
        ]);

        foreach ($dates as $date) {
            DB::table('gsc_property_daily')->insert([
                'digital_asset_id' => $this->asset->id,
                'external_resource_id' => $this->resource->id,
                'site_url' => 'sc-domain:example.com',
                'reporting_date' => $date,
                'clicks' => 10,
                'impressions' => 100,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'UTC',
                'record_fingerprint' => hash('sha256', 'gsc-'.$date),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @return list<string>
     */
    private function contiguousDates(string $start, int $count): array
    {
        $dates = [];
        $cursor = CarbonImmutable::parse($start);
        for ($i = 0; $i < $count; $i++) {
            $dates[] = $cursor->addDays($i)->toDateString();
        }

        return $dates;
    }
}
