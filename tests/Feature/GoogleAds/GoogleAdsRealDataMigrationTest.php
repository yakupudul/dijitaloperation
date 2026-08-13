<?php

namespace Tests\Feature\GoogleAds;

use App\Enums\DataPool\DataSourceState;
use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DataPool\DataIntegrityAuditRun;
use App\Models\DataPool\DataIntegrityCheckResult;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\GoogleAds\GoogleAdsPoolReadRepository;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSpecialistReadService;
use App\Services\GoogleAds\Support\GoogleAdsBindingMode;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\GoogleAdsWorkspaceFixtures;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GoogleAdsRealDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
        ]);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'module_id' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [GoogleScopes::ADWORDS],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'gads-access-token',
                'refresh_token' => 'gads-refresh-token',
                'scope' => GoogleScopes::ADWORDS,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333',
            'display_name' => 'Bound Google Ads Customer',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'timezone' => 'Europe/London',
                'currency' => 'GBP',
            ],
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function demo_catalog_asset_uses_fixtures_with_demo_provenance(): void
    {
        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace(DemoCatalog::GOOGLE_ADS_ASSET_ID);

        $this->assertSame('demo_catalog', $workspace['migration_mode']);
        $this->assertSame(
            GoogleAdsWorkspaceFixtures::workspace('last_28')['campaigns'][0]['spend'],
            $workspace['campaigns'][0]['spend'],
        );
        foreach ($workspace['data_provenance'] as $field => $state) {
            $this->assertSame(DataSourceState::Demo->value, $state, "Field {$field} should be DEMO");
        }
        foreach ($workspace['tab_status'] as $status) {
            $this->assertSame('DEMO', $status);
        }
    }

    #[Test]
    public function binding_resolver_without_binding_is_not_connected_and_never_picks_arbitrary_customer(): void
    {
        $unbound = DigitalAsset::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $otherResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '9998887777',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $context = app(GoogleAdsSpecialistBindingResolver::class)->resolve((string) $unbound->id);

        $this->assertSame(GoogleAdsBindingMode::NotConnected, $context->mode);
        $this->assertNull($context->customerId);
        $this->assertNotSame('9998887777', $context->customerId);

        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $unbound->id);
        $this->assertSame('not_connected', $workspace['migration_mode']);
        $this->assertNull($workspace['identity']['customer_id']);
        $this->assertSame('—', $workspace['glance']['spend']['value']);

        $this->assertDatabaseMissing('core_asset_bindings', [
            'digital_asset_id' => $unbound->id,
            'external_resource_id' => $otherResource->id,
        ]);
    }

    #[Test]
    public function binding_resolver_uses_human_confirmed_core_asset_binding_customer_id(): void
    {
        $altResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '5554443333',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $context = app(GoogleAdsSpecialistBindingResolver::class)->resolve((string) $this->asset->id);

        $this->assertSame(GoogleAdsBindingMode::RealBound, $context->mode);
        $this->assertSame('1112223333', $context->customerId);
        $this->assertSame($this->binding->id, $context->coreAssetBindingId);
        $this->assertNotSame($altResource->id, $context->externalResourceId);
    }

    #[Test]
    public function account_kpis_come_from_account_daily_not_campaign_or_keyword_sums(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('google_ads_account_daily', $dates);
        $this->insertAccountDailyRows($dates, costMicros: 100_000_000, conversions: 4);

        foreach ($dates as $date) {
            $this->insertCampaignDailyRow($date, 'camp-1', costMicros: 999_000_000, conversions: 999);
            $this->insertKeywordDailyRow($date, 'kw-1', costMicros: 999_000_000, conversions: 999);
        }

        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame(2800.0, $workspace['glance']['spend']['raw']);
        $this->assertSame(112.0, $workspace['glance']['conversions']['raw']);
        $this->assertNotSame(999.0 * 28, $workspace['glance']['spend']['raw']);
    }

    #[Test]
    public function cpa_and_pacing_are_always_unavailable_on_the_real_path(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('google_ads_account_daily', $dates);
        $this->insertAccountDailyRows($dates, costMicros: 100_000_000, conversions: 4);

        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame(DataSourceState::Unavailable->value, $workspace['data_provenance']['glance.cpa']);
        $this->assertSame(DataSourceState::Unavailable->value, $workspace['data_provenance']['glance.pacing']);
        $this->assertStringContainsString('unavailable', strtolower($workspace['glance']['cpa']['value']));
        $this->assertStringContainsString('business-action mapping', $workspace['glance']['cpa']['note']);
        $this->assertNull($workspace['business_goal']['primary_conversion']);
    }

    #[Test]
    public function conversions_carry_a_qualified_lead_disclaimer(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('google_ads_account_daily', $dates);
        $this->insertAccountDailyRows($dates, costMicros: 100_000_000, conversions: 4);

        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertStringContainsString('not automatically Qualified Lead', $workspace['glance']['conversions']['note']);
        $this->assertStringContainsString('not Qualified Leads', $workspace['performance_trend']['note']);
    }

    #[Test]
    public function campaigns_are_honest_and_pmax_never_shows_a_fake_ad_group(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('google_ads_account_daily', $dates);
        $this->seedDatasetReady('google_ads_campaign_daily', $dates);
        $this->insertAccountDailyRows($dates, costMicros: 100_000_000, conversions: 4);
        $this->insertCampaignSnapshot('camp-pmax', 'PMax Campaign', 'PERFORMANCE_MAX');
        foreach ($dates as $date) {
            $this->insertCampaignDailyRow($date, 'camp-pmax', costMicros: 50_000_000, conversions: 2);
        }

        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');
        $campaign = $workspace['campaigns'][0];

        $this->assertSame('Performance Max', $campaign['type']);
        $this->assertTrue($campaign['is_pmax']);
        $this->assertSame('Unavailable', $campaign['pacing']);
        $this->assertNull($campaign['cpa']);
        $this->assertArrayNotHasKey('ad_group', $campaign);
        $this->assertSame(DataSourceState::Real->value, $workspace['data_provenance']['campaigns']);
    }

    #[Test]
    public function search_terms_are_provider_limited_and_pmax_terms_carry_no_fabricated_ad_group(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('google_ads_search_term_daily', $dates);
        foreach ($dates as $date) {
            $this->insertSearchTermDailyRow($date, 'best dental implants', costMicros: 10_000_000, conversions: 1, isPmax: true);
        }

        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');
        $term = $workspace['search']['terms'][0];

        $this->assertSame('PROVIDER_LIMITED', $workspace['data_provenance']['search.terms']);
        $this->assertSame('PROVIDER_LIMITED', $term['completeness']);
        $this->assertNull($term['ad_group']);
        $this->assertTrue($term['is_pmax']);
        $this->assertStringContainsString('not market search volume', $term['search_term_note']);
        $this->assertStringContainsString('Search term ≠ keyword', $term['keyword_distinction_note']);
    }

    #[Test]
    public function keywords_preserve_match_type_and_are_not_merged_with_search_terms(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('google_ads_keyword_daily', $dates);
        $this->insertKeywordSnapshot('kw-1', 'dental implants turkey', 'EXACT');
        foreach ($dates as $date) {
            $this->insertKeywordDailyRow($date, 'kw-1', costMicros: 5_000_000, conversions: 1);
        }

        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');
        $keyword = $workspace['search']['keywords'][0];

        $this->assertSame('Exact', $keyword['match']);
        $this->assertSame('PROVIDER_LIMITED', $workspace['data_provenance']['search.keywords']);
        $this->assertTrue($keyword['keyword_neq_search_term']);
    }

    #[Test]
    public function measurement_matrix_keeps_conversions_and_all_conversions_distinct(): void
    {
        $this->seedSnapshotReady('google_ads_conversion_action_snapshot');
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('google_ads_conversion_action_daily', $dates);

        DB::table('google_ads_conversion_action_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'conversion_action_id' => 'ca-1',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/London',
            'record_fingerprint' => hash('sha256', 'ca-1'),
            'metadata' => json_encode([
                'name' => 'Lead form submit',
                'category' => 'SUBMIT_LEAD_FORM',
                'type' => 'WEBPAGE',
                'status' => 'ENABLED',
                'primary_for_goal' => true,
                'include_in_conversions_metric' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($dates as $date) {
            DB::table('google_ads_conversion_action_daily')->insert([
                'digital_asset_id' => $this->asset->id,
                'external_resource_id' => $this->resource->id,
                'customer_id' => '1112223333',
                'reporting_date' => $date,
                'conversion_action_id' => 'ca-1',
                'conversions' => 2,
                'conversions_value' => 100.0,
                'all_conversions' => 5,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'Europe/London',
                'record_fingerprint' => hash('sha256', 'ca-1-'.$date),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');
        $row = $workspace['measurement']['matrix'][0];

        $this->assertSame('Lead form submit', $row['action']);
        $this->assertSame('Primary', $row['role']);
        $this->assertSame(56.0, $row['conversions']);
        $this->assertSame(140.0, $row['all_conversions']);
        $this->assertNotSame($row['conversions'], $row['all_conversions']);
    }

    #[Test]
    public function unreadiness_does_not_present_synthetic_zero_glance_values(): void
    {
        $demoBaseline = GoogleAdsWorkspaceFixtures::workspace('last_28');
        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame('real', $workspace['migration_mode']);
        $this->assertSame('—', $workspace['glance']['spend']['value']);
        $this->assertNull($workspace['glance']['spend']['raw']);
        $this->assertNotSame($demoBaseline['campaigns'][0]['spend'], $workspace['glance']['spend']['raw']);
        $this->assertNotSame(0, $workspace['glance']['spend']['raw']);
        $this->assertStringContainsString('Unavailable ≠ zero', $workspace['glance']['spend']['note'] ?? '');
        $this->assertSame([], $workspace['campaigns']);
        $this->assertSame([], $workspace['ads']['rows']);
    }

    #[Test]
    public function exception_path_returns_unavailable_operational_workspace_without_demo_fallback(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('google_ads_account_daily', $dates);

        $pool = $this->mock(GoogleAdsPoolReadRepository::class)->makePartial();
        $pool->shouldReceive('accountDailySums')->andThrow(new RuntimeException('simulated read failure'));
        $this->app->forgetInstance(GoogleAdsSpecialistReadService::class);

        $demoBaseline = GoogleAdsWorkspaceFixtures::workspace('last_28');
        $workspace = app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame('real', $workspace['migration_mode']);
        $this->assertNull($workspace['glance']['spend']['raw']);
        $this->assertNotSame($demoBaseline['campaigns'][0]['spend'], $workspace['glance']['spend']['raw']);
        $this->assertStringContainsString('read error', strtolower($workspace['identity']['title']));
        foreach ($workspace['data_provenance'] as $state) {
            $this->assertSame(DataSourceState::Unavailable->value, $state);
        }
    }

    #[Test]
    public function workspace_build_creates_no_evidence_or_findings(): void
    {
        $beforeEvidence = Evidence::query()->count();
        $beforeFindings = Finding::query()->count();

        app(GoogleAdsSpecialistReadService::class)->workspace(DemoCatalog::GOOGLE_ADS_ASSET_ID);
        app(GoogleAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame($beforeEvidence, Evidence::query()->count());
        $this->assertSame($beforeFindings, Finding::query()->count());
    }

    /**
     * @param  list<string>  $dates
     */
    private function seedDatasetReady(string $datasetId, array $dates): void
    {
        $this->materializationWithDates($datasetId, $dates);
        $this->seedIntegrityCheck($datasetId, IntegrityCheckStatus::Pass);
    }

    private function seedSnapshotReady(string $datasetId): void
    {
        DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC'),
            'coverage_start_date' => null,
            'coverage_end_date' => null,
            'row_count_approx' => 1,
            'row_count_semantics' => 'exact',
            'partial' => false,
            'freshness_metadata' => [],
        ]);
        $this->seedIntegrityCheck($datasetId, IntegrityCheckStatus::Pass);
    }

    /**
     * @param  list<string>  $dates
     */
    private function materializationWithDates(string $datasetId, array $dates): DatasetMaterialization
    {
        $dates = array_values(array_unique($dates));
        sort($dates);
        $set = CoverageIntervalSet::fromSuccessfulDates($dates);

        return DatasetMaterialization::query()->create([
            'dataset_id' => $datasetId,
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'last_collected_at' => CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC'),
            'coverage_start_date' => $set->bounds()['start'],
            'coverage_end_date' => $set->bounds()['end'],
            'row_count_approx' => 0,
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
    }

    private function seedIntegrityCheck(
        string $datasetId,
        IntegrityCheckStatus $status,
        bool $blocksMigration = false,
    ): DataIntegrityAuditRun {
        $run = DataIntegrityAuditRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'status' => IntegrityAuditStatus::Completed,
            'mode' => IntegrityAuditMode::LocalIntegrity,
            'scope_type' => 'dataset',
            'scope' => ['dataset_id' => $datasetId],
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
            'audit_run_id' => $run->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'dataset_id' => $datasetId,
            'check_id' => 'natural_key_uniqueness',
            'category' => 'integrity',
            'severity' => 'info',
            'status' => $status,
            'message' => 'test check',
            'blocks_migration' => $blocksMigration,
        ]);

        return $run;
    }

    /**
     * @param  list<string>  $dates
     */
    private function insertAccountDailyRows(array $dates, int $costMicros, int $conversions): void
    {
        foreach ($dates as $date) {
            DB::table('google_ads_account_daily')->insert([
                'digital_asset_id' => $this->asset->id,
                'external_resource_id' => $this->resource->id,
                'customer_id' => '1112223333',
                'reporting_date' => $date,
                'impressions' => 1000,
                'clicks' => 100,
                'cost_micros' => $costMicros,
                'cost_amount' => $costMicros / 1_000_000,
                'conversions' => $conversions,
                'currency' => 'GBP',
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'Europe/London',
                'record_fingerprint' => hash('sha256', 'account-'.$date),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertCampaignSnapshot(string $campaignId, string $name, string $channelType): void
    {
        DB::table('google_ads_campaign_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'campaign_id' => $campaignId,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/London',
            'record_fingerprint' => hash('sha256', 'campaign-'.$campaignId),
            'metadata' => json_encode([
                'name' => $name,
                'status' => 'ENABLED',
                'advertising_channel_type' => $channelType,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCampaignDailyRow(string $date, string $campaignId, int $costMicros, int $conversions): void
    {
        DB::table('google_ads_campaign_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'reporting_date' => $date,
            'campaign_id' => $campaignId,
            'impressions' => 500,
            'clicks' => 50,
            'cost_micros' => $costMicros,
            'cost_amount' => $costMicros / 1_000_000,
            'conversions' => $conversions,
            'currency' => 'GBP',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/London',
            'record_fingerprint' => hash('sha256', 'campaign-'.$campaignId.'-'.$date),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertKeywordSnapshot(string $criterionId, string $keyword, string $matchType): void
    {
        DB::table('google_ads_keyword_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'criterion_id' => $criterionId,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/London',
            'record_fingerprint' => hash('sha256', 'kw-'.$criterionId),
            'metadata' => json_encode([
                'keyword_text' => $keyword,
                'match_type' => $matchType,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertKeywordDailyRow(string $date, string $criterionId, int $costMicros, int $conversions): void
    {
        DB::table('google_ads_keyword_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'reporting_date' => $date,
            'criterion_id' => $criterionId,
            'impressions' => 200,
            'clicks' => 20,
            'cost_micros' => $costMicros,
            'cost_amount' => $costMicros / 1_000_000,
            'conversions' => $conversions,
            'currency' => 'GBP',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/London',
            'record_fingerprint' => hash('sha256', 'keyword-'.$criterionId.'-'.$date),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertSearchTermDailyRow(string $date, string $term, int $costMicros, int $conversions, bool $isPmax): void
    {
        DB::table('google_ads_search_term_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'customer_id' => '1112223333',
            'reporting_date' => $date,
            'search_term' => $term,
            'impressions' => 300,
            'clicks' => 30,
            'cost_micros' => $costMicros,
            'cost_amount' => $costMicros / 1_000_000,
            'conversions' => $conversions,
            'currency' => 'GBP',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/London',
            'record_fingerprint' => hash('sha256', 'term-'.$term.'-'.$date),
            'metadata' => json_encode([
                'source_view' => $isPmax ? 'campaign_search_term_view' : 'search_term_view',
                'contexts' => [
                    [
                        'campaign_id' => 'camp-pmax',
                        'ad_group_id' => $isPmax ? null : 'ag-1',
                        'advertising_channel_type' => $isPmax ? 'PERFORMANCE_MAX' : 'SEARCH',
                    ],
                ],
                'provider_may_omit_terms' => true,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
