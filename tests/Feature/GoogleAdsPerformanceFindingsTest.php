<?php

namespace Tests\Feature;

use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Run;
use App\Services\Findings\FindingLifecycleService;
use App\Services\Integrations\BoundCollectorRegistry;
use App\Services\Integrations\CollectLiveBoundDataService;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use MoxDop\GoogleAds\Findings\GoogleAdsPerformanceBoundEvidenceEvaluator;
use MoxDop\GoogleAds\Findings\PerformanceFindingsCatalog;
use Tests\TestCase;

class GoogleAdsPerformanceFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_and_campaign_rules_and_failed_run_resolution_protection(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $run = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_account_summary',
            'payload' => [
                'response_ok' => true,
                'current' => [
                    'cost' => 200.0,
                    'conversions' => 2.0,
                    'clicks' => 100.0,
                    'impressions' => 1000.0,
                ],
                'previous' => [
                    'cost' => 100.0,
                    'conversions' => 20.0,
                    'clicks' => 80.0,
                    'impressions' => 900.0,
                ],
                'deltas' => [
                    'cost' => ['absolute' => 100.0, 'percent' => 100.0],
                    'conversions' => ['absolute' => -18.0, 'percent' => -90.0],
                ],
            ],
        ]);

        Evidence::factory()->create([
            'run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_campaign_performance',
            'payload' => [
                'response_ok' => true,
                'rows' => [
                    [
                        'campaign_id' => '111',
                        'campaign_name' => 'Zero Conv Campaign',
                        'cost' => 75.0,
                        'clicks' => 40.0,
                        'conversions' => 0.0,
                    ],
                ],
            ],
        ]);

        $result = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$run->fresh('evidence')]);
        $this->assertTrue($result->evaluationSuccessful);
        app(FindingLifecycleService::class)->apply($result);

        $this->assertDatabaseHas('findings', [
            'fingerprint' => PerformanceFindingsCatalog::RULE_CONVERSIONS_DECLINE,
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('findings', [
            'fingerprint' => PerformanceFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS.':111',
            'status' => 'open',
        ]);

        $failed = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'failed',
        ]);
        Evidence::factory()->create([
            'run_id' => $failed->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_account_summary',
            'payload' => ['response_ok' => false],
        ]);

        $failedResult = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$failed->fresh('evidence')]);
        $this->assertFalse($failedResult->evaluationSuccessful);
        $stats = app(FindingLifecycleService::class)->apply($failedResult);
        $this->assertSame(0, $stats['resolved']);
        $this->assertSame('open', Finding::query()->where('fingerprint', PerformanceFindingsCatalog::RULE_CONVERSIONS_DECLINE)->value('status'));

        // Healthy evaluation with no matches resolves owned open findings.
        $healthy = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $healthy->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_account_summary',
            'payload' => [
                'response_ok' => true,
                'current' => ['cost' => 40.0, 'conversions' => 12.0],
                'previous' => ['cost' => 40.0, 'conversions' => 12.0],
                'deltas' => [
                    'cost' => ['absolute' => 0.0, 'percent' => 0.0],
                    'conversions' => ['absolute' => 0.0, 'percent' => 0.0],
                ],
            ],
        ]);
        Evidence::factory()->create([
            'run_id' => $healthy->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_campaign_performance',
            'payload' => [
                'response_ok' => true,
                'rows' => [
                    [
                        'campaign_id' => '111',
                        'campaign_name' => 'Zero Conv Campaign',
                        'cost' => 10.0,
                        'clicks' => 5.0,
                        'conversions' => 2.0,
                    ],
                ],
            ],
        ]);

        $healthyResult = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$healthy->fresh('evidence')]);
        $resolveStats = app(FindingLifecycleService::class)->apply($healthyResult);
        $this->assertGreaterThanOrEqual(2, $resolveStats['resolved']);
        $this->assertSame('resolved', Finding::query()->where('fingerprint', PerformanceFindingsCatalog::RULE_CONVERSIONS_DECLINE)->value('status'));
        $this->assertSame('resolved', Finding::query()->where('fingerprint', PerformanceFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS.':111')->value('status'));

        // Reopen same fingerprint / preserve first_seen_at.
        $firstSeen = Finding::query()->where('fingerprint', PerformanceFindingsCatalog::RULE_CONVERSIONS_DECLINE)->value('first_seen_at');
        $again = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'google-ads',
            'status' => 'completed',
        ]);
        Evidence::factory()->create([
            'run_id' => $again->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_account_summary',
            'payload' => [
                'response_ok' => true,
                'current' => ['cost' => 200.0, 'conversions' => 2.0],
                'previous' => ['cost' => 100.0, 'conversions' => 20.0],
                'deltas' => [
                    'cost' => ['absolute' => 100.0, 'percent' => 100.0],
                    'conversions' => ['absolute' => -18.0, 'percent' => -90.0],
                ],
            ],
        ]);
        Evidence::factory()->create([
            'run_id' => $again->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'google-ads',
            'type' => 'google_ads_campaign_performance',
            'payload' => ['response_ok' => true, 'rows' => []],
        ]);

        $reopen = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$again->fresh('evidence')]);
        $reopenStats = app(FindingLifecycleService::class)->apply($reopen);
        $this->assertGreaterThanOrEqual(1, $reopenStats['reopened']);
        $finding = Finding::query()->where('fingerprint', PerformanceFindingsCatalog::RULE_CONVERSIONS_DECLINE)->firstOrFail();
        $this->assertSame('open', $finding->status);
        $this->assertNull($finding->resolved_at);
        $this->assertTrue($finding->first_seen_at->equalTo($firstSeen));
    }

    public function test_collect_live_data_wires_ads_findings(): void
    {
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'dev-token',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
            'expires_at' => now()->addHour(),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => '1234567890',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['login_customer_id' => '9999999999'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        Http::fake(function ($request) {
            if (! str_contains($request->url(), 'googleAds:search')) {
                return Http::response(['error' => 'unexpected'], 500);
            }

            $query = strtolower((string) ($request->data()['query'] ?? ''));
            if (str_contains($query, 'ad_group_ad.ad.final_urls')) {
                return Http::response(['results' => []], 200);
            }

            if (str_contains($query, 'from campaign')) {
                return Http::response([
                    'results' => [[
                        'campaign' => ['id' => '42', 'name' => 'Spendy', 'status' => 'ENABLED'],
                        'metrics' => [
                            'costMicros' => '80000000',
                            'impressions' => '1000',
                            'clicks' => '50',
                            'ctr' => 0.05,
                            'conversions' => 0,
                        ],
                    ]],
                ], 200);
            }

            return Http::response([
                'results' => [[
                    'metrics' => [
                        'costMicros' => '80000000',
                        'impressions' => '1000',
                        'clicks' => '50',
                        'ctr' => 0.05,
                        'averageCpc' => '1600000',
                        'conversions' => 0,
                        'conversionsValue' => 0,
                    ],
                ]],
            ], 200);
        });

        $binding = CoreAssetBinding::query()
            ->with(['externalResource.integration', 'digitalAsset'])
            ->where('digital_asset_id', $asset->id)
            ->where('capability', 'google_ads')
            ->firstOrFail();

        $collector = app(BoundCollectorRegistry::class)->forCapability('google_ads');
        $this->assertNotNull($collector);
        $run = $collector->collect($binding);
        $this->assertSame('google-ads', $run->module_id);

        $evaluated = app(GoogleAdsPerformanceBoundEvidenceEvaluator::class)->evaluate($asset, [$run->fresh('evidence')]);
        $this->assertTrue($evaluated->evaluationSuccessful);
        $stats = app(FindingLifecycleService::class)->apply($evaluated);
        $this->assertGreaterThanOrEqual(1, $stats['opened']);
        $this->assertDatabaseHas('findings', [
            'digital_asset_id' => $asset->id,
            'fingerprint' => PerformanceFindingsCatalog::RULE_CAMPAIGN_SPEND_ZERO_CONVERSIONS.':42',
        ]);
    }

    public function test_operator_collect_now_routes_google_ads_to_collection_engine(): void
    {
        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'dev-token',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'atok',
                'refresh_token' => 'rtok',
            ],
            'expires_at' => now()->addHour(),
        ]);

        $asset = DigitalAsset::factory()->create(['type' => 'google_ads']);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'google_ads',
            'external_id' => '1234567890',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['login_customer_id' => '9999999999'],
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        config([
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-collection.queue_connection' => 'database',
        ]);

        $result = app(CollectLiveBoundDataService::class)->collect($asset->fresh());
        $this->assertTrue($result['ok'], (string) ($result['message'] ?? ''));
        $this->assertNotNull($result['collection_run_id']);
        $this->assertEmpty($result['runs']);
        $this->assertDatabaseHas('collection_runs', [
            'id' => $result['collection_run_id'],
            'digital_asset_id' => $asset->id,
        ]);
        $this->assertTrue(CollectionRun::query()->whereKey($result['collection_run_id'])->exists());
        $this->assertSame(0, Evidence::query()->where('type', 'google_ads_account_summary')->count());
        $this->assertSame(0, Run::query()->where('module_id', 'google-ads')->count());
    }
}
