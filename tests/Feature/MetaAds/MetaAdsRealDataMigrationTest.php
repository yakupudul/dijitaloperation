<?php

namespace Tests\Feature\MetaAds;

use App\Enums\DataPool\DataSourceState;
use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Meta\OverviewPage;
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
use App\Models\User;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\MetaAds\MetaAdsPoolReadRepository;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaAdsSpecialistReadService;
use App\Services\MetaAds\Support\MetaAdsBindingMode;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\MetaAdsWorkspaceFixtures;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class MetaAdsRealDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const string ACCOUNT_ID = '11110001';

    private DigitalAsset $asset;

    private CoreIntegration $integration;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        $this->seed(RoleAndPermissionSeeder::class);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'auth_method' => 'oauth',
                'auth_status' => 'connected',
                'connection_status' => 'connected',
                'credential_status' => 'valid',
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'EAAG-synthetic-meta-token-never-real',
                'granted_permissions' => ['ads_read', 'business_management'],
            ],
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_'.self::ACCOUNT_ID,
            'display_name' => 'Bound Meta Ad Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
                'account_status' => 1,
            ],
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => MetaAdsSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function demo_catalog_asset_uses_fixtures_with_demo_provenance(): void
    {
        $workspace = app(MetaAdsSpecialistReadService::class)->workspace(DemoCatalog::META_ASSET_ID);

        $this->assertSame('demo_catalog', $workspace['migration_mode']);
        $this->assertSame(
            MetaAdsWorkspaceFixtures::workspace('last_28')['campaigns'][0]['spend'],
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
    public function binding_resolver_without_binding_is_not_connected_and_never_picks_arbitrary_account(): void
    {
        $unbound = DigitalAsset::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'type' => 'meta_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $otherResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_99988877',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $context = app(MetaAdsSpecialistBindingResolver::class)->resolve((string) $unbound->id);

        $this->assertSame(MetaAdsBindingMode::NotConnected, $context->mode);
        $this->assertNull($context->accountId);
        $this->assertNotSame('99988877', $context->accountId);

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $unbound->id);
        $this->assertSame('not_connected', $workspace['migration_mode']);
        $this->assertSame('—', $workspace['glance']['spend']['value']);

        $this->assertDatabaseMissing('core_asset_bindings', [
            'digital_asset_id' => $unbound->id,
            'external_resource_id' => $otherResource->id,
        ]);
    }

    #[Test]
    public function business_resource_is_rejected_as_analytical_root(): void
    {
        $this->resource->forceFill([
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_999',
        ])->save();

        $context = app(MetaAdsSpecialistBindingResolver::class)->resolve((string) $this->asset->id);

        $this->assertSame(MetaAdsBindingMode::ActionRequired, $context->mode);
        $this->assertSame('meta_business_not_analytical_root', $context->reason);

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id);
        $this->assertSame('not_connected', $workspace['migration_mode']);
        $this->assertSame('Action required', $workspace['identity']['status']);
    }

    #[Test]
    public function campaign_spend_comes_from_campaign_daily_not_typed_action_fanout(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_campaign_daily', $dates);
        foreach ($dates as $date) {
            $this->insertCampaignDailyRow($date, 'camp-1', spend: 100.0, impressions: 1000, clicks: 50);
        }

        // Typed actions must never fan spend out — they have no spend column at all,
        // but seed a huge action_value to prove the calculator never mixes it into spend.
        $this->seedDatasetReady('meta_typed_action_daily', $dates);
        foreach ($dates as $date) {
            $this->insertTypedActionRow($date, 'camp-1', 'lead', 999_999.0);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame(2800.0, $workspace['glance']['spend']['raw']);
        $this->assertNotSame(999_999.0 * 28, $workspace['glance']['spend']['raw']);
    }

    #[Test]
    public function large_spend_is_not_treated_as_google_ads_micros(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_campaign_daily', $dates);
        foreach ($dates as $date) {
            // A large major-currency spend value — if this code ever divided by 1e6
            // (a Google Ads micros assumption), this would collapse to ~0.0056.
            $this->insertCampaignDailyRow($date, 'camp-1', spend: 5600.0, impressions: 1000, clicks: 50);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame(156800.0, $workspace['glance']['spend']['raw']);
        $this->assertGreaterThan(1.0, $workspace['glance']['spend']['raw']);
    }

    #[Test]
    public function period_reach_is_never_summed_from_daily_rows(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_campaign_daily', $dates);
        foreach ($dates as $date) {
            $this->insertCampaignDailyRow($date, 'camp-1', spend: 50.0, impressions: 1000, clicks: 50, reach: 800);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $campaign = $workspace['campaigns'][0];
        $this->assertNull($campaign['reach']);
        $this->assertNotSame(800 * 28, $campaign['reach']);
        $this->assertArrayNotHasKey('reach', $workspace['glance']);
        $this->assertStringContainsString('never be summed', $campaign['reach_note']);
    }

    #[Test]
    public function frequency_is_never_averaged_into_a_period_value(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_campaign_daily', $dates);
        foreach ($dates as $date) {
            $this->insertCampaignDailyRow($date, 'camp-1', spend: 50.0, impressions: 1000, clicks: 50, reach: 800, frequency: 1.25);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $campaign = $workspace['campaigns'][0];
        $this->assertNull($campaign['frequency']);
        $this->assertStringContainsString('never be averaged', $campaign['frequency_note']);
    }

    #[Test]
    public function clicks_and_link_clicks_are_kept_distinct(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_campaign_daily', $dates);
        foreach ($dates as $date) {
            $this->insertCampaignDailyRow($date, 'camp-1', spend: 50.0, impressions: 1000, clicks: 90, linkClicks: 40);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $campaign = $workspace['campaigns'][0];
        $this->assertSame(40 * 28, $campaign['link_clicks']);
        $this->assertNotSame($campaign['link_clicks'], 90 * 28);
        $this->assertStringContainsString('distinct', $campaign['clicks_note']);
    }

    #[Test]
    public function keyword_and_search_term_concepts_are_not_applicable_to_meta_ads(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_campaign_daily', $dates);
        foreach ($dates as $date) {
            $this->insertCampaignDailyRow($date, 'camp-1', spend: 10.0, impressions: 100, clicks: 5);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertArrayNotHasKey('search', $workspace);
        $this->assertArrayNotHasKey('keyword', $workspace['campaigns'][0]);
        $this->assertArrayNotHasKey('search_term', $workspace['campaigns'][0]);
    }

    #[Test]
    public function typed_actions_retain_action_type_and_generic_results_stay_unavailable(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_typed_action_daily', $dates);
        foreach ($dates as $date) {
            $this->insertTypedActionRow($date, 'camp-1', 'lead', 2.0);
            $this->insertTypedActionRow($date, 'camp-1', 'purchase', 1.0);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $actionTypes = array_column($workspace['measurement']['matrix'], 'action_type');
        $this->assertContains('lead', $actionTypes);
        $this->assertContains('purchase', $actionTypes);
        $this->assertCount(2, $workspace['measurement']['matrix']);

        $this->assertSame(DataSourceState::Unavailable->value, $workspace['data_provenance']['glance.result_mix']);
        $this->assertStringContainsString('canonical typed-action', $workspace['result_mix']['note']);
    }

    #[Test]
    public function typed_action_note_says_action_is_not_automatically_a_qualified_lead(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_typed_action_daily', $dates);
        foreach ($dates as $date) {
            $this->insertTypedActionRow($date, 'camp-1', 'lead', 2.0);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $row = $workspace['measurement']['matrix'][0];
        $this->assertStringContainsString('not automatically a qualified lead', $row['note']);
    }

    #[Test]
    public function creative_reuse_across_multiple_ads_does_not_duplicate_spend(): void
    {
        $this->seedSnapshotReady('meta_creative_snapshot');
        $this->insertCreativeSnapshot('crv-1', 'Shared Creative');

        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_ad_daily', $dates);
        foreach ($dates as $date) {
            $this->insertAdDailyRow($date, 'ad-1', 'camp-1', 'crv-1', spend: 10.0, impressions: 500, clicks: 25);
            $this->insertAdDailyRow($date, 'ad-2', 'camp-1', 'crv-1', spend: 15.0, impressions: 700, clicks: 30);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $creative = collect($workspace['creatives']['gallery'])->firstWhere('id', 'crv-1');
        $this->assertNotNull($creative);
        $this->assertSame(700.0, $creative['spend']);
    }

    #[Test]
    public function country_breakdown_is_always_unavailable(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_delivery_breakdown_daily', $dates);
        foreach ($dates as $date) {
            $this->insertDeliveryBreakdownRow($date, 'age', '25-34', spend: 10.0);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame([], $workspace['audience']['country']);
        $this->assertSame(DataSourceState::Unavailable->value, $workspace['data_provenance']['audience.country']);
    }

    #[Test]
    public function age_gender_platform_breakdowns_are_real_when_seeded(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_delivery_breakdown_daily', $dates);
        foreach ($dates as $date) {
            $this->insertDeliveryBreakdownRow($date, 'age', '25-34', spend: 10.0);
            $this->insertDeliveryBreakdownRow($date, 'gender', 'female', spend: 6.0);
            $this->insertDeliveryBreakdownRow($date, 'publisher_platform', 'facebook', spend: 8.0);
        }

        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertNotEmpty($workspace['audience']['age']);
        $this->assertSame('25-34', $workspace['audience']['age'][0]['label']);
        $this->assertSame(280.0, $workspace['audience']['age'][0]['spend']);

        $this->assertNotEmpty($workspace['audience']['gender']);
        $this->assertSame('Female', $workspace['audience']['gender'][0]['label']);

        $this->assertNotEmpty($workspace['audience']['platform']);
        $this->assertSame('Facebook', $workspace['audience']['platform'][0]['label']);

        $this->assertSame(DataSourceState::Real->value, $workspace['data_provenance']['audience.age']);
        $this->assertSame(DataSourceState::Real->value, $workspace['data_provenance']['audience.gender']);
        $this->assertSame(DataSourceState::Real->value, $workspace['data_provenance']['audience.platform']);
    }

    #[Test]
    public function exception_path_returns_unavailable_operational_workspace_without_demo_fallback(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('meta_campaign_daily', $dates);

        $pool = $this->mock(MetaAdsPoolReadRepository::class)->makePartial();
        $pool->shouldReceive('campaignDailySums')->andThrow(new RuntimeException('simulated read failure'));
        $this->app->forgetInstance(MetaAdsSpecialistReadService::class);

        $demoBaseline = MetaAdsWorkspaceFixtures::workspace('last_28');
        $workspace = app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

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

        app(MetaAdsSpecialistReadService::class)->workspace(DemoCatalog::META_ASSET_ID);
        app(MetaAdsSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame($beforeEvidence, Evidence::query()->count());
        $this->assertSame($beforeFindings, Finding::query()->count());
    }

    #[Test]
    public function overview_page_render_makes_zero_http_calls_for_demo_asset(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        Livewire::test(OverviewPage::class, [
            'assetId' => DemoCatalog::META_ASSET_ID,
        ])->assertSee('Atlas Health');

        Http::assertNothingSent();
    }

    #[Test]
    public function frozen_allowed_tabs_list_is_unchanged(): void
    {
        $component = new OverviewPage;
        $this->assertSame([
            'overview',
            'campaigns',
            'creatives',
            'audience',
            'funnel',
            'measurement',
            'operations',
        ], $component->allowedTabs);
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
            'provider_or_source' => 'META_ADS',
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
            'provider_or_source' => 'META_ADS',
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
            'provider_or_source' => 'META_ADS',
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

    private function insertCampaignDailyRow(
        string $date,
        string $campaignId,
        float $spend,
        int $impressions,
        int $clicks,
        ?int $reach = null,
        ?float $frequency = null,
        ?int $linkClicks = null,
    ): void {
        DB::table('meta_campaign_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'account_id' => self::ACCOUNT_ID,
            'reporting_date' => $date,
            'campaign_id' => $campaignId,
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'reach' => $reach,
            'frequency' => $frequency,
            'currency' => 'EUR',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Berlin',
            'record_fingerprint' => hash('sha256', 'campaign-'.$campaignId.'-'.$date),
            'metadata' => json_encode([
                'inline_link_clicks' => $linkClicks,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertTypedActionRow(string $date, string $campaignId, string $actionType, float $actionValue): void
    {
        DB::table('meta_typed_action_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'account_id' => self::ACCOUNT_ID,
            'reporting_date' => $date,
            'entity_level' => 'campaign',
            'entity_id' => $campaignId,
            'action_type' => $actionType,
            'action_value' => $actionValue,
            'currency' => 'EUR',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Berlin',
            'record_fingerprint' => hash('sha256', 'action-'.$campaignId.'-'.$actionType.'-'.$date),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCreativeSnapshot(string $creativeId, string $name): void
    {
        DB::table('meta_creative_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'account_id' => self::ACCOUNT_ID,
            'creative_id' => $creativeId,
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Berlin',
            'record_fingerprint' => hash('sha256', 'creative-'.$creativeId),
            'metadata' => json_encode([
                'name' => $name,
                'object_type' => 'SHARE',
                'status' => 'ACTIVE',
                'title' => 'Headline',
                'body' => 'Body copy',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertAdDailyRow(
        string $date,
        string $adId,
        string $campaignId,
        ?string $creativeId,
        float $spend,
        int $impressions,
        int $clicks,
    ): void {
        DB::table('meta_ad_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'account_id' => self::ACCOUNT_ID,
            'reporting_date' => $date,
            'ad_id' => $adId,
            'spend' => $spend,
            'impressions' => $impressions,
            'clicks' => $clicks,
            'currency' => 'EUR',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Berlin',
            'record_fingerprint' => hash('sha256', 'ad-'.$adId.'-'.$date),
            'metadata' => json_encode([
                'campaign_id' => $campaignId,
                'creative_id' => $creativeId,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertDeliveryBreakdownRow(string $date, string $breakdownType, string $breakdownValue, float $spend): void
    {
        DB::table('meta_delivery_breakdown_daily')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'account_id' => self::ACCOUNT_ID,
            'reporting_date' => $date,
            'entity_id' => self::ACCOUNT_ID,
            'breakdown_type' => $breakdownType,
            'breakdown_value' => $breakdownValue,
            'spend' => $spend,
            'impressions' => 100,
            'clicks' => 5,
            'currency' => 'EUR',
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'Europe/Berlin',
            'record_fingerprint' => hash('sha256', 'breakdown-'.$breakdownType.'-'.$breakdownValue.'-'.$date),
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
