<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Models\User;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use MoxDop\MetaAds\Collection\MetaAdsBoundCollector;
use MoxDop\MetaAds\Workspace\MetaAdsWorkspaceData;
use Tests\TestCase;

class MetaAdsComparisonDeltaGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_deltas_without_previous_period_are_suppressed(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $integration = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_real_999',
            'display_name' => 'Real Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::query()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'configuration' => [],
        ]);

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'meta-ads',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY,
            'title' => 'account',
            'observed_at' => now(),
            'payload' => [
                'response_ok' => true,
                'account_name' => 'Real Account',
                'requested_period' => ['start' => '2026-07-01', 'end' => '2026-07-28'],
                'comparison_period' => ['start' => '2026-06-03', 'end' => '2026-06-30'],
                'current' => [
                    'spend' => 1200.5,
                    'impressions' => 87299,
                    'clicks' => 1296,
                    'ctr' => 1.48,
                ],
                // Synthetic/stale deltas without a populated previous period.
                'deltas' => [
                    'spend' => ['percent' => 8.2],
                    'ctr' => ['percent' => -3.1],
                ],
                'previous' => [],
            ],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_CAMPAIGN_PERFORMANCE,
            'title' => 'campaigns',
            'observed_at' => now(),
            'payload' => [
                'response_ok' => true,
                'rows' => [[
                    'campaign_name' => 'Real Camp',
                    'objective' => 'OUTCOME_LEADS',
                    'spend' => 100,
                    'primary_result_status' => 'resolved',
                    'primary_result' => [
                        'status' => 'resolved',
                        'raw_action_type' => 'lead',
                        'count' => 5,
                    ],
                    'actions' => [],
                ]],
            ],
        ]);

        $data = app(MetaAdsWorkspaceData::class)->for($asset->fresh());

        $this->assertFalse($data['comparison']['available']);
        foreach ($data['kpis'] as $kpi) {
            $this->assertNull($kpi['delta_percent'], $kpi['key'].' must not expose a delta without prior Evidence');
        }

        $html = view('meta-ads::workspace.overview', ['data' => $data])->render();
        $this->assertStringNotContainsString('8.2% vs prior', $html);
        $this->assertStringNotContainsString('-3.1% vs prior', $html);
        $this->assertStringNotContainsString('vs previous period', $html);
        $this->assertStringContainsString('comparison deltas are suppressed', $data['comparison']['reason']);

        $this->assertSame('Not connected', $data['data_coverage']['business_validation']);
        $this->assertArrayHasKey('attribution_context', $data['data_coverage']);
        $this->assertArrayHasKey('result_signal', $data['data_coverage']);
        $this->assertSame('Resolved', $data['data_coverage']['result_signal']);
    }

    public function test_deltas_appear_only_when_previous_period_has_metrics(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
        ]);
        $integration = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_real_1000',
            'display_name' => 'Real Account B',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::query()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
            'configuration' => [],
        ]);

        $run = Run::query()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'meta-ads',
            'status' => 'completed',
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [],
        ]);

        Evidence::query()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'meta-ads',
            'type' => MetaAdsBoundCollector::EVIDENCE_ACCOUNT_SUMMARY,
            'title' => 'account',
            'observed_at' => now(),
            'payload' => [
                'response_ok' => true,
                'requested_period' => ['start' => '2026-07-01', 'end' => '2026-07-28'],
                'comparison_period' => ['start' => '2026-06-03', 'end' => '2026-06-30'],
                'current' => ['spend' => 200.0, 'impressions' => 10000, 'ctr' => 1.5],
                'previous' => ['spend' => 100.0, 'impressions' => 8000, 'ctr' => 1.2],
                'deltas' => [
                    'spend' => ['percent' => 100.0],
                    'ctr' => ['percent' => 25.0],
                ],
            ],
        ]);

        $data = app(MetaAdsWorkspaceData::class)->for($asset->fresh());
        $this->assertTrue($data['comparison']['available']);
        $spend = collect($data['kpis'])->firstWhere('key', 'spend');
        $this->assertSame(100.0, (float) $spend['delta_percent']);
    }
}
