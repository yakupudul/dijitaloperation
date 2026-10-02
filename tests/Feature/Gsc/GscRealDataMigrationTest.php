<?php

namespace Tests\Feature\Gsc;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DataPool\IntegrityAuditMode;
use App\Enums\DataPool\IntegrityAuditStatus;
use App\Enums\DataPool\IntegrityCheckStatus;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Livewire\Demo\Assets\SearchConsolePage;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
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
use App\Services\DataPool\MaterializationService;
use App\Services\Formulas\GscFormulaCalculator;
use App\Services\Gsc\GscPoolReadRepository;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistReadService;
use App\Services\Gsc\Support\GscBindingMode;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\GscWorkspaceFixtures;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class GscRealDataMigrationTest extends TestCase
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
            'type' => 'gsc',
            'module_id' => 'search_console',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'granted_scopes' => [GoogleScopes::SEARCH_CONSOLE_READONLY],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'gsc-access-token',
                'refresh_token' => 'gsc-refresh-token',
                'scope' => GoogleScopes::SEARCH_CONSOLE_READONLY,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => 'sc-domain:example.com',
            'display_name' => 'Bound GSC Property',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'reporting_timezone' => 'America/Los_Angeles',
            ],
        ]);

        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'capability' => GscSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function catalog_string_ids_do_not_produce_fixture_backed_gsc_workspaces(): void
    {
        $workspace = app(GscSpecialistReadService::class)->workspace(DemoCatalog::GSC_ASSET_ID);
        $fixtureClicks = GscWorkspaceFixtures::workspace('last_28')['glance']['clicks']['raw'];

        $this->assertNotSame('demo_catalog', $workspace['migration_mode']);
        $this->assertNotSame($fixtureClicks, $workspace['glance']['clicks']['raw'] ?? null);
        $this->assertSame('—', $workspace['glance']['clicks']['value']);
        $this->assertNull($workspace['glance']['clicks']['raw']);
        $this->assertSame(18420, $fixtureClicks);
    }

    #[Test]
    public function binding_resolver_without_binding_is_not_connected_and_never_picks_arbitrary_property(): void
    {
        $unbound = DigitalAsset::factory()->create([
            'brand_id' => $this->asset->brand_id,
            'type' => 'gsc',
            'status' => DigitalAssetStatus::Active,
        ]);

        $otherResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => 'sc-domain:other.example',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $context = app(GscSpecialistBindingResolver::class)->resolve((string) $unbound->id);

        $this->assertSame(GscBindingMode::NotConnected, $context->mode);
        $this->assertNull($context->siteUrl);
        $this->assertNotSame('sc-domain:other.example', $context->siteUrl);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $unbound->id);
        $this->assertSame('not_connected', $workspace['migration_mode']);
        $this->assertNull($workspace['identity']['property_label']);
        $this->assertSame('—', $workspace['glance']['clicks']['value']);

        $this->assertDatabaseMissing('core_asset_bindings', [
            'digital_asset_id' => $unbound->id,
            'external_resource_id' => $otherResource->id,
        ]);
    }

    #[Test]
    public function binding_resolver_uses_human_confirmed_core_asset_binding_site_url(): void
    {
        $altResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->resource->integration_id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => 'sc-domain:alt.example',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $context = app(GscSpecialistBindingResolver::class)->resolve((string) $this->asset->id);

        $this->assertSame(GscBindingMode::RealBound, $context->mode);
        $this->assertSame('sc-domain:example.com', $context->siteUrl);
        $this->assertSame($this->binding->id, $context->coreAssetBindingId);
        $this->assertNotSame($altResource->id, $context->externalResourceId);
    }

    #[Test]
    public function property_totals_come_from_property_daily_not_query_sums(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);
        $this->insertPropertyDailyRows($dates, clicks: 100, impressions: 1000, position: 5.0);

        foreach ($dates as $date) {
            DB::table('gsc_query_daily')->insert([
                'digital_asset_id' => $this->asset->id,
                'external_resource_id' => $this->resource->id,
                'site_url' => 'sc-domain:example.com',
                'reporting_date' => $date,
                'query' => 'high volume query',
                'clicks' => 9999,
                'impressions' => 99999,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'America/Los_Angeles',
                'record_fingerprint' => hash('sha256', 'query-'.$date),
                'metadata' => json_encode(['provider_average_position' => 1.0]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame(2800, $workspace['glance']['clicks']['raw']);
        $this->assertSame(28000, $workspace['glance']['impressions']['raw']);
        $this->assertNotSame(2800 * 9999 / 100, $workspace['glance']['clicks']['raw']);
    }

    #[Test]
    public function ctr_formula_uses_sum_over_sum_not_average_of_daily_ctrs(): void
    {
        $calculator = app(GscFormulaCalculator::class);

        $aggregate = $calculator->ctr(30, 150);
        $this->assertSame(0.2, $aggregate->toDisplay());

        $dayOne = $calculator->ctr(10, 100)->toDisplay();
        $dayTwo = $calculator->ctr(20, 50)->toDisplay();
        $blindAverage = ($dayOne + $dayTwo) / 2;

        $this->assertNotSame($blindAverage, $aggregate->toDisplay());
        $this->assertSame(0.2, $aggregate->toDisplay());
    }

    #[Test]
    public function position_is_impression_weighted_not_blind_average_and_carries_provenance_note(): void
    {
        $calculator = app(GscFormulaCalculator::class);

        $weighted = $calculator->impressionWeightedPosition(50.0, 100);
        $this->assertSame(0.5, $weighted->toDisplay());

        $blindAverage = (10.0 + 2.0) / 2;
        $this->assertNotSame($blindAverage, $weighted->toDisplay());

        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);
        $this->insertPropertyDailyRows($dates, clicks: 10, impressions: 100, position: 8.0);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertStringContainsString('not an exact SERP rank', $workspace['glance']['clicks']['position_note'] ?? '');
        $this->assertSame(8.0, $workspace['glance']['clicks']['avg_position']);
    }

    #[Test]
    public function zero_impression_day_is_a_chart_gap_not_position_or_ctr_zero(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);
        $this->insertPropertyDailyRows(array_slice($dates, 1), clicks: 10, impressions: 100, position: 8.0);
        $this->insertPropertyDailyRows([$dates[0]], clicks: 0, impressions: 0, position: 0.0);

        $series = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28')['metric_series'];

        $this->assertNull($series['position'][0]);
        $this->assertNull($series['ctr'][0]);
        $this->assertSame(8.0, $series['position'][1]);
        $this->assertNotContains(0.0, $series['position']);
    }

    #[Test]
    public function impressions_are_labeled_honestly_not_as_search_volume(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);
        $this->insertPropertyDailyRows($dates);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertStringContainsString('not search volume', strtolower($workspace['glance']['impressions']['secondary'] ?? ''));
        $this->assertStringContainsString('not an exhaustive keyword universe', strtolower($workspace['demand']['observed_query_note'] ?? ''));
    }

    #[Test]
    public function query_rows_are_provider_limited_when_real(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);
        $this->seedDatasetReady('gsc_query_daily', $dates);
        $this->insertPropertyDailyRows($dates);
        $this->insertQueryDailyRows($dates, 'observed query', clicks: 5, impressions: 50);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame('PROVIDER_LIMITED', $workspace['data_provenance']['demand.queries']);
        $this->assertSame('PROVIDER_LIMITED', $workspace['demand']['queries'][0]['completeness'] ?? null);
    }

    #[Test]
    public function indexing_site_wide_coverage_is_unavailable_when_real_bound(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);
        $this->insertPropertyDailyRows($dates);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame('Unavailable', $workspace['indexing']['coverage']['state'] ?? null);
        $this->assertNull($workspace['indexing']['coverage']['indexed']);
        $this->assertSame('UNAVAILABLE', $workspace['data_provenance']['indexing.coverage']);
        $this->assertNull($workspace['indexing']['reconciliation']['index_observed']);
    }

    #[Test]
    public function sitemap_metadata_notes_submitted_is_not_indexed(): void
    {
        $this->seedSnapshotReady('gsc_sitemap_snapshot');
        DB::table('gsc_sitemap_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'site_url' => 'sc-domain:example.com',
            'sitemap_path' => '/sitemap.xml',
            'retrieved_at' => now(),
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'America/Los_Angeles',
            'record_fingerprint' => hash('sha256', 'sitemap'),
            'metadata' => json_encode([
                'last_submitted' => '2026-08-01',
                'last_downloaded' => '2026-08-12',
                'warnings' => 0,
                'errors' => 0,
                'contents' => [['type' => 'web', 'submitted' => 42]],
                'submitted_not_indexed' => true,
                'deprecated_indexed_used' => false,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);
        $this->insertPropertyDailyRows($dates);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');
        $sitemap = $workspace['indexing']['sitemaps'][0] ?? null;

        $this->assertNotNull($sitemap);
        $this->assertStringContainsString('Submitted URL count ≠ indexed', $sitemap['note'] ?? '');
        $this->assertSame(42, $sitemap['discovered']);
    }

    #[Test]
    public function url_inspection_rows_are_sample_only_not_extrapolated(): void
    {
        $this->seedSnapshotReady('gsc_url_inspection_snapshot');
        DB::table('gsc_url_inspection_snapshot')->insert([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $this->resource->id,
            'site_url' => 'sc-domain:example.com',
            'page' => 'https://example.com/page-a',
            'inspected_at' => now(),
            'contract_version' => 1,
            'first_collected_at' => now(),
            'last_collected_at' => now(),
            'source_timezone' => 'America/Los_Angeles',
            'record_fingerprint' => hash('sha256', 'inspect'),
            'metadata' => json_encode([
                'coverage_state' => 'Indexed',
                'user_canonical' => 'https://example.com/user',
                'google_canonical' => 'https://example.com/google',
                'provider_completeness' => 'CONTROLLED_SAMPLE_ONLY',
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);
        $this->insertPropertyDailyRows($dates);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');
        $url = $workspace['indexing']['urls'][0] ?? null;

        $this->assertNotNull($url);
        $this->assertSame('https://example.com/user', $url['user_canonical']);
        $this->assertSame('https://example.com/google', $url['google_canonical']);
        $this->assertStringContainsString('selective sample', strtolower($url['sample_note'] ?? ''));
        $this->assertStringContainsString('never extrapolated', strtolower($workspace['indexing']['inspection_note'] ?? ''));
    }

    #[Test]
    public function unreadiness_does_not_present_synthetic_zero_glance_values(): void
    {
        // RealBound asset with no materialization / integrity — gate not usable.
        $demoBaseline = GscWorkspaceFixtures::workspace('last_28');
        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame('real', $workspace['migration_mode']);
        $this->assertSame('—', $workspace['glance']['clicks']['value']);
        $this->assertNull($workspace['glance']['clicks']['raw']);
        $this->assertSame('—', $workspace['glance']['impressions']['value']);
        $this->assertNull($workspace['glance']['impressions']['raw']);
        $this->assertNotSame($demoBaseline['glance']['clicks']['raw'], $workspace['glance']['clicks']['raw']);
        $this->assertNotSame(0, $workspace['glance']['clicks']['raw']);
        $this->assertStringContainsString('Unavailable ≠ zero', $workspace['glance']['clicks']['note'] ?? '');
    }

    #[Test]
    public function exception_path_returns_unavailable_operational_workspace_without_demo_fallback(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', $dates);

        $pool = $this->mock(GscPoolReadRepository::class)->makePartial();
        $pool->shouldReceive('propertyDailySums')->andThrow(new RuntimeException('simulated read failure'));
        $this->app->forgetInstance(GscSpecialistReadService::class);

        $demoBaseline = GscWorkspaceFixtures::workspace('last_28');
        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame('real', $workspace['migration_mode']);
        $this->assertNull($workspace['glance']['clicks']['raw']);
        $this->assertNotSame($demoBaseline['glance']['clicks']['raw'], $workspace['glance']['clicks']['raw']);
        $this->assertStringContainsString('read error', strtolower($workspace['identity']['title']));
        foreach ($workspace['data_provenance'] as $state) {
            $this->assertSame('UNAVAILABLE', $state);
        }
    }

    #[Test]
    public function search_console_page_render_makes_zero_http_calls_for_demo_asset(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $user->assignRole(Roles::ADMIN);
        $this->actingAs($user);

        Livewire::test(SearchConsolePage::class, ['assetId' => (string) $this->asset->id])
            ->assertOk();

        Http::assertNothingSent();
    }

    #[Test]
    public function workspace_build_creates_no_evidence_or_findings(): void
    {
        $beforeEvidence = Evidence::query()->count();
        $beforeFindings = Finding::query()->count();

        app(GscSpecialistReadService::class)->workspace(DemoCatalog::GSC_ASSET_ID);
        app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame($beforeEvidence, Evidence::query()->count());
        $this->assertSame($beforeFindings, Finding::query()->count());
    }

    #[Test]
    public function frozen_allowed_tabs_list_is_unchanged(): void
    {
        $component = new SearchConsolePage;
        $this->assertSame([
            'overview',
            'performance',
            'demand',
            'pages',
            'indexing',
            'operations',
        ], $component->allowedTabs);
    }

    #[Test]
    public function bound_workspace_reads_central_resource_first_history_after_binding(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->centralMaterializationWithDates('gsc_property_daily', $dates, 'web');
        $this->insertCentralPropertyRows($dates, clicks: 100, impressions: 1000, searchType: 'web');
        // Other search surfaces of the same central property never leak into the Web workspace.
        $this->insertCentralPropertyRows($dates, clicks: 7, impressions: 70, searchType: 'image');

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame('real', $workspace['migration_mode']);
        $this->assertSame(2800, $workspace['glance']['clicks']['raw']);
        $this->assertSame(28000, $workspace['glance']['impressions']['raw']);
        $this->assertSame('REAL', $workspace['data_provenance']['glance.clicks']);
        $this->assertCount(28, $workspace['metric_series']['clicks']);
    }

    #[Test]
    public function central_coverage_from_another_search_type_does_not_make_the_web_workspace_ready(): void
    {
        $dates = $this->contiguousDates('2026-07-16', 28);
        $this->centralMaterializationWithDates('gsc_property_daily', $dates, 'image');
        $this->insertCentralPropertyRows($dates, clicks: 7, impressions: 70, searchType: 'image');

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertNull($workspace['glance']['clicks']['raw']);
        $this->assertSame('UNAVAILABLE', $workspace['data_provenance']['glance.clicks']);
    }

    #[Test]
    public function a_natural_key_has_one_row_so_bound_and_central_scopes_never_double_count(): void
    {
        $date = '2026-07-16';
        $this->insertPropertyDailyRows([$date], clicks: 10, impressions: 100);

        $this->expectException(QueryException::class);
        $this->insertCentralPropertyRows([$date], clicks: 10, impressions: 100, searchType: 'web');
    }

    #[Test]
    public function comparison_is_unavailable_unless_both_periods_are_fully_covered(): void
    {
        $current = $this->contiguousDates('2026-07-16', 28);
        // Only half of the previous 28-day window was collected.
        $previous = $this->contiguousDates('2026-07-02', 14);
        $this->seedDatasetReady('gsc_property_daily', array_merge($previous, $current));
        $this->insertPropertyDailyRows(array_merge($previous, $current), clicks: 10, impressions: 100);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28');

        $this->assertSame(280, $workspace['glance']['clicks']['raw']);
        $this->assertStringContainsString('vs previous period unavailable', $workspace['glance']['clicks']['secondary']);
        $this->assertStringContainsString('vs previous period unavailable', $workspace['glance']['impressions']['secondary']);
        $this->assertStringContainsString('vs previous period unavailable', $workspace['glance']['ctr']['secondary']);
    }

    #[Test]
    public function fully_covered_yoy_comparison_is_labelled_year_ago(): void
    {
        $current = $this->contiguousDates('2026-07-16', 28);
        $yearAgo = $this->contiguousDates('2025-07-16', 28);
        $this->seedDatasetReady('gsc_property_daily', array_merge($yearAgo, $current));
        $this->insertPropertyDailyRows($current, clicks: 20, impressions: 100);
        $this->insertPropertyDailyRows($yearAgo, clicks: 10, impressions: 100);

        $workspace = app(GscSpecialistReadService::class)->workspace((string) $this->asset->id, 'last_28', null, null, 'yoy');

        $this->assertSame('+100.0% vs year-ago period', explode(' · ', $workspace['glance']['clicks']['secondary'])[0]);
        $this->assertStringNotContainsString('previous period', $workspace['glance']['ctr']['secondary']);
    }

    /**
     * Central materialization whose coverage is attributed to a dataset run of one search type.
     *
     * @param  list<string>  $dates
     */
    private function centralMaterializationWithDates(string $datasetId, array $dates, string $searchType): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'resource_kind' => 'provider_resource',
            'external_resource_id' => $this->resource->id,
            'digital_asset_id' => null,
            'status' => CollectionRunStatus::Completed,
            'metadata' => ['collection_scope' => 'provider_resource_first'],
        ]);
        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'dataset_contract_id' => $datasetId,
            'request_family_id' => 'GSC_CENTRAL_SEARCH_ANALYTICS',
            'status' => CollectionRunStatus::Completed,
            'metadata' => ['search_type' => $searchType, 'central_definition' => ['dataset_id' => $datasetId, 'search_type' => $searchType]],
        ]);

        $materialization = app(MaterializationService::class)->recordSuccessfulCoverageDates(
            datasetId: $datasetId,
            digitalAssetId: null,
            externalResourceId: $this->resource->id,
            contractVersion: 1,
            dates: $dates,
            collectionRunId: $run->id,
            datasetRunId: $datasetRun->id,
            providerOrSource: 'SEARCH_CONSOLE',
        );
        $materialization->forceFill(['last_collected_at' => CarbonImmutable::parse('2026-08-12 10:00:00', 'UTC')])->save();
    }

    /**
     * @param  list<string>  $dates
     */
    private function insertCentralPropertyRows(array $dates, int $clicks, int $impressions, string $searchType): void
    {
        foreach ($dates as $date) {
            DB::table('gsc_property_daily')->insert([
                'digital_asset_id' => null,
                'external_resource_id' => $this->resource->id,
                'site_url' => 'sc-domain:example.com',
                'reporting_date' => $date,
                'search_type' => $searchType,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'America/Los_Angeles',
                'record_fingerprint' => hash('sha256', 'central-'.$searchType.'-'.$date),
                'metadata' => json_encode(['provider_average_position' => 6.0]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
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
            'provider_or_source' => 'SEARCH_CONSOLE',
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
            'provider_or_source' => 'SEARCH_CONSOLE',
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
            'provider_or_source' => 'SEARCH_CONSOLE',
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
    private function insertPropertyDailyRows(
        array $dates,
        int $clicks = 50,
        int $impressions = 500,
        float $position = 8.0,
    ): void {
        foreach ($dates as $date) {
            DB::table('gsc_property_daily')->insert([
                'digital_asset_id' => $this->asset->id,
                'external_resource_id' => $this->resource->id,
                'site_url' => 'sc-domain:example.com',
                'reporting_date' => $date,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'America/Los_Angeles',
                'record_fingerprint' => hash('sha256', 'property-'.$date),
                'metadata' => json_encode(['provider_average_position' => $position]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  list<string>  $dates
     */
    private function insertQueryDailyRows(
        array $dates,
        string $query,
        int $clicks,
        int $impressions,
    ): void {
        foreach ($dates as $date) {
            DB::table('gsc_query_daily')->insert([
                'digital_asset_id' => $this->asset->id,
                'external_resource_id' => $this->resource->id,
                'site_url' => 'sc-domain:example.com',
                'reporting_date' => $date,
                'query' => $query,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'America/Los_Angeles',
                'record_fingerprint' => hash('sha256', 'query-'.$date),
                'metadata' => json_encode(['provider_average_position' => 4.0]),
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
