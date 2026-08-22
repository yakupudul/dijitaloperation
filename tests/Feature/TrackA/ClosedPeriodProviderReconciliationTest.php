<?php

namespace Tests\Feature\TrackA;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
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

        Http::fake([
            'https://analyticsdata.googleapis.com/v1beta/*' => Http::response([
                'metricHeaders' => [
                    ['name' => 'sessions'],
                    ['name' => 'engagedSessions'],
                    ['name' => 'screenPageViews'],
                    ['name' => 'newUsers'],
                    ['name' => 'conversions'],
                    ['name' => 'totalRevenue'],
                ],
                'rows' => [[
                    'metricValues' => [
                        ['value' => '310'],
                        ['value' => '217'],
                        ['value' => '620'],
                        ['value' => '93'],
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

    private function insertGscDays(int $assetId, int $resourceId, string $start, int $days, int $clicks, int $impressions): void
    {
        $date = CarbonImmutable::parse($start);
        for ($i = 0; $i < $days; $i++) {
            $day = $date->addDays($i)->toDateString();
            DB::table('gsc_property_daily')->insert([
                'digital_asset_id' => $assetId,
                'external_resource_id' => $resourceId,
                'site_url' => 'sc-domain:example.com',
                'reporting_date' => $day,
                'clicks' => $clicks,
                'impressions' => $impressions,
                'contract_version' => 1,
                'first_collected_at' => now(),
                'last_collected_at' => now(),
                'source_timezone' => 'America/Los_Angeles',
                'record_fingerprint' => hash('sha256', $assetId.'-'.$day),
                'metadata' => json_encode(['provider_average_position' => 8.0]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function insertGa4Days(
        int $assetId,
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
                'record_fingerprint' => hash('sha256', 'ga4-'.$assetId.'-'.$day),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
