<?php

namespace Tests\Feature\Ga4;

use App\Enums\DataPool\DataSourceState;
use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Assets\AnalyticsPage;
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
use App\Services\Formulas\Ga4FormulaCalculator;
use App\Services\Ga4\Ga4PoolReadRepository;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Ga4\Ga4SpecialistReadService;
use App\Services\Ga4\Ga4UiDatasetGate;
use App\Services\Ga4\Support\Ga4BindingMode;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\Ga4WorkspaceFixtures;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\Carbon;
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

class Ga4RealDataMigrationTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $asset;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse(DemoPeriod::ANCHOR_DATE, DemoPeriod::TIMEZONE)->endOfDay());

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
            'type' => 'ga4',
            'module_id' => 'ga4',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [GoogleScopes::ANALYTICS_READONLY],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'ga4-access-token',
                'refresh_token' => 'ga4-refresh-token',
                'scope' => GoogleScopes::ANALYTICS_READONLY,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
            'external_id' => 'properties/123456',
            'display_name' => 'Bound GA4 Property',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'timezone' => 'Europe/Berlin',
            ],
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => Ga4SpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function data_source_state_enum_has_shared_taxonomy_and_helpers(): void
    {
        $cases = DataSourceState::cases();
        $values = array_map(static fn (DataSourceState $c): string => $c->value, $cases);

        $this->assertSame([
            'REAL',
            'REAL_DERIVED',
            'PARTIAL_REAL',
            'DEMO',
            'UNAVAILABLE',
            'PROVIDER_LIMITED',
            'STALE_REAL',
        ], $values);

        $this->assertTrue(DataSourceState::Real->isReal());
        $this->assertTrue(DataSourceState::RealDerived->isReal());
        $this->assertTrue(DataSourceState::PartialReal->isReal());
        $this->assertTrue(DataSourceState::StaleReal->isReal());
        $this->assertFalse(DataSourceState::Demo->isReal());
        $this->assertFalse(DataSourceState::Unavailable->isReal());

        $this->assertTrue(DataSourceState::Real->isTrustedPresentation());
        $this->assertTrue(DataSourceState::ProviderLimited->isTrustedPresentation());
        $this->assertFalse(DataSourceState::Demo->isTrustedPresentation());
        $this->assertFalse(DataSourceState::Unavailable->isTrustedPresentation());

        $this->assertTrue(DataSourceState::Demo->isDemo());
        $this->assertTrue(DataSourceState::Unavailable->isUnavailable());
        $this->assertTrue(DataSourceState::ProviderLimited->isProviderLimited());
        $this->assertTrue(DataSourceState::StaleReal->isStaleReal());
    }

    #[Test]
    public function demo_catalog_asset_uses_fixtures_with_demo_provenance(): void
    {
        $workspace = app(Ga4SpecialistReadService::class)->workspace(DemoCatalog::GA4_ASSET_ID);

        $this->assertSame('demo_catalog', $workspace['migration_mode']);
        $this->assertSame(
            Ga4WorkspaceFixtures::workspace('last_28')['glance']['sessions']['raw'],
            $workspace['glance']['sessions']['raw'],
        );
        foreach ($workspace['data_provenance'] as $field => $state) {
            $this->assertSame(DataSourceState::Demo->value, $state, "Field {$field} should be DEMO");
        }
        foreach ($workspace['tab_status'] as $status) {
            $this->assertSame('DEMO', $status);
        }
    }

    #[Test]
    public function binding_resolver_without_binding_is_not_connected_and_never_picks_arbitrary_property(): void
    {
        $unbound = DigitalAsset::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'type' => 'ga4',
            'status' => DigitalAssetStatus::Active,
        ]);

        $otherResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
            'external_id' => 'properties/999999',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $resolver = app(Ga4SpecialistBindingResolver::class);
        $context = $resolver->resolve((string) $unbound->id);

        $this->assertSame(Ga4BindingMode::NotConnected, $context->mode);
        $this->assertNull($context->propertyId);
        $this->assertNotSame('999999', $context->propertyId);

        $workspace = app(Ga4SpecialistReadService::class)->workspace((string) $unbound->id);
        $this->assertSame('not_connected', $workspace['migration_mode']);
        $this->assertNull($workspace['identity']['property_id']);
        $this->assertSame('—', $workspace['glance']['sessions']['value']);

        $this->assertDatabaseMissing('core_asset_bindings', [
            'digital_asset_id' => $unbound->id,
            'external_resource_id' => $otherResource->id,
        ]);
    }

    #[Test]
    public function binding_resolver_uses_human_confirmed_core_asset_binding_property(): void
    {
        $altResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
            'external_id' => 'properties/777777',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $context = app(Ga4SpecialistBindingResolver::class)->resolve((string) $this->asset->id);

        $this->assertSame(Ga4BindingMode::RealBound, $context->mode);
        $this->assertSame('123456', $context->propertyId);
        $this->assertSame($this->binding->id, $context->coreAssetBindingId);
        $this->assertNotSame($altResource->id, $context->externalResourceId);
    }

    #[Test]
    public function users_are_not_summed_into_period_kpi(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('ga4_property_daily', $dates);
        $this->insertPropertyDailyRows($dates, sessions: 50, activeUsers: 10);

        $workspace = app(Ga4SpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertNull($workspace['glance']['users']['raw']);
        $this->assertSame('—', $workspace['glance']['users']['value']);
        $this->assertStringContainsString('not additive', $workspace['glance']['users']['secondary']);
        $this->assertSame(DataSourceState::Unavailable->value, $workspace['data_provenance']['glance.users']);

        $this->assertSame(1400, $workspace['glance']['sessions']['raw']);
        $this->assertNotSame(280, $workspace['glance']['users']['raw']);
    }

    #[Test]
    public function session_acquisition_uses_session_default_channel_group_dataset(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('ga4_property_daily', $dates);
        $this->seedDatasetReady('ga4_acquisition_channel_daily', $dates);
        $this->insertPropertyDailyRows($dates, sessions: 10);
        $this->insertAcquisitionChannelRows($dates, 'Organic Search', sessions: 40);

        $workspace = app(Ga4SpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');
        $channels = $workspace['acquisition']['channels'];

        $this->assertNotEmpty($channels);
        $this->assertSame('Organic Search', $channels[0]['channel']);
        $this->assertSame(1120, $channels[0]['sessions']);

        $this->assertDatabaseMissing('ga4_acquisition_channel_daily', [
            'sessionDefaultChannelGroup' => 'firstUserDefaultChannelGroup',
        ]);
    }

    #[Test]
    public function formula_engagement_rate_uses_sum_over_sum_not_average_of_rates(): void
    {
        $calculator = app(Ga4FormulaCalculator::class);

        $aggregate = $calculator->engagementRate(70, 100);
        $this->assertSame(0.7, $aggregate->toDisplay());

        $dayOneRate = $calculator->engagementRate(10, 100)->toDisplay();
        $dayTwoRate = $calculator->engagementRate(60, 100)->toDisplay();
        $blindAverage = ($dayOneRate + $dayTwoRate) / 2;

        $this->assertNotSame($blindAverage, $aggregate->toDisplay());
        $this->assertSame(0.7, $aggregate->toDisplay());
    }

    #[Test]
    public function unverified_integrity_dataset_is_not_presented_as_real(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->materializationWithDates('ga4_property_daily', $dates);
        $this->insertPropertyDailyRows($dates, sessions: 99);

        $workspace = app(Ga4SpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame(DataSourceState::Unavailable->value, $workspace['data_provenance']['glance.sessions']);
        $this->assertNotSame(2772, $workspace['glance']['sessions']['raw']);
        $this->assertStringContainsString('unavailable', strtolower($workspace['glance']['sessions']['note'] ?? ''));
    }

    #[Test]
    public function integrity_blocked_dataset_is_not_presented_as_real(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->materializationWithDates('ga4_property_daily', $dates);
        $this->seedIntegrityCheck('ga4_property_daily', IntegrityCheckStatus::Fail, blocksMigration: true);
        $this->insertPropertyDailyRows($dates, sessions: 99);

        $workspace = app(Ga4SpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame(DataSourceState::Unavailable->value, $workspace['data_provenance']['glance.sessions']);
        $this->assertNotSame(2772, $workspace['glance']['sessions']['raw']);
        $this->assertStringContainsString('unavailable', strtolower($workspace['glance']['sessions']['note'] ?? ''));
        $this->assertStringContainsString('unavailable', strtolower($workspace['glance']['sessions']['note'] ?? ''));
    }

    #[Test]
    public function exception_path_returns_unavailable_operational_workspace_without_demo_fallback(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('ga4_property_daily', $dates);

        $pool = $this->mock(Ga4PoolReadRepository::class)->makePartial();
        $pool->shouldReceive('propertyDailySums')->andThrow(new RuntimeException('simulated read failure'));
        $this->app->forgetInstance(Ga4SpecialistReadService::class);

        $demoBaseline = Ga4WorkspaceFixtures::workspace('last_28');
        $workspace = app(Ga4SpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame('real', $workspace['migration_mode']);
        $this->assertNull($workspace['glance']['sessions']['raw']);
        $this->assertNotSame($demoBaseline['glance']['sessions']['raw'], $workspace['glance']['sessions']['raw']);
        $this->assertStringContainsString('read error', strtolower($workspace['identity']['title']));
        foreach ($workspace['data_provenance'] as $state) {
            $this->assertSame(DataSourceState::Unavailable->value, $state);
        }
    }

    #[Test]
    public function analytics_page_render_makes_zero_http_calls_for_demo_asset(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        Livewire::test(AnalyticsPage::class, ['assetId' => (string) $this->asset->id])
            ->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function workspace_build_creates_no_evidence_or_findings(): void
    {
        $beforeEvidence = Evidence::query()->count();
        $beforeFindings = Finding::query()->count();

        app(Ga4SpecialistReadService::class)->workspace(DemoCatalog::GA4_ASSET_ID);
        app(Ga4SpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame($beforeEvidence, Evidence::query()->count());
        $this->assertSame($beforeFindings, Finding::query()->count());
    }

    #[Test]
    public function frozen_allowed_tabs_list_is_unchanged(): void
    {
        $component = new AnalyticsPage;
        $this->assertSame([
            'overview',
            'measurement',
            'acquisition',
            'behavior',
            'journeys',
            'operations',
        ], $component->allowedTabs);
    }

    #[Test]
    public function ui_dataset_gate_maps_partial_coverage_to_partial_real(): void
    {
        $dates = array_merge(
            $this->contiguousDates('2026-07-16', 10),
            $this->contiguousDates('2026-07-27', 5),
        );
        $this->materializationWithDates('ga4_property_daily', $dates);
        $this->seedIntegrityCheck('ga4_property_daily', IntegrityCheckStatus::Pass);

        $gate = app(Ga4UiDatasetGate::class)->evaluate(
            $this->asset->id,
            $this->resource->id,
            'ga4_property_daily',
            '2026-07-16',
            '2026-08-12',
            'Europe/Berlin',
        );

        $this->assertTrue($gate->isUsable());
        $this->assertSame(DataSourceState::PartialReal, $gate->dataSourceState());
    }

    /**
     * @param  list<string>  $dates
     */
    private function seedDatasetReady(string $datasetId, array $dates): void
    {
        $this->materializationWithDates($datasetId, $dates);
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
            'provider_or_source' => 'GA4',
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
            'provider_or_source' => 'GA4',
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
    private function insertPropertyDailyRows(array $dates, int $sessions = 50, int $activeUsers = 10): void
    {
        foreach ($dates as $date) {
            DB::table('ga4_property_daily')->insert([
                'digital_asset_id' => $this->asset->id,
                'external_resource_id' => $this->resource->id,
                'property_id' => '123456',
                'reporting_date' => $date,
                'sessions' => $sessions,
                'engagedSessions' => (int) round($sessions * 0.7),
                'screenPageViews' => $sessions * 2,
                'userEngagementDuration' => 120.0,
                'totalUsers' => $activeUsers,
                'activeUsers' => $activeUsers,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'Europe/Berlin',
                'record_fingerprint' => hash('sha256', 'property-'.$date),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<string>  $dates
     */
    private function insertAcquisitionChannelRows(array $dates, string $channel, int $sessions): void
    {
        foreach ($dates as $date) {
            DB::table('ga4_acquisition_channel_daily')->insert([
                'digital_asset_id' => $this->asset->id,
                'external_resource_id' => $this->resource->id,
                'property_id' => '123456',
                'reporting_date' => $date,
                'sessionDefaultChannelGroup' => $channel,
                'sessions' => $sessions,
                'engagedSessions' => (int) round($sessions * 0.6),
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'Europe/Berlin',
                'record_fingerprint' => hash('sha256', 'channel-'.$date),
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
