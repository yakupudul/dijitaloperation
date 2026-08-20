<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\DigitalAssetStatus;
use App\Jobs\Collection\ExecuteDatasetRunJob;
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
use App\Models\User;
use App\Services\Collection\CancellationService;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionErrorRecorder;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\CollectionStateMachine;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\Contracts\RetryPolicy;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\ProgressReporter;
use App\Services\Collection\Providers\MetaAds\MetaAdsDatasetExecutor;
use App\Services\Collection\Providers\MetaAds\MetaAdsNormalizer;
use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use App\Services\Collection\Providers\MetaAds\MetaInsightsRetrievalStrategy;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsProductionCollectorTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private DigitalAsset $asset;

    private CoreIntegration $integration;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);

        Storage::fake('raw_ingestion');
        config([
            'app.url' => 'http://127.0.0.1:8000',
            'moxdop.meta.app_id' => '111222333',
            'moxdop.meta.app_secret' => 'test-meta-app-secret',
            'moxdop.meta.use_appsecret_proof' => false,
            'moxdop.meta.api_version' => 'v26.0',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
            ],
            'moxdop-meta-ads-collector.write_batch_size' => 100,
            'moxdop-meta-ads-collector.max_insight_pages_per_tick' => 50,
            'moxdop-meta-ads-collector.async_day_threshold' => [
                'campaign' => 90,
                'adset' => 45,
                'ad' => 30,
                'account' => 90,
            ],
            'moxdop-meta-ads-collector.async_poll_backoff_seconds' => 1,
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
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
            'external_id' => 'act_11110001',
            'display_name' => 'Synthetic Meta Ads',
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
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function planner_maps_meta_ads_families_and_defers_async_family(): void
    {
        $plan = app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));
        $this->assertSame('META_ADS', $plan['resources'][0]['provider_or_source']);
        $families = array_column($plan['datasets'], 'request_family_id');
        $this->assertContains(MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META, $families);
        $this->assertContains(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY, $families);
        $this->assertContains(MetaAdsRequestFamilyCatalog::FAMILY_TYPED_ACTIONS, $families);
        $this->assertNotContains('RF_META_ASYNC_INSIGHTS', $families);

        $deferred = collect($plan['dispositions'])->firstWhere('request_family_id', 'RF_META_ASYNC_INSIGHTS');
        $this->assertNotNull($deferred);
    }

    #[Test]
    public function unbound_account_is_not_collectable_and_business_is_rejected(): void
    {
        $this->binding->forceFill(['status' => CoreAssetBinding::STATUS_DISABLED])->save();
        $this->expectException(\InvalidArgumentException::class);
        app(CollectionPlanner::class)->plan(new StartCollectionRequest(
            digitalAsset: $this->asset,
            dateRange: ['start' => '2026-08-01', 'end' => '2026-08-02'],
        ));
    }

    #[Test]
    public function business_resource_is_not_analytical_collection_root(): void
    {
        $this->resource->forceFill([
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_999',
        ])->save();
        Http::fake();
        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('RESOURCE_TYPE_MISMATCH', $result->errorCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function permission_lost_blocks_provider_calls(): void
    {
        $this->integration->forceFill([
            'config' => array_merge($this->integration->config ?? [], [
                'granted_permissions' => ['business_management'],
                'credential_status' => 'valid',
            ]),
        ])->save();
        Http::fake();
        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META);
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame('PERMISSION_REQUIRED', $result->errorCode);
        Http::assertNothingSent();
    }

    #[Test]
    public function ad_account_metadata_preserves_currency_timezone_without_token_leak(): void
    {
        $this->fakeMetaHttp();
        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);

        $row = DB::table('meta_ad_account_snapshot')->first();
        $this->assertNotNull($row);
        $this->assertSame('11110001', $row->account_id);
        $this->assertSame('Europe/Berlin', $row->source_timezone);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame('EUR', $meta['currency']);
        $this->assertStringNotContainsString('EAAG-synthetic', (string) json_encode($meta));

        foreach (DB::table('raw_ingestion_objects')->get() as $raw) {
            $payload = json_encode($raw);
            $this->assertStringNotContainsString('EAAG-synthetic-meta-token-never-real', (string) $payload);
            $this->assertStringNotContainsString('test-meta-app-secret', (string) $payload);
        }
    }

    #[Test]
    public function entity_snapshot_keeps_campaign_adset_creative_distinct_and_objective_vs_optimization(): void
    {
        $this->fakeMetaHttp();
        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);

        $campaign = DB::table('meta_campaign_snapshot')->first();
        $adset = DB::table('meta_adset_snapshot')->first();
        $creative = DB::table('meta_creative_snapshot')->first();
        $this->assertNotNull($campaign);
        $this->assertNotNull($adset);
        $this->assertNotNull($creative);

        $cMeta = json_decode((string) $campaign->metadata, true);
        $aMeta = json_decode((string) $adset->metadata, true);
        $this->assertSame('OUTCOME_TRAFFIC', $cMeta['objective']);
        $this->assertSame('LINK_CLICKS', $aMeta['optimization_goal']);
        $this->assertNotEquals($cMeta['objective'], $aMeta['optimization_goal']);
        $this->assertSame('ACTIVE', $cMeta['status']);
        $this->assertSame('CAMPAIGN_PAUSED', $cMeta['effective_status']);
        $this->assertSame('10.000000', $cMeta['daily_budget']); // 1000 minor units / 100
        $this->assertNull($aMeta['daily_budget']); // campaign budget not copied
        $this->assertSame('WEBSITE', $aMeta['destination_type']);
        $this->assertTrue($aMeta['destination_neq_business_outcome']);

        $crMeta = json_decode((string) $creative->metadata, true);
        $this->assertFalse($crMeta['binary_media_downloaded']);
        $this->assertFalse($crMeta['instagram_digital_asset_created']);
        $this->assertFalse($crMeta['instagram_binding_created']);
        $this->assertSame(0, CoreAssetBinding::query()->where('capability', 'instagram')->count());
    }

    #[Test]
    public function campaign_rename_updates_same_natural_key(): void
    {
        $this->fakeMetaHttp();
        $first = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);
        $this->assertSame(DatasetExecutionOutcome::Completed, $first->outcome, (string) $first->errorMessage);
        $this->assertSame(1, DB::table('meta_campaign_snapshot')->count());
        $firstRunId = (int) DB::table('meta_campaign_snapshot')->value('last_dataset_run_id');

        $this->fakeMetaHttp([
            'handler' => function ($request) {
                if (str_contains($request->url(), 'campaigns')) {
                    return Http::response([
                        'data' => [[
                            'id' => '1001',
                            'name' => 'Renamed Campaign',
                            'objective' => 'OUTCOME_TRAFFIC',
                            'status' => 'ACTIVE',
                            'effective_status' => 'ACTIVE',
                            'buying_type' => 'AUCTION',
                            'daily_budget' => '1000',
                        ]],
                    ], 200);
                }

                return $this->defaultMetaResponse($request);
            },
        ]);

        $second = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);
        $this->assertSame(DatasetExecutionOutcome::Completed, $second->outcome, (string) $second->errorMessage);
        $this->assertSame(1, DB::table('meta_campaign_snapshot')->count());
        $row = DB::table('meta_campaign_snapshot')->first();
        $meta = json_decode((string) $row->metadata, true);
        $this->assertNotSame($firstRunId, (int) $row->last_dataset_run_id);
        $this->assertSame('Renamed Campaign', $meta['name']);
    }

    #[Test]
    public function campaign_daily_preserves_clicks_link_clicks_outbound_reach_and_timezone(): void
    {
        $this->fakeMetaHttp();
        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);

        $row = DB::table('meta_campaign_daily')->where('campaign_id', '1001')->first();
        $this->assertNotNull($row);
        $this->assertSame('2026-08-01', $row->reporting_date);
        $this->assertSame('Europe/Berlin', $row->source_timezone);
        $this->assertSame('EUR', $row->currency);
        $this->assertSame('12.340000', number_format((float) $row->spend, 6, '.', ''));
        $this->assertSame(100, (int) $row->impressions);
        $this->assertSame(10, (int) $row->clicks);
        $this->assertSame(80, (int) $row->reach);
        $meta = json_decode((string) $row->metadata, true);
        $this->assertSame(7, $meta['inline_link_clicks']);
        $this->assertSame(5, $meta['outbound_clicks']);
        $this->assertTrue($meta['clicks_neq_link_clicks_neq_outbound']);
        $this->assertTrue($meta['reach_non_additive']);
        $this->assertFalse($meta['google_ads_micros_assumption']);
        $this->assertFalse($meta['fx']);

        $this->assertGreaterThan(0, DB::table('meta_adset_daily')->count());
        $this->assertGreaterThan(0, DB::table('meta_ad_daily')->count());
    }

    #[Test]
    public function typed_actions_remain_distinct_and_never_become_generic_results(): void
    {
        $this->fakeMetaHttp();
        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_TYPED_ACTIONS, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);

        $types = DB::table('meta_typed_action_daily')
            ->where('entity_level', 'campaign')
            ->where('entity_id', '1001')
            ->pluck('action_type')
            ->sort()
            ->values()
            ->all();
        $this->assertSame(['lead', 'onsite_conversion.messaging_conversation_started_7d', 'purchase'], $types);
        $this->assertSame(3, DB::table('meta_typed_action_daily')->where('entity_level', 'campaign')->where('entity_id', '1001')->count());

        $lead = DB::table('meta_typed_action_daily')->where('action_type', 'lead')->first();
        $meta = json_decode((string) $lead->metadata, true);
        $this->assertTrue($meta['generic_results_forbidden']);
        $this->assertTrue($meta['action_neq_qualified_lead']);
        $this->assertTrue($meta['action_neq_business_outcome']);
        $this->assertFalse($meta['business_action_mapping_applied']);
        $this->assertSame('50.000000', $meta['action_value_amount']);
    }

    #[Test]
    public function budget_is_not_spend_and_money_is_not_google_micros(): void
    {
        $n = new MetaAdsNormalizer;
        $campaigns = $n->normalizeCampaignSnapshots('1', 'UTC', [[
            'id' => '9',
            'daily_budget' => '2500',
            'lifetime_budget' => '10000',
        ]], 1, 1);
        $this->assertSame('25.000000', $campaigns[0]['metadata']['daily_budget']);
        $this->assertSame('100.000000', $campaigns[0]['metadata']['lifetime_budget']);

        $daily = $n->normalizeInsightsDaily('1', 'UTC', 'campaign', [[
            'campaign_id' => '9',
            'date_start' => '2026-08-01',
            'spend' => '12.34',
            'impressions' => '1',
            'clicks' => '1',
            'account_currency' => 'EUR',
        ]], 1, 1);
        $this->assertSame('12.340000', $daily[0]['spend']);
        $this->assertFalse($daily[0]['metadata']['google_ads_micros_assumption']);
    }

    #[Test]
    public function reach_and_frequency_are_not_summed_by_collector(): void
    {
        $source = file_get_contents(app_path('Services/Collection/Providers/MetaAds/MetaAdsDatasetExecutor.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('array_sum($reach', $source);
        $this->assertStringNotContainsString('sum(reach', strtolower($source));
        $this->assertStringNotContainsString('avg(frequency', strtolower($source));
    }

    #[Test]
    public function sync_zero_rows_completes_distinct_from_failure(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/insights') && $request->method() === 'GET') {
                return Http::response(['data' => []], 200);
            }

            return $this->defaultMetaResponse($request);
        });

        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_SYNC, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $this->assertSame(0, DB::table('meta_campaign_daily')->count());
    }

    #[Test]
    public function async_submit_wait_download_does_not_complete_before_ingest(): void
    {
        $states = ['submit' => 0, 'poll' => 0];
        Http::fake(function ($request) use (&$states) {
            $url = $request->url();
            if (str_contains($url, '/insights') && $request->method() === 'POST') {
                $states['submit']++;

                return Http::response(['report_run_id' => '999888777'], 200);
            }
            if (str_contains($url, '999888777') && ! str_contains($url, '/insights')) {
                $states['poll']++;
                if ($states['poll'] < 2) {
                    return Http::response([
                        'id' => '999888777',
                        'async_status' => 'Job Running',
                        'async_percent_completion' => 64,
                    ], 200);
                }

                return Http::response([
                    'id' => '999888777',
                    'async_status' => 'Job Completed',
                    'async_percent_completion' => 100,
                ], 200);
            }
            if (str_contains($url, '999888777/insights')) {
                return Http::response([
                    'data' => [[
                        'campaign_id' => '1001',
                        'date_start' => '2026-08-01',
                        'date_stop' => '2026-08-01',
                        'spend' => '1.00',
                        'impressions' => '10',
                        'clicks' => '1',
                        'reach' => '8',
                        'account_currency' => 'EUR',
                        'actions' => [],
                    ]],
                ], 200);
            }

            return $this->defaultMetaResponse($request);
        });

        // Force async via preferred mode override in catalog path: breakdowns prefer async.
        config(['moxdop-meta-ads-collector.async_day_threshold.campaign' => 0]);
        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $this->assertGreaterThanOrEqual(1, $states['submit']);
        $this->assertGreaterThanOrEqual(2, $states['poll']);
        $this->assertGreaterThan(0, DB::table('meta_campaign_daily')->count());

        foreach (DB::table('raw_ingestion_objects')->get() as $raw) {
            $payload = (string) json_encode($raw);
            $this->assertStringNotContainsString('EAAG-synthetic-meta-token-never-real', $payload);
        }
    }

    #[Test]
    public function async_duplicate_submit_protection_reuses_report_run_id(): void
    {
        $submits = 0;
        Http::fake(function ($request) use (&$submits) {
            if (str_contains($request->url(), '/insights') && $request->method() === 'POST') {
                $submits++;

                return Http::response(['report_run_id' => '555'], 200);
            }
            if (str_contains($request->url(), '/555') && ! str_contains($request->url(), '/insights')) {
                return Http::response([
                    'id' => '555',
                    'async_status' => 'Job Completed',
                    'async_percent_completion' => 100,
                ], 200);
            }
            if (str_contains($request->url(), '555/insights')) {
                return Http::response(['data' => []], 200);
            }

            return $this->defaultMetaResponse($request);
        });

        config(['moxdop-meta-ads-collector.async_day_threshold.account' => 0]);
        [$ctx, $datasetRun] = $this->makeContext(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_BREAKDOWN, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $executor = app(MetaAdsDatasetExecutor::class);

        $first = $executor->execute($ctx);
        $this->assertSame(DatasetExecutionOutcome::Continue, $first->outcome);
        $this->assertSame('WAITING_PROVIDER', $first->stage);
        $reportId = $first->checkpoint['async']['report_run_id'] ?? null;
        $this->assertSame('555', $reportId);

        // Simulate at-least-once re-entry with same fingerprint/report id already checkpointed.
        $second = $executor->execute(new DatasetExecutionContext(
            collectionRun: $ctx->collectionRun,
            resourceRun: $ctx->resourceRun,
            datasetRun: $datasetRun->fresh(),
            checkpoint: $first->checkpoint ?? [],
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 2,
        ));
        $this->assertSame(DatasetExecutionOutcome::Continue, $second->outcome);
        $this->assertSame(1, $submits, 'duplicate submit must reuse checkpointed report_run_id');
    }

    #[Test]
    public function natural_key_idempotency_and_late_correction(): void
    {
        $this->fakeMetaHttp();
        $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $before = DB::table('meta_campaign_daily')->count();

        $this->fakeMetaHttp([
            'handler' => function ($request) {
                $url = $request->url();
                $data = $request->data();
                $level = (string) ($data['level'] ?? '');
                if ($level === '' && preg_match('/[?&]level=([^&]+)/', $url, $m) === 1) {
                    $level = urldecode($m[1]);
                }
                if (str_contains($url, '/insights') && $level === 'campaign') {
                    return Http::response([
                        'data' => [[
                            'campaign_id' => '1001',
                            'date_start' => '2026-08-01',
                            'date_stop' => '2026-08-01',
                            'spend' => '99.99',
                            'impressions' => '100',
                            'clicks' => '10',
                            'reach' => '80',
                            'frequency' => '1.25',
                            'inline_link_clicks' => '7',
                            'outbound_clicks' => [['value' => '5']],
                            'account_currency' => 'EUR',
                            'actions' => [],
                        ]],
                    ], 200);
                }

                return $this->defaultMetaResponse($request);
            },
        ]);

        $second = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $second->outcome, (string) $second->errorMessage);
        $this->assertSame($before, DB::table('meta_campaign_daily')->where('campaign_id', '1001')->count());
        $row = DB::table('meta_campaign_daily')->where('campaign_id', '1001')->first();
        $this->assertSame('99.990000', number_format((float) $row->spend, 6, '.', ''));
    }

    #[Test]
    public function entity_snapshot_resumes_from_step_index_without_skipping_or_duplicating(): void
    {
        Queue::fake();
        $this->fakeMetaHttp();
        [$context, $datasetRun] = $this->makeContext(MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);

        $this->handleDatasetJob($datasetRun);
        $this->assertFalse($datasetRun->fresh()->status->isTerminal());
        $this->assertSame(1, (int) ($datasetRun->fresh()->checkpoint['step_index'] ?? 0));
        $this->assertSame(1, DB::table('meta_campaign_snapshot')->count());
        $this->assertSame(0, DB::table('meta_adset_snapshot')->count());
        $this->assertSame(0, DB::table('meta_creative_snapshot')->count());

        $replay = app(MetaAdsDatasetExecutor::class)->execute(new DatasetExecutionContext(
            collectionRun: $context->collectionRun->fresh(),
            resourceRun: $context->resourceRun->fresh(),
            datasetRun: $datasetRun->fresh(),
            checkpoint: ['step_index' => 0],
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 2,
        ));
        $this->assertSame(DatasetExecutionOutcome::Continue, $replay->outcome);
        $this->assertSame(1, DB::table('meta_campaign_snapshot')->count());
        $this->assertSame(1, (int) ($replay->checkpoint['step_index'] ?? 0));

        $this->handleDatasetJob($datasetRun->fresh());
        $this->assertSame(1, DB::table('meta_adset_snapshot')->count());
        $this->assertSame(2, (int) ($datasetRun->fresh()->checkpoint['step_index'] ?? 0));
        $this->assertSame('adsets', $datasetRun->fresh()->checkpoint['last_step'] ?? null);

        $this->handleDatasetJob($datasetRun->fresh());
        $this->handleDatasetJob($datasetRun->fresh());
        $this->assertSame(CollectionRunStatus::Completed, $datasetRun->fresh()->status);
        $this->assertSame(1, DB::table('meta_campaign_snapshot')->count());
        $this->assertSame(1, DB::table('meta_adset_snapshot')->count());
        $this->assertSame(1, DB::table('meta_creative_snapshot')->count());
    }

    #[Test]
    public function entity_snapshot_partial_ads_failure_keeps_committed_siblings_and_is_not_marked_complete(): void
    {
        Queue::fake();
        $this->fakeMetaHttp();
        [, $datasetRun] = $this->makeContext(MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);

        $this->handleDatasetJob($datasetRun);
        $this->handleDatasetJob($datasetRun->fresh());
        $this->assertSame(1, DB::table('meta_campaign_snapshot')->count());
        $this->assertSame(1, DB::table('meta_adset_snapshot')->count());

        $this->fakeMetaHttp([
            'handler' => function ($request) {
                if (str_contains($request->url(), '/ads') && ! str_contains($request->url(), '/insights')) {
                    return Http::response(['error' => ['message' => 'ads boom', 'code' => 1]], 500);
                }

                return $this->defaultMetaResponse($request);
            },
        ]);

        $this->handleDatasetJob($datasetRun->fresh());
        $failed = $datasetRun->fresh();
        $this->assertNotSame(CollectionRunStatus::Completed, $failed->status);
        $this->assertTrue(in_array($failed->status, [
            CollectionRunStatus::Failed,
            CollectionRunStatus::Retrying,
            CollectionRunStatus::Queued,
        ], true));
        $this->assertSame(2, (int) ($failed->checkpoint['step_index'] ?? -1), 'failed ads step must not advance past unfinished work');
        $this->assertSame(1, DB::table('meta_campaign_snapshot')->count());
        $this->assertSame(1, DB::table('meta_adset_snapshot')->count());
        $this->assertSame(0, DB::table('meta_creative_snapshot')->count());
    }

    #[Test]
    public function insights_daily_resumes_work_index_without_duplicating_or_overwriting_snapshot(): void
    {
        Queue::fake();
        $this->fakeMetaHttp();
        $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);
        $snapshotCampaigns = DB::table('meta_campaign_snapshot')->count();
        $snapshotAdsets = DB::table('meta_adset_snapshot')->count();
        $snapshotCreatives = DB::table('meta_creative_snapshot')->count();

        config(['moxdop-meta-ads-collector.date_slice_days.RF_META_INSIGHTS_DAILY' => 1]);
        [$context, $datasetRun] = $this->makeContext(
            MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY,
            ['start' => '2026-08-01', 'end' => '2026-08-02'],
        );

        $this->handleDatasetJob($datasetRun);
        $this->assertFalse($datasetRun->fresh()->status->isTerminal());
        $this->assertSame(1, (int) ($datasetRun->fresh()->checkpoint['work_index'] ?? 0));
        $this->assertSame(1, DB::table('meta_campaign_daily')->where('reporting_date', '2026-08-01')->count());
        $this->assertSame(0, DB::table('meta_adset_daily')->count());
        $this->assertSame($snapshotCampaigns, DB::table('meta_campaign_snapshot')->count());

        $replay = app(MetaAdsDatasetExecutor::class)->execute(new DatasetExecutionContext(
            collectionRun: $context->collectionRun->fresh(),
            resourceRun: $context->resourceRun->fresh(),
            datasetRun: $datasetRun->fresh(),
            checkpoint: ['work_index' => 0, 'timezone' => 'Europe/Berlin', 'currency' => 'EUR'],
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 2,
        ));
        $this->assertSame(DatasetExecutionOutcome::Continue, $replay->outcome);
        $this->assertSame(1, DB::table('meta_campaign_daily')->where('campaign_id', '1001')->count());
        $this->assertNotEmpty($datasetRun->fresh()->checkpoint['request_fingerprint'] ?? $replay->checkpoint['request_fingerprint'] ?? null);

        $raw = DB::table('raw_ingestion_objects')->get();
        $this->assertTrue($raw->contains(fn ($row): bool => str_contains((string) json_encode($row), 'fb-req-2026-08-01-campaign')));

        $this->handleDatasetJob($datasetRun->fresh());
        $this->assertSame(1, DB::table('meta_adset_daily')->where('reporting_date', '2026-08-01')->count());
        $this->assertSame(1, DB::table('meta_campaign_daily')->where('reporting_date', '2026-08-01')->count());

        $this->fakeMetaHttp([
            'handler' => function ($request) {
                $data = $request->data();
                if (str_contains($request->url(), '/insights') && ($data['level'] ?? '') === 'ad') {
                    return Http::response(['error' => ['message' => 'ad insights boom', 'code' => 1]], 500);
                }

                return $this->defaultMetaResponse($request);
            },
        ]);
        $this->handleDatasetJob($datasetRun->fresh());
        $status = $datasetRun->fresh()->status;
        $this->assertNotSame(CollectionRunStatus::Completed, $status);
        $this->assertTrue(in_array($status, [
            CollectionRunStatus::Failed,
            CollectionRunStatus::Retrying,
            CollectionRunStatus::Queued,
        ], true));
        $this->assertSame(2, (int) ($datasetRun->fresh()->checkpoint['work_index'] ?? -1), 'failed ad insights tick must not skip past the unfinished work item');
        $this->assertSame(1, DB::table('meta_campaign_daily')->count());
        $this->assertSame(1, DB::table('meta_adset_daily')->count());
        $this->assertSame(0, DB::table('meta_ad_daily')->count());
        $this->assertSame($snapshotCampaigns, DB::table('meta_campaign_snapshot')->count());
        $this->assertSame($snapshotAdsets, DB::table('meta_adset_snapshot')->count());
        $this->assertSame($snapshotCreatives, DB::table('meta_creative_snapshot')->count());
    }

    #[Test]
    public function breakdown_family_requests_only_contract_dimensions(): void
    {
        $seen = [];
        Http::fake(function ($request) use (&$seen) {
            if (str_contains($request->url(), '/insights')) {
                $data = $request->data();
                if (isset($data['breakdowns'])) {
                    $seen[] = $data['breakdowns'];
                }
                if ($request->method() === 'POST') {
                    return Http::response(['report_run_id' => 'bd-1'], 200);
                }
            }
            if (str_contains($request->url(), 'bd-1') && ! str_contains($request->url(), '/insights')) {
                return Http::response([
                    'async_status' => 'Job Completed',
                    'async_percent_completion' => 100,
                ], 200);
            }
            if (str_contains($request->url(), 'bd-1/insights')) {
                return Http::response([
                    'data' => [[
                        'date_start' => '2026-08-01',
                        'spend' => '1',
                        'impressions' => '1',
                        'clicks' => '0',
                        'age' => '25-34',
                        'gender' => 'female',
                        'publisher_platform' => 'facebook',
                        'account_currency' => 'EUR',
                    ]],
                ], 200);
            }

            return $this->defaultMetaResponse($request);
        });

        $result = $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_BREAKDOWN, ['start' => '2026-08-01', 'end' => '2026-08-01']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $result->errorMessage);
        $this->assertContains('age', $seen);
        $this->assertContains('gender', $seen);
        $this->assertContains('publisher_platform', $seen);
        $this->assertNotContains('country', $seen);
        $this->assertNotContains('device_platform', $seen);
    }

    #[Test]
    public function collector_does_not_reference_physical_table_writes_directly(): void
    {
        $source = file_get_contents(app_path('Services/Collection/Providers/MetaAds/MetaAdsDatasetExecutor.php'));
        $this->assertIsString($source);
        $this->assertStringNotContainsString('DB::table(', $source);
        $this->assertStringContainsString('NormalizedDatasetBatch', $source);
        $this->assertStringContainsString('DatasetWritePipeline', $source);
    }

    #[Test]
    public function no_lead_pii_or_provider_mutation_endpoints(): void
    {
        $urls = [];
        Http::fake(function ($request) use (&$urls) {
            $urls[] = $request->method().' '.$request->url();

            return $this->defaultMetaResponse($request);
        });

        $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT);
        $this->runFamily(MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY, ['start' => '2026-08-01', 'end' => '2026-08-01']);

        $joined = implode("\n", $urls);
        $this->assertStringNotContainsString('/leads', $joined);
        $this->assertStringNotContainsString('customaudiences', strtolower($joined));
        $this->assertStringNotContainsString('/messages', $joined);
        foreach ($urls as $url) {
            if (str_starts_with($url, 'POST ')) {
                $this->assertStringContainsString('/insights', $url, 'Only read-only async Insights POST is allowed');
            }
            $this->assertDoesNotMatchRegularExpression('/\b(PATCH|DELETE)\b/', $url);
        }
    }

    #[Test]
    public function strategy_prefers_async_for_high_cardinality_long_ranges(): void
    {
        $strategy = new MetaInsightsRetrievalStrategy;
        $this->assertSame(
            MetaInsightsRetrievalStrategy::MODE_SYNC,
            $strategy->resolve(['preferred_mode' => 'sync', 'high_cardinality' => false], 'campaign', 7),
        );
        $this->assertSame(
            MetaInsightsRetrievalStrategy::MODE_ASYNC,
            $strategy->resolve(['preferred_mode' => 'async', 'high_cardinality' => true], 'account', 3),
        );
        $this->assertSame(
            MetaInsightsRetrievalStrategy::MODE_ASYNC,
            $strategy->resolve(['preferred_mode' => 'sync_then_async', 'high_cardinality' => true], 'ad', 31),
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeMetaHttp(array $overrides = []): void
    {
        // Http::fake() merges stubs — swap a fresh factory so later fakes replace earlier ones.
        $factory = new Factory;
        Http::swap($factory);
        $factory->fake(function ($request) use ($overrides) {
            return $this->defaultMetaResponse($request, $overrides);
        });
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function defaultMetaResponse($request, array $overrides = []): PromiseInterface|Response
    {
        $url = $request->url();
        $method = $request->method();

        if (isset($overrides['handler']) && is_callable($overrides['handler'])) {
            return $overrides['handler']($request);
        }

        if ($method === 'POST' && str_contains($url, '/insights')) {
            return Http::response(['report_run_id' => 'async-1'], 200);
        }

        if (preg_match('#/act_\d+$#', parse_url($url, PHP_URL_PATH) ?? '') === 1 || str_ends_with(rtrim(parse_url($url, PHP_URL_PATH) ?? '', '/'), 'act_11110001')) {
            if (! str_contains($url, '/campaigns') && ! str_contains($url, '/adsets') && ! str_contains($url, '/ads') && ! str_contains($url, '/insights') && ! str_contains($url, '/adcreatives')) {
                return Http::response([
                    'id' => 'act_11110001',
                    'name' => 'Synthetic Meta Ads',
                    'account_status' => 1,
                    'currency' => 'EUR',
                    'timezone_name' => 'Europe/Berlin',
                    'business' => ['id' => 'biz_1', 'name' => 'Synthetic Biz'],
                ], 200);
            }
        }

        if (str_contains($url, '/campaigns')) {
            return Http::response([
                'data' => [[
                    'id' => '1001',
                    'name' => 'Traffic Campaign',
                    'objective' => 'OUTCOME_TRAFFIC',
                    'status' => 'ACTIVE',
                    'effective_status' => 'CAMPAIGN_PAUSED',
                    'buying_type' => 'AUCTION',
                    'daily_budget' => '1000',
                    'lifetime_budget' => null,
                    'budget_remaining' => '500',
                    'start_time' => '2026-07-01T00:00:00+0200',
                    'stop_time' => null,
                ]],
            ], 200);
        }

        if (str_contains($url, '/adsets')) {
            return Http::response([
                'data' => [[
                    'id' => '2001',
                    'name' => 'Ad Set A',
                    'campaign_id' => '1001',
                    'optimization_goal' => 'LINK_CLICKS',
                    'billing_event' => 'IMPRESSIONS',
                    'destination_type' => 'WEBSITE',
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                    'daily_budget' => null,
                    'lifetime_budget' => null,
                ]],
            ], 200);
        }

        if (str_contains($url, '/ads') && ! str_contains($url, '/insights')) {
            return Http::response([
                'data' => [[
                    'id' => '3001',
                    'name' => 'Ad A',
                    'campaign_id' => '1001',
                    'adset_id' => '2001',
                    'status' => 'ACTIVE',
                    'effective_status' => 'ACTIVE',
                    'creative' => ['id' => '4001'],
                ]],
            ], 200);
        }

        if (str_contains($url, '/adcreatives')) {
            return Http::response([
                'data' => [[
                    'id' => '4001',
                    'name' => 'Creative A',
                    'object_type' => 'SHARE',
                    'status' => 'ACTIVE',
                    'title' => 'Title',
                    'body' => 'Body copy',
                    'call_to_action_type' => 'LEARN_MORE',
                    'link_url' => 'https://example.test/landing',
                    'thumbnail_url' => 'https://example.test/thumb.jpg',
                    'image_hash' => 'abc',
                    'instagram_actor_id' => 'ig_99',
                    'object_story_spec' => [
                        'page_id' => 'page_1',
                        'link_data' => ['link' => 'https://example.test/landing'],
                    ],
                ]],
            ], 200);
        }

        if (str_contains($url, '/insights')) {
            $data = $request->data();
            $level = (string) ($data['level'] ?? 'campaign');
            $since = $this->insightSince($request);
            $row = [
                'date_start' => $since,
                'date_stop' => $since,
                'spend' => '12.34',
                'impressions' => '100',
                'clicks' => '10',
                'reach' => '80',
                'frequency' => '1.25',
                'inline_link_clicks' => '7',
                'outbound_clicks' => [['action_type' => 'outbound_click', 'value' => '5']],
                'account_currency' => 'EUR',
                'request_id' => 'fb-req-'.$since.'-'.$level,
                'actions' => [
                    ['action_type' => 'lead', 'value' => '10'],
                    ['action_type' => 'onsite_conversion.messaging_conversation_started_7d', 'value' => '7'],
                    ['action_type' => 'purchase', 'value' => '2'],
                ],
                'action_values' => [
                    ['action_type' => 'lead', 'value' => '50'],
                    ['action_type' => 'purchase', 'value' => '120'],
                ],
            ];
            if ($level === 'campaign') {
                $row['campaign_id'] = '1001';
            } elseif ($level === 'adset') {
                $row['adset_id'] = '2001';
                $row['campaign_id'] = '1001';
            } elseif ($level === 'ad') {
                $row['ad_id'] = '3001';
                $row['adset_id'] = '2001';
                $row['campaign_id'] = '1001';
            }

            return Http::response(['data' => [$row], 'request_id' => 'fb-req-'.$since.'-'.$level], 200);
        }

        return Http::response(['error' => ['message' => 'unexpected '.$url, 'code' => 1]], 500);
    }

    /**
     * @param  array{start: string, end: string}|null  $dateRange
     */
    private function runFamily(string $family, ?array $dateRange = null): DatasetExecutionResult
    {
        [$executionContext, $datasetRun] = $this->makeContext($family, $dateRange);
        $executor = app(MetaAdsDatasetExecutor::class);
        $result = $executor->execute($executionContext);
        $guard = 0;
        while ($result->outcome === DatasetExecutionOutcome::Continue && $guard < 120) {
            $guard++;
            if ($result->checkpoint !== null) {
                app(CheckpointManager::class)->advance($datasetRun, $result->checkpoint);
            }
            $result = $executor->execute(new DatasetExecutionContext(
                collectionRun: $executionContext->collectionRun->fresh(),
                resourceRun: $executionContext->resourceRun->fresh(),
                datasetRun: $datasetRun->fresh(),
                checkpoint: $result->checkpoint ?? [],
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: $guard + 1,
            ));
        }

        return $result;
    }

    /**
     * @param  array{start: string, end: string}|null  $dateRange
     * @return array{0: DatasetExecutionContext, 1: CollectionDatasetRun}
     */
    private function makeContext(string $family, ?array $dateRange = null): array
    {
        $dateRange ??= ['start' => '2026-08-01', 'end' => '2026-08-02'];
        $definition = MetaAdsRequestFamilyCatalog::definition($family);

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'brand_id' => $this->brand->id,
            'customer_id' => $this->brand->customer_id,
            'status' => CollectionRunStatus::Running,
            'request_context' => [
                'date_range' => $dateRange,
            ],
        ]);

        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'META_ADS',
            'external_resource_id' => $this->resource->id,
            'digital_asset_id' => $this->asset->id,
            'core_asset_binding_id' => $this->binding->id,
            'status' => CollectionRunStatus::Running,
        ]);

        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'META_ADS',
            'dataset_contract_id' => $definition['dataset_ids'][0] ?? $family,
            'request_family_id' => $family,
            'contract_registry_version' => 1,
            'status' => CollectionRunStatus::Running,
            'metadata' => [
                'date_range' => $dateRange,
            ],
        ]);

        return [
            new DatasetExecutionContext(
                collectionRun: $run,
                resourceRun: $resourceRun,
                datasetRun: $datasetRun,
                checkpoint: [],
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: 1,
            ),
            $datasetRun,
        ];
    }

    private function handleDatasetJob(CollectionDatasetRun $datasetRun): void
    {
        (new ExecuteDatasetRunJob((int) $datasetRun->id))->handle(
            app(DatasetExecutorResolver::class),
            app(DataContractRegistryLoader::class),
            app(CollectionStateMachine::class),
            app(CollectionStatusAggregator::class),
            app(CollectionErrorRecorder::class),
            app(CheckpointManager::class),
            app(ProgressReporter::class),
            app(RetryPolicy::class),
            app(CancellationService::class),
            app(StartCollectionService::class),
        );
    }

    private function insightSince($request): string
    {
        $data = $request->data();
        $range = json_decode((string) ($data['time_range'] ?? ''), true);
        if (is_array($range) && is_string($range['since'] ?? null) && $range['since'] !== '') {
            return $range['since'];
        }

        return '2026-08-01';
    }
}
