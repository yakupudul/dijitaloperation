<?php

namespace Tests\Feature;

use App\Contracts\WebsiteOperatorWorkspace;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Services\Gsc\GscSpecialistBindingResolver;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PeriodAwareWebsiteWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_disconnected_sources_clear_period_dependent_rows_instead_of_keeping_stale_evidence(): void
    {
        $asset = $this->websiteWithStaleUnboundedEvidence();

        $data = app(WebsiteOperatorWorkspace::class)->overview($asset, 'last_28');

        $this->assertSame([], $data['pages']);
        $this->assertSame([], $data['queries']);
        $this->assertSame([], $data['landing_pages']);
        $this->assertSame([], $data['acquisition']);
        $this->assertSame([], $data['gsc_daily']['clicks'] ?? []);
        $this->assertFalse($data['has_performance_data']);
        $this->assertSame([], $data['kpis']);
        $this->assertNull($data['gsc_summary']);
        $this->assertNull($data['ga4_summary']);
    }

    public function test_selected_period_without_usable_source_rows_does_not_keep_unbounded_pages(): void
    {
        $asset = $this->websiteWithStaleUnboundedEvidence();
        $this->bindConnectedSearchConsole($asset);

        $data = app(WebsiteOperatorWorkspace::class)->overview(
            $asset,
            'custom',
            '2020-01-01',
            '2020-01-31',
        );

        $this->assertSame([], $data['pages']);
        $this->assertSame([], $data['queries']);
        $this->assertSame([], $data['landing_pages']);
        $this->assertSame([], $data['acquisition']);
        $this->assertFalse($data['has_performance_data']);
        foreach ($data['kpis'] as $kpi) {
            $this->assertNotSame(0, $kpi['value']);
            $this->assertNotSame('0', $kpi['value']);
        }
    }

    private function websiteWithStaleUnboundedEvidence(): DigitalAsset
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website',
            'status' => 'completed',
            'started_at' => now()->subDay(),
            'finished_at' => now()->subDay(),
        ]);

        foreach ([
            'gsc_page_performance' => [['url' => 'https://example.com/stale-page', 'clicks' => 44]],
            'gsc_query_performance' => [['query' => 'stale query', 'clicks' => 21]],
            'ga4_landing_page_performance' => [['page' => '/stale-landing', 'sessions' => 18]],
            'ga4_acquisition_summary' => [['channel' => 'organic', 'sessions' => 12]],
            'gsc_daily_performance' => [['date' => '2026-07-01', 'clicks' => 9, 'impressions' => 90]],
        ] as $type => $rows) {
            Evidence::query()->create([
                'run_id' => $run->id,
                'digital_asset_id' => $asset->id,
                'source_module' => 'website',
                'type' => $type,
                'title' => $type,
                'payload' => [
                    'response_ok' => true,
                    'rows' => $rows,
                ],
                'observed_at' => now()->subDay(),
            ]);
        }

        return $asset->fresh(['brand']) ?? $asset;
    }

    private function bindConnectedSearchConsole(DigitalAsset $asset): void
    {
        config([
            'moxdop.google.client_id' => 'test-client-id',
            'moxdop.google.client_secret' => 'test-client-secret',
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

        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => 'sc-domain:example.com',
            'display_name' => 'Example GSC',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => GscSpecialistBindingResolver::CAPABILITY,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }
}
