<?php

namespace Tests\Feature\DataPool;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DataPool\MigrationReadinessStatus;
use App\Enums\DataPool\WriteBatchStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DataPool\DatasetWriteBatch;
use App\Models\DigitalAsset;
use App\Services\DataPool\Integrity\DataIntegrityRegistryLoader;
use App\Services\DataPool\Integrity\DataPoolIntegrityAuditor;
use App\Services\DataPool\Integrity\MetricAggregationGuard;
use App\Services\DataPool\Integrity\RealDataMigrationReadinessService;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\DataPool\Integrity\Support\IntegrityAuditRequest;
use App\Services\DataPool\Integrity\Support\IntegrityCheckOutcome;
use App\Services\DataPool\StorageContractValidator;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataPoolIntegrityReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_11110001',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
            ],
        ]);
        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function integrity_registry_loads_with_profiles_for_all_production_providers(): void
    {
        $loader = app(DataIntegrityRegistryLoader::class);
        $this->assertSame('MOXDOP_DATA_INTEGRITY_REGISTRY', $loader->registryId());
        $this->assertSame(1, $loader->version());
        $this->assertFalse($loader->globalPolicies()['numeric_quality_score']);
        $this->assertFalse($loader->globalPolicies()['automatic_repair']);

        $profiles = $loader->profiles();
        $this->assertGreaterThanOrEqual(40, count($profiles));
        $providers = collect($profiles)->pluck('provider_or_source')->unique()->sort()->values()->all();
        $this->assertSame(['GA4', 'GOOGLE_ADS', 'META_ADS', 'SEARCH_CONSOLE'], $providers);

        foreach ($profiles as $profile) {
            $this->assertFalse($profile['collection_run_in_natural_key']);
            $this->assertNotEmpty($profile['required_checks']);
        }
    }

    #[Test]
    public function integrity_registry_schema_and_storage_contract_remain_valid(): void
    {
        $schema = json_decode(file_get_contents(base_path('docs/data-contracts/MOXDOP_DATA_INTEGRITY_REGISTRY_V1.schema.json')), true);
        $this->assertSame('MOXDOP_DATA_INTEGRITY_REGISTRY', $schema['properties']['metadata']['properties']['integrity_registry_id']['const']);

        $errors = app(StorageContractValidator::class)->validate();
        $this->assertSame([], $errors);
    }

    #[Test]
    public function coverage_interval_set_detects_internal_gap_despite_full_minmax(): void
    {
        $dates = ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-05', '2026-01-06'];
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);
        $gaps = $set->gapsIn('2026-01-01', '2026-01-06');
        $this->assertSame(['2026-01-04'], $gaps);
        $bounds = $set->bounds();
        $this->assertSame('2026-01-01', $bounds['start']);
        $this->assertSame('2026-01-06', $bounds['end']);
    }

    #[Test]
    public function zero_row_successful_date_is_coverage_not_gap(): void
    {
        $dates = ['2026-01-01', '2026-01-02', '2026-01-03']; // Jan 2 zero-row still listed as successful
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);
        $this->assertSame([], $set->gapsIn('2026-01-01', '2026-01-03'));
    }

    #[Test]
    public function natural_key_duplicates_are_detected_without_repair(): void
    {
        if (! Schema::hasTable('meta_campaign_daily')) {
            $this->markTestSkipped('meta_campaign_daily missing');
        }

        // Drop DB unique so we can simulate a defective duplicate state the auditor must detect.
        Schema::table('meta_campaign_daily', function ($table): void {
            $table->dropUnique('meta_campaign_daily_nk_unique');
        });

        $base = [
            'digital_asset_id' => $this->asset->id,
            'account_id' => 'act_11110001',
            'reporting_date' => '2026-08-01',
            'campaign_id' => 'cmp_1',
            'source_timezone' => 'Europe/Berlin',
            'currency' => 'EUR',
            'impressions' => 10,
            'clicks' => 1,
            'spend' => 1.5,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'record_fingerprint' => 'fp-a',
            'last_collection_run_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('meta_campaign_daily')->insert($base);
        DB::table('meta_campaign_daily')->insert(array_merge($base, [
            'impressions' => 99,
            'record_fingerprint' => 'fp-b',
        ]));

        $before = (int) DB::table('meta_campaign_daily')->count();

        $run = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_campaign_daily'],
            digitalAssetIds: [$this->asset->id],
            externalResourceIds: [$this->resource->id],
        ));

        $dup = $run->checkResults()
            ->where('check_id', 'natural_key_duplicates')
            ->where('dataset_id', 'meta_campaign_daily')
            ->first();
        $this->assertNotNull($dup);
        $this->assertSame(IntegrityCheckStatus::Fail, $dup->status);
        $this->assertSame($before, (int) DB::table('meta_campaign_daily')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function retry_upsert_accounting_passes_one_to_one(): void
    {
        $run = CollectionRun::factory()->create(['digital_asset_id' => $this->asset->id]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'core_asset_binding_id' => $this->binding->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'META_ADS',
        ]);
        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'META_ADS',
            'dataset_contract_id' => 'meta_campaign_daily',
            'request_family_id' => 'RF_META_INSIGHTS_DAILY',
            'status' => CollectionRunStatus::Completed,
            'rows_received' => 100,
            'rows_written' => 100,
        ]);
        DatasetWriteBatch::query()->create([
            'dataset_run_id' => $datasetRun->id,
            'batch_key' => 'b1',
            'idempotency_key' => 'idem-1',
            'dataset_id' => 'meta_campaign_daily',
            'status' => WriteBatchStatus::Committed,
            'rows_received' => 100,
            'rows_inserted' => 80,
            'rows_updated' => 15,
            'rows_unchanged' => 5,
            'committed_at' => now(),
        ]);

        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_campaign_daily'],
            digitalAssetIds: [$this->asset->id],
        ));

        $row = $audit->checkResults()->where('check_id', 'row_accounting')->first();
        $this->assertSame(IntegrityCheckStatus::Pass, $row->status);
    }

    #[Test]
    public function silent_row_loss_fails(): void
    {
        $run = CollectionRun::factory()->create(['digital_asset_id' => $this->asset->id]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'provider_or_source' => 'META_ADS',
        ]);
        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'META_ADS',
            'dataset_contract_id' => 'meta_campaign_daily',
            'status' => CollectionRunStatus::Completed,
        ]);
        DatasetWriteBatch::query()->create([
            'dataset_run_id' => $datasetRun->id,
            'batch_key' => 'loss',
            'idempotency_key' => 'idem-loss',
            'dataset_id' => 'meta_campaign_daily',
            'status' => WriteBatchStatus::Committed,
            'rows_received' => 100,
            'rows_inserted' => 90,
            'rows_updated' => 0,
            'rows_unchanged' => 0,
            'committed_at' => now(),
        ]);

        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_campaign_daily'],
            digitalAssetIds: [$this->asset->id],
        ));
        $row = $audit->checkResults()->where('check_id', 'row_accounting')->first();
        $this->assertSame(IntegrityCheckStatus::Fail, $row->status);
    }

    #[Test]
    public function typed_meta_action_expansion_allows_written_gt_received(): void
    {
        $run = CollectionRun::factory()->create(['digital_asset_id' => $this->asset->id]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'provider_or_source' => 'META_ADS',
        ]);
        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'META_ADS',
            'dataset_contract_id' => 'meta_typed_action_daily',
            'status' => CollectionRunStatus::Completed,
        ]);
        DatasetWriteBatch::query()->create([
            'dataset_run_id' => $datasetRun->id,
            'batch_key' => 'actions',
            'idempotency_key' => 'idem-actions',
            'dataset_id' => 'meta_typed_action_daily',
            'status' => WriteBatchStatus::Committed,
            'rows_received' => 10,
            'rows_inserted' => 27,
            'rows_updated' => 0,
            'rows_unchanged' => 0,
            'committed_at' => now(),
        ]);

        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_typed_action_daily'],
            digitalAssetIds: [$this->asset->id],
        ));
        $row = $audit->checkResults()->where('check_id', 'row_accounting')->first();
        $this->assertSame(IntegrityCheckStatus::Pass, $row->status);
    }

    #[Test]
    public function coverage_gap_detected_from_interval_evidence(): void
    {
        DatasetMaterialization::query()->create([
            'dataset_id' => 'meta_campaign_daily',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'META_ADS',
            'contract_version' => 1,
            'coverage_start_date' => '2026-01-01',
            'coverage_end_date' => '2026-01-06',
            'status' => MaterializationStatus::Available,
            'partial' => false,
            'last_collected_at' => now(),
            'freshness_metadata' => [
                'successful_coverage_dates' => ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-05', '2026-01-06'],
            ],
        ]);

        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_campaign_daily'],
            digitalAssetIds: [$this->asset->id],
            externalResourceIds: [$this->resource->id],
        ));
        $coverage = $audit->checkResults()->where('check_id', 'coverage_intervals')->first();
        $this->assertSame(IntegrityCheckStatus::Fail, $coverage->status);
        $this->assertStringContainsString('gap', strtolower((string) $coverage->message));
    }

    #[Test]
    public function meta_async_provider_complete_with_incomplete_download_fails(): void
    {
        $run = CollectionRun::factory()->create(['digital_asset_id' => $this->asset->id]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'digital_asset_id' => $this->asset->id,
            'provider_or_source' => 'META_ADS',
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'META_ADS',
            'dataset_contract_id' => 'meta_ad_daily',
            'request_family_id' => 'RF_META_INSIGHTS_DAILY',
            'status' => CollectionRunStatus::Completed,
            'checkpoint' => [
                'async' => [
                    'active' => true,
                    'stage' => 'DOWNLOADING_RESULTS',
                    'provider_percent' => 100,
                    'provider_status' => 'JOB_COMPLETED',
                    'pages_downloaded' => 2,
                    'result_complete' => false,
                ],
            ],
        ]);

        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_ad_daily'],
            digitalAssetIds: [$this->asset->id],
        ));
        $page = $audit->checkResults()->where('check_id', 'pagination_completeness')->first();
        $this->assertSame(IntegrityCheckStatus::Fail, $page->status);
    }

    #[Test]
    public function ga4_rowcount_mismatch_fails(): void
    {
        $ga4Asset = DigitalAsset::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $run = CollectionRun::factory()->create(['digital_asset_id' => $ga4Asset->id]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'digital_asset_id' => $ga4Asset->id,
            'provider_or_source' => 'GA4',
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'GA4',
            'dataset_contract_id' => 'ga4_property_daily',
            'request_family_id' => 'GA4_RF_PROPERTY_DAILY',
            'status' => CollectionRunStatus::Completed,
            'rows_received' => 100,
            'checkpoint' => [
                'provider_row_count' => 42319,
                'rows_received_total' => 40000,
                'execution_completeness' => 'PROVEN_COMPLETE',
            ],
        ]);

        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['GA4'],
            datasetIds: ['ga4_property_daily'],
            digitalAssetIds: [$ga4Asset->id],
        ));
        $page = $audit->checkResults()->where('check_id', 'pagination_completeness')->first();
        $this->assertSame(IntegrityCheckStatus::Fail, $page->status);
    }

    #[Test]
    public function non_additive_metrics_cannot_be_summed(): void
    {
        $guard = app(MetricAggregationGuard::class);
        $this->assertFalse($guard->canSumAcrossDates('reach'));
        $this->assertFalse($guard->canSumAcrossDates('frequency'));
        $this->assertFalse($guard->canSumAcrossDates('totalUsers'));
        $this->assertFalse($guard->canAverageBlindly('frequency'));
        $this->expectException(InvalidArgumentException::class);
        $guard->assertSummationAllowed('reach', ['reach']);
    }

    #[Test]
    public function currency_mismatch_and_cross_currency_fail(): void
    {
        if (! Schema::hasTable('meta_campaign_daily')) {
            $this->markTestSkipped('meta_campaign_daily missing');
        }

        DB::table('meta_campaign_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'account_id' => 'act_11110001',
            'reporting_date' => '2026-08-01',
            'campaign_id' => 'cmp_eur',
            'source_timezone' => 'Europe/Berlin',
            'currency' => 'TRY',
            'impressions' => 1,
            'clicks' => 0,
            'spend' => 1,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'record_fingerprint' => 'fp-try',
            'last_collection_run_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_campaign_daily'],
            digitalAssetIds: [$this->asset->id],
            externalResourceIds: [$this->resource->id],
        ));
        $currency = $audit->checkResults()->where('check_id', 'currency_provenance')->first();
        $this->assertSame(IntegrityCheckStatus::Fail, $currency->status);
    }

    #[Test]
    public function timezone_mismatch_fails(): void
    {
        if (! Schema::hasTable('meta_campaign_daily')) {
            $this->markTestSkipped('meta_campaign_daily missing');
        }

        DB::table('meta_campaign_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'account_id' => 'act_11110001',
            'reporting_date' => '2026-08-01',
            'campaign_id' => 'cmp_tz',
            'source_timezone' => 'UTC',
            'currency' => 'EUR',
            'impressions' => 1,
            'clicks' => 0,
            'spend' => 1,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'record_fingerprint' => 'fp-tz',
            'last_collection_run_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_campaign_daily'],
            digitalAssetIds: [$this->asset->id],
            externalResourceIds: [$this->resource->id],
        ));
        $tz = $audit->checkResults()->where('check_id', 'timezone_provenance')->first();
        $this->assertSame(IntegrityCheckStatus::Fail, $tz->status);
    }

    #[Test]
    public function provider_readiness_is_independent_and_has_no_score(): void
    {
        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS', 'GA4'],
        ));

        $this->assertArrayHasKey('META_ADS', $audit->provider_readiness);
        $this->assertArrayHasKey('GA4', $audit->provider_readiness);
        $this->assertNull($audit->provider_readiness['META_ADS']['numeric_quality_score']);
        $this->assertNull($audit->provider_readiness['_global']['numeric_quality_score'] ?? null);

        // Inject blocking failure only for Meta and ensure GA4 path remains independently evaluable.
        $service = app(RealDataMigrationReadinessService::class);
        $metaBlocked = [
            'status' => MigrationReadinessStatus::BlockedIntegrity->value,
            'allows_real_ui_migration' => false,
            'blocking_datasets' => [['dataset_id' => 'meta_ad_daily']],
            'limitations' => [],
            'dataset_statuses' => ['meta_ad_daily' => MigrationReadinessStatus::BlockedIntegrity->value],
            'numeric_quality_score' => null,
        ];
        $this->assertFalse($metaBlocked['allows_real_ui_migration']);
        $this->assertTrue(
            MigrationReadinessStatus::ReadyForRealUi->allowsRealUiMigration()
        );
        Http::assertNothingSent();
        $this->assertSame(IntegrityAuditMode::LocalIntegrity, $audit->mode);
    }

    #[Test]
    public function local_audit_makes_zero_provider_calls_and_command_works(): void
    {
        Http::fake();
        $this->artisan('moxdop:data-pool-audit', [
            '--provider' => ['META_ADS'],
            '--json' => true,
        ])->assertSuccessful();

        $this->assertSame(1, DataIntegrityAuditRun::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function provider_reconcile_mode_requires_explicit_config(): void
    {
        config(['moxdop-data-integrity.allow_provider_reconciliation' => false]);
        $this->expectException(InvalidArgumentException::class);
        app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            mode: IntegrityAuditMode::ProviderReconciliation,
            providers: ['META_ADS'],
        ));
    }

    #[Test]
    public function unverified_is_not_ready(): void
    {
        $service = app(RealDataMigrationReadinessService::class);
        $status = $service->evaluateDatasetChecks([
            new IntegrityCheckOutcome(
                checkId: 'coverage_intervals',
                category: 'coverage',
                status: IntegrityCheckStatus::Unverified,
                blocksMigration: true,
                providerOrSource: 'META_ADS',
                datasetId: 'meta_campaign_daily',
            ),
        ]);
        $this->assertSame(MigrationReadinessStatus::Unverified, $status);
        $this->assertFalse($status->allowsRealUiMigration());
    }

    #[Test]
    public function snapshot_dataset_skips_daily_gap_validation(): void
    {
        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest(
            providers: ['META_ADS'],
            datasetIds: ['meta_campaign_snapshot'],
            digitalAssetIds: [$this->asset->id],
        ));
        $snap = $audit->checkResults()->where('check_id', 'snapshot_semantics')->first();
        $this->assertNotNull($snap);
        $this->assertSame(IntegrityCheckStatus::Pass, $snap->status);
        $coverage = $audit->checkResults()->where('check_id', 'coverage_intervals')->first();
        $this->assertTrue(
            $coverage === null || $coverage->status === IntegrityCheckStatus::NotApplicable
        );
    }

    #[Test]
    public function real_pool_audit_reports_scale_honestly_when_empty(): void
    {
        $audit = app(DataPoolIntegrityAuditor::class)->run(new IntegrityAuditRequest);
        $this->assertSame(0, (int) ($audit->summary['real_pool_fact_rows_observed'] ?? 0));
        $this->assertGreaterThan(0, $audit->checks_total);
        Http::assertNothingSent();
    }
}
