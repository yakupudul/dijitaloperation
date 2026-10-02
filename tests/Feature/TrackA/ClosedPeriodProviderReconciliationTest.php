<?php

namespace Tests\Feature\TrackA;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Services\DataPool\MaterializationService;
use App\Services\DataPool\Reconciliation\ClosedPeriodProviderReconciler;
use App\Services\Ga4\Ga4SpecialistBindingResolver;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Operator\OperatorClock;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ClosedPeriodProviderReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $frozen = CarbonImmutable::parse('2026-08-21', OperatorClock::timezone());
        Carbon::setTestNow($frozen);
        CarbonImmutable::setTestNow($frozen);
        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function gsc_closed_period_matches_provider_totals_within_one_percent(): void
    {
        [$asset, $resource] = $this->makeGscBinding();
        $this->insertGscDays($asset->id, $resource->id, '2026-07-01', 31, clicks: 100, impressions: 1000);
        $this->recordCompletedCoverage('SEARCH_CONSOLE', 'gsc_property_daily', $asset->id, $resource->id, '2026-07-01', '2026-07-31');

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [[
                    'clicks' => 3100,
                    'impressions' => 31000,
                    'ctr' => 0.1,
                    'position' => 8.2,
                ]],
            ], 200),
        ]);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile(
            'SEARCH_CONSOLE',
            $asset->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame('pass', $report->status);
        $this->assertTrue($report->externalUatRequired);
        $clicks = collect($report->metrics)->firstWhere('metric', 'clicks');
        $this->assertSame('match', $clicks['status']);
        $this->assertSame(3100.0, $clicks['warehouse']);
        $position = collect($report->metrics)->firstWhere('metric', 'position');
        $this->assertSame('definition_difference', $position['status']);
    }

    #[Test]
    public function missing_warehouse_days_are_unavailable_not_zero(): void
    {
        [$asset] = $this->makeGscBinding();

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [['clicks' => 10, 'impressions' => 100]],
            ], 200),
        ]);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile(
            'SEARCH_CONSOLE',
            $asset->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame('unavailable', $report->status);
        $clicks = collect($report->metrics)->firstWhere('metric', 'clicks');
        $this->assertSame('unavailable', $clicks['status']);
        $this->assertNull($clicks['warehouse']);
        $this->assertStringContainsString('Missing ≠ zero', $clicks['note']);
    }

    #[Test]
    public function artisan_command_emits_json_and_external_uat_flag(): void
    {
        [$asset, $resource] = $this->makeGscBinding();
        $this->insertGscDays($asset->id, $resource->id, '2026-07-01', 31, clicks: 10, impressions: 100);
        $this->recordCompletedCoverage('SEARCH_CONSOLE', 'gsc_property_daily', $asset->id, $resource->id, '2026-07-01', '2026-07-31');

        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [['clicks' => 310, 'impressions' => 3100, 'ctr' => 0.1, 'position' => 4]],
            ], 200),
        ]);

        $exit = Artisan::call('moxdop:reconcile-provider-period', [
            'provider' => 'SEARCH_CONSOLE',
            '--asset' => $asset->id,
            '--from' => '2026-07-01',
            '--to' => '2026-07-31',
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame('pass', $payload['status']);
        $this->assertTrue($payload['external_uat_required']);
        $this->assertStringContainsString('/assets/search-console/', $payload['operator_path']);
    }

    #[Test]
    public function ga4_closed_period_matches_additive_totals_and_documents_non_additive_users(): void
    {
        [$asset, $resource] = $this->makeGa4Binding();
        $this->insertGa4Days($asset->id, $resource->id, '2026-07-01', 31, sessions: 10, engaged: 7, views: 20, newUsers: 3, conversions: 1, revenue: '4.250000');
        $this->recordCompletedCoverage('GA4', 'ga4_property_daily', $asset->id, $resource->id, '2026-07-01', '2026-07-31');

        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/*' => Http::response([
                'metricHeaders' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'newUsers'],
                    ['name' => 'conversions'],
                    ['name' => 'keyEvents'],
                    ['name' => 'totalRevenue'],
                ],
                'rows' => [[
                    'metricValues' => [
                        ['value' => '310'],
                        ['value' => '217'],
                        ['value' => '620'],
                        ['value' => '93'],
                        ['value' => '31'],
                        ['value' => '31'],
                        ['value' => '131.75'],
                    ],
                ]],
            ], 200),
        ]);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile(
            'GA4',
            $asset->id,
            '2026-07-01',
            '2026-07-31',
        );

        $this->assertSame('pass', $report->status);
        $sessions = collect($report->metrics)->firstWhere('metric', 'sessions');
        $this->assertSame('match', $sessions['status']);
        $this->assertSame(310.0, $sessions['warehouse']);
        $users = collect($report->metrics)->firstWhere('metric', 'totalUsers');
        $this->assertSame('definition_difference', $users['status']);
        $this->assertTrue($report->externalUatRequired);
    }

    #[Test]
    public function gsc_central_resource_first_collection_reconciles_for_the_bound_asset(): void
    {
        [$asset, $resource] = $this->makeGscBinding();
        $this->insertGscDays(null, $resource->id, '2026-07-01', 31, clicks: 100, impressions: 1000);
        // Another search type of the same central resource must never leak into Web totals.
        $this->insertGscDays(null, $resource->id, '2026-07-01', 31, clicks: 7, impressions: 70, searchType: 'image');
        $this->recordCompletedCoverage('SEARCH_CONSOLE', 'gsc_property_daily', null, $resource->id, '2026-06-01', '2026-08-19', [
            'collection_scope' => 'provider_resource_first',
            'search_type' => 'web',
            'central_definition' => ['dataset_id' => 'gsc_property_daily', 'search_type' => 'web', 'data_state' => 'final', 'aggregation_type' => 'byProperty'],
        ]);
        $this->fakeGscTotals(3100, 31000);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile('SEARCH_CONSOLE', $asset->id, '2026-07-01', '2026-07-31');

        $this->assertSame('pass', $report->status);
        $this->assertSame('central', $report->scope['collection_scope']);
        $this->assertSame('FULLY_COVERED', $report->scope['coverage_state']);
        $this->assertSame(3100.0, collect($report->metrics)->firstWhere('metric', 'clicks')['warehouse']);
        Http::assertSent(fn ($request): bool => $request['type'] === 'web'
            && $request['dataState'] === 'final'
            && $request['aggregationType'] === 'byProperty');
    }

    #[Test]
    public function gsc_central_coverage_from_another_search_type_does_not_prove_web_coverage(): void
    {
        [$asset, $resource] = $this->makeGscBinding();
        $this->insertGscDays(null, $resource->id, '2026-07-01', 31, clicks: 100, impressions: 1000);
        $this->recordCompletedCoverage('SEARCH_CONSOLE', 'gsc_property_daily', null, $resource->id, '2026-07-01', '2026-07-31', [
            'collection_scope' => 'provider_resource_first',
            'search_type' => 'image',
            'central_definition' => ['dataset_id' => 'gsc_property_daily', 'search_type' => 'image', 'data_state' => 'final', 'aggregation_type' => 'byProperty'],
        ]);
        $this->fakeGscTotals(3100, 31000);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile('SEARCH_CONSOLE', $asset->id, '2026-07-01', '2026-07-31');

        $this->assertSame('unavailable', $report->status);
        $this->assertSame('NOT_COVERED', $report->scope['coverage_state']);
    }

    #[Test]
    public function partial_coverage_is_unavailable_and_command_exits_non_zero(): void
    {
        [$asset, $resource] = $this->makeGscBinding();
        $this->insertGscDays($asset->id, $resource->id, '2026-07-01', 31, clicks: 100, impressions: 1000);
        $this->recordCompletedCoverage('SEARCH_CONSOLE', 'gsc_property_daily', $asset->id, $resource->id, '2026-07-01', '2026-07-20');
        $this->fakeGscTotals(3100, 31000);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile('SEARCH_CONSOLE', $asset->id, '2026-07-01', '2026-07-31');

        $this->assertSame('unavailable', $report->status);
        $this->assertSame('PARTIALLY_COVERED', $report->scope['coverage_state']);
        $this->assertSame(11, $report->scope['missing_days']);
        $this->assertSame('2026-07-21', $report->scope['first_missing_date']);
        $this->assertNull(collect($report->metrics)->firstWhere('metric', 'clicks')['warehouse']);

        $exit = Artisan::call('moxdop:reconcile-provider-period', [
            'provider' => 'SEARCH_CONSOLE',
            '--asset' => $asset->id,
            '--from' => '2026-07-01',
            '--to' => '2026-07-31',
            '--json' => true,
        ]);
        $this->assertSame(1, $exit);
        $this->assertSame('unavailable', json_decode(Artisan::output(), true)['status']);
    }

    #[Test]
    public function coverage_written_by_a_run_that_did_not_complete_is_not_proof(): void
    {
        [$asset, $resource] = $this->makeGscBinding();
        $this->insertGscDays($asset->id, $resource->id, '2026-07-01', 31, clicks: 100, impressions: 1000);
        $this->recordCompletedCoverage('SEARCH_CONSOLE', 'gsc_property_daily', $asset->id, $resource->id, '2026-07-01', '2026-07-31', status: CollectionRunStatus::Partial);
        $this->fakeGscTotals(3100, 31000);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile('SEARCH_CONSOLE', $asset->id, '2026-07-01', '2026-07-31');

        $this->assertSame('unavailable', $report->status);
        $this->assertSame('NOT_COVERED', $report->scope['coverage_state']);
    }

    #[Test]
    public function tolerance_uses_provider_total_as_denominator(): void
    {
        [$asset, $resource] = $this->makeGscBinding();
        // Warehouse 3131 vs provider 3100: |31| / 3100 = 1.0% (match). Provider-as-denominator
        // matters: against the warehouse total it would be 0.99%.
        $this->insertGscDays($asset->id, $resource->id, '2026-07-01', 31, clicks: 101, impressions: 1000);
        $this->recordCompletedCoverage('SEARCH_CONSOLE', 'gsc_property_daily', $asset->id, $resource->id, '2026-07-01', '2026-07-31');
        $this->fakeGscTotals(3100, 31000);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile('SEARCH_CONSOLE', $asset->id, '2026-07-01', '2026-07-31');
        $clicks = collect($report->metrics)->firstWhere('metric', 'clicks');
        $this->assertSame('match', $clicks['status']);
        $this->assertSame(0.01, $clicks['relative_delta']);

        $strict = app(ClosedPeriodProviderReconciler::class)->reconcile('SEARCH_CONSOLE', $asset->id, '2026-07-01', '2026-07-31', 0.0099);
        $this->assertSame('fail', $strict->status);
        $this->assertSame('mismatch', collect($strict->metrics)->firstWhere('metric', 'clicks')['status']);
    }

    #[Test]
    public function ga4_central_resource_first_collection_reconciles_for_the_bound_asset(): void
    {
        [$asset, $resource] = $this->makeGa4Binding();
        $this->insertGa4Days(null, $resource->id, '2026-07-01', 31, sessions: 10, engaged: 7, views: 20, newUsers: 3, conversions: 1, revenue: '4.250000');
        $this->recordCompletedCoverage('GA4', 'ga4_property_daily', null, $resource->id, '2026-07-01', '2026-07-31', [
            'collection_scope' => 'provider_resource_first',
        ]);
        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/*' => Http::response([
                'metricHeaders' => [['name' => 'sessions'], ['name' => 'engagedSessions'], ['name' => 'screenPageViews']],
                'rows' => [['metricValues' => [['value' => '310'], ['value' => '217'], ['value' => '620']]]],
            ], 200),
        ]);

        $report = app(ClosedPeriodProviderReconciler::class)->reconcile('GA4', $asset->id, '2026-07-01', '2026-07-31');

        $this->assertSame('central', $report->scope['collection_scope']);
        $this->assertSame('FULLY_COVERED', $report->scope['coverage_state']);
        $sessions = collect($report->metrics)->firstWhere('metric', 'sessions');
        $this->assertSame('match', $sessions['status']);
        $this->assertSame(310.0, $sessions['warehouse']);
        // newUsers was collected but the provider did not return it: unavailable, never zero.
        $newUsers = collect($report->metrics)->firstWhere('metric', 'newUsers');
        $this->assertSame('unavailable', $newUsers['status']);
        $this->assertSame('unavailable', $report->status);
    }

    #[Test]
    public function ga4_rejects_open_current_day(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('closed');

        app(ClosedPeriodProviderReconciler::class)->reconcile('GA4', 1, '2026-08-01', '2026-08-21');
    }

    /**
     * @return array{0: DigitalAsset, 1: CoreExternalResource}
     */
    private function makeGscBinding(): array
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'gsc',
        ]);
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::SEARCH_CONSOLE_READONLY]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'gsc-access-token',
                'refresh_token' => 'gsc-refresh-token',
                'scope' => GoogleScopes::SEARCH_CONSOLE_READONLY,
            ],
            'expires_at' => now()->addHour(),
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => 'sc-domain:example.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => GscSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$asset, $resource];
    }

    /**
     * @return array{0: DigitalAsset, 1: CoreExternalResource}
     */
    private function makeGa4Binding(): array
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'ga4',
        ]);
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::ANALYTICS_READONLY]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'ga4-access-token',
                'refresh_token' => 'ga4-refresh-token',
                'scope' => GoogleScopes::ANALYTICS_READONLY,
            ],
            'expires_at' => now()->addHour(),
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
            'external_id' => 'properties/123456',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => Ga4SpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return [$asset, $resource];
    }

    private function fakeGscTotals(int $clicks, int $impressions): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response([
                'rows' => [['clicks' => $clicks, 'impressions' => $impressions, 'ctr' => 0.1, 'position' => 8.2]],
            ], 200),
        ]);
    }

    /**
     * Record coverage the way the collectors do: a dataset run writes successful dates into the
     * materialization through MaterializationService, then the run reaches its final status.
     *
     * @param  array<string, mixed>  $datasetRunMetadata
     */
    private function recordCompletedCoverage(
        string $provider,
        string $datasetId,
        ?int $assetId,
        int $resourceId,
        string $start,
        string $end,
        array $datasetRunMetadata = [],
        CollectionRunStatus $status = CollectionRunStatus::Completed,
    ): void {
        $run = CollectionRun::factory()->create(['status' => $status]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => $provider,
            'resource_kind' => $assetId === null ? 'provider_resource' : 'bound_provider_resource',
            'external_resource_id' => $resourceId,
            'digital_asset_id' => $assetId,
            'status' => $status,
        ]);
        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => $provider,
            'dataset_contract_id' => $datasetId,
            'request_family_id' => $provider === 'GA4' ? 'GA4_RF_PROPERTY_DAILY' : 'GSC_RF_PROPERTY_DAILY',
            'status' => $status,
            'metadata' => $datasetRunMetadata,
        ]);

        app(MaterializationService::class)->recordSuccessfulCoverageRange(
            datasetId: $datasetId,
            digitalAssetId: $assetId,
            externalResourceId: $resourceId,
            contractVersion: 1,
            start: $start,
            end: $end,
            collectionRunId: $run->id,
            datasetRunId: $datasetRun->id,
            providerOrSource: $provider,
        );
    }

    private function insertGscDays(?int $assetId, int $resourceId, string $start, int $days, int $clicks, int $impressions, string $searchType = 'web'): void
    {
        $date = CarbonImmutable::parse($start);
        for ($i = 0; $i < $days; $i++) {
            $day = $date->addDays($i)->toDateString();
            DB::table('gsc_property_daily')->insert([
                'digital_asset_id' => $assetId,
                'external_resource_id' => $resourceId,
                'site_url' => 'sc-domain:example.com',
                'reporting_date' => $day,
                'search_type' => $searchType,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'America/Los_Angeles',
                'record_fingerprint' => hash('sha256', $resourceId.'-'.$searchType.'-'.$day),
                'metadata' => json_encode(['provider_average_position' => 8.0]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertGa4Days(
        ?int $assetId,
        int $resourceId,
        string $start,
        int $days,
        int $sessions,
        int $engaged,
        int $views,
        int $newUsers,
        int $conversions,
        string $revenue,
    ): void {
        $date = CarbonImmutable::parse($start);
        for ($i = 0; $i < $days; $i++) {
            $day = $date->addDays($i)->toDateString();
            DB::table('ga4_property_daily')->insert([
                'digital_asset_id' => $assetId,
                'external_resource_id' => $resourceId,
                'property_id' => '123456',
                'reporting_date' => $day,
                'sessions' => $sessions,
                'engagedSessions' => $engaged,
                'screenPageViews' => $views,
                'userEngagementDuration' => 100,
                'totalUsers' => 8,
                'activeUsers' => 6,
                'newUsers' => $newUsers,
                'conversions' => $conversions,
                'keyEvents' => $conversions,
                'totalRevenue' => $revenue,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'UTC',
                'record_fingerprint' => hash('sha256', 'ga4-'.$resourceId.'-'.$day),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
