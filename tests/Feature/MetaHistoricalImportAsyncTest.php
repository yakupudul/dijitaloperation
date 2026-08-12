<?php

namespace Tests\Feature;

use App\Jobs\Async\MetaHistoricalAccountImportJob;
use App\Jobs\Async\MetaHistoricalImportJob;
use App\Jobs\Async\MetaHistoricalRefreshJob;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\Meta\MetaException;
use App\Services\Integrations\Meta\MetaResourceDiscoveryService;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use MoxDop\MetaAds\History\MetaHistoricalImportProgress;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\History\MetaHistoricalRetry;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use MoxDop\MetaAds\Models\MetaAdsHistoryCoverage;
use Tests\TestCase;

class MetaHistoricalImportAsyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    private CoreExternalResource $accountOne;

    private CoreExternalResource $accountTwo;

    private string $historyDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);

        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);

        $this->accountOne = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_1001',
            'display_name' => 'Account One',
            'metadata' => ['currency' => 'USD', 'timezone_name' => 'UTC'],
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->accountTwo = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_2001',
            'display_name' => 'Account Two',
            'metadata' => ['currency' => 'USD', 'timezone_name' => 'UTC'],
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        // A day comfortably inside the provider history window.
        $this->historyDate = now('UTC')->subMonths(2)->toDateString();

        config(['moxdop.meta.access_token' => 'EAAG-synthetic-only']);
    }

    public function test_queue_creates_integration_scoped_run(): void
    {
        Bus::fake();

        $result = app(AsyncOperationService::class)->queueMetaHistoryImport($this->integration, $this->admin);

        $this->assertTrue($result['queued']);
        $run = $result['run'];
        $this->assertNull($run->digital_asset_id);
        $this->assertSame($this->integration->id, $run->core_integration_id);
        $this->assertSame(AsyncOperationTypes::META_HISTORY_IMPORT, data_get($run->metadata, 'operation_type'));
        $this->assertSame('Meta history import', data_get($run->metadata, 'human_title'));
        $this->assertTrue((bool) data_get($run->metadata, 'async'));

        Bus::assertDispatched(MetaHistoricalImportJob::class);
    }

    public function test_import_completes_upserts_facts_and_is_idempotent(): void
    {
        $this->fakeMetaGraph();

        $run = $this->queueImportRun();

        $this->runImportJob($run);

        $run->refresh();
        $this->assertSame('completed', $run->status);

        // Facts upserted for both accounts, at least the account-level rows.
        $this->assertGreaterThan(0, MetaAdsDailyFact::query()->where('core_external_resource_id', $this->accountOne->id)->count());
        $this->assertGreaterThan(0, MetaAdsDailyFact::query()->where('core_external_resource_id', $this->accountTwo->id)->count());

        // Never auto-binds a Digital Asset.
        $this->assertSame(0, CoreAssetBinding::query()->count());

        // Coverage marked complete for daily facts.
        $coverage = MetaAdsHistoryCoverage::query()
            ->where('core_external_resource_id', $this->accountOne->id)
            ->where('data_layer', MetaAdsHistoryCoverage::LAYER_DAILY_FACTS)
            ->first();
        $this->assertNotNull($coverage);
        $this->assertSame(MetaAdsHistoryCoverage::STATUS_COMPLETE, $coverage->status);

        $countAfterFirst = MetaAdsDailyFact::query()->count();

        // Idempotent re-run does not duplicate facts.
        $run2 = $this->queueImportRun();
        $this->runImportJob($run2);

        $this->assertSame($countAfterFirst, MetaAdsDailyFact::query()->count());
    }

    public function test_all_discovered_available_resources_are_selected(): void
    {
        // A disabled account must be excluded from import selection.
        $unavailable = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => 'act_9999',
            'display_name' => 'Retired Account',
            'status' => CoreExternalResource::STATUS_UNAVAILABLE,
        ]);

        $accounts = app(MetaHistoricalImportService::class)->discoverAccountsForImport($this->integration);

        $ids = $accounts->pluck('external_id')->all();
        $this->assertContains('act_1001', $ids);
        $this->assertContains('act_2001', $ids);
        $this->assertNotContains($unavailable->external_id, $ids);
    }

    public function test_partial_when_one_account_is_forbidden(): void
    {
        $this->fakeMetaGraph(forbidAccount: 'act_2001');

        $run = $this->queueImportRun();
        $this->runImportJob($run);

        $run->refresh();
        $this->assertSame('partial', $run->status);

        // Healthy account data is preserved.
        $this->assertGreaterThan(0, MetaAdsDailyFact::query()->where('core_external_resource_id', $this->accountOne->id)->count());

        $results = data_get($run->metadata, 'account_results');
        $this->assertSame('failed', data_get($results, 'act_2001.status'));
        $this->assertNotSame('failed', data_get($results, 'act_1001.status'));
    }

    public function test_rate_limit_is_retried_by_retry_helper(): void
    {
        Sleep::fake();

        $attempts = 0;
        $value = MetaHistoricalRetry::attempt(function () use (&$attempts): array {
            $attempts++;
            if ($attempts < 2) {
                throw new MetaException('Rate limited.', kind: MetaException::KIND_RATE_LIMIT, httpStatus: 429);
            }

            return ['data' => ['ok']];
        });

        $this->assertSame(2, $attempts);
        $this->assertSame(['data' => ['ok']], $value);
    }

    public function test_auth_failure_is_not_retried(): void
    {
        Sleep::fake();

        $attempts = 0;
        try {
            MetaHistoricalRetry::attempt(function () use (&$attempts): array {
                $attempts++;

                throw new MetaException('Authentication failed.', kind: MetaException::KIND_AUTH, httpStatus: 401);
            });
            $this->fail('Expected MetaException to be thrown.');
        } catch (MetaException $exception) {
            $this->assertSame(MetaException::KIND_AUTH, $exception->kind);
        }

        $this->assertSame(1, $attempts);
    }

    public function test_refresh_does_not_delete_older_facts(): void
    {
        $this->fakeMetaGraph();

        // Seed a historical fact well before the correction window.
        $olderDate = now('UTC')->subMonths(6)->toDateString();
        MetaAdsDailyFact::query()->create([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->accountOne->id,
            'entity_type' => 'account',
            'provider_external_id' => 'act_1001',
            'date' => $olderDate,
            'spend' => 12.34,
            'impressions' => 500,
        ]);

        // Pre-existing coverage so the refresh has a bound account context.
        MetaAdsHistoryCoverage::query()->create([
            'core_integration_id' => $this->integration->id,
            'core_external_resource_id' => $this->accountOne->id,
            'data_layer' => MetaAdsHistoryCoverage::LAYER_DAILY_FACTS,
            'granularity' => 'day',
            'status' => MetaAdsHistoryCoverage::STATUS_COMPLETE,
            'start_date' => $olderDate,
            'end_date' => now('UTC')->subDay()->toDateString(),
        ]);

        $asset = $this->boundAsset($this->accountOne);

        Bus::fake([MetaHistoricalRefreshJob::class]);
        $run = app(AsyncOperationService::class)->queueMetaHistoryRefresh($asset, $this->admin)['run'];
        $this->assertNotNull($run);

        app(MetaHistoricalRefreshJob::class, ['runId' => $run->id])->handle(
            app(AsyncOperationService::class),
            app(MetaHistoricalImportService::class),
        );

        $run->refresh();
        $this->assertContains($run->status, ['completed', 'partial']);

        // The older fact is untouched.
        $this->assertDatabaseHas('meta_ads_daily_facts', [
            'core_external_resource_id' => $this->accountOne->id,
            'date' => $olderDate,
            'entity_type' => 'account',
        ]);

        $coverage = MetaAdsHistoryCoverage::query()
            ->where('core_external_resource_id', $this->accountOne->id)
            ->where('data_layer', MetaAdsHistoryCoverage::LAYER_DAILY_FACTS)
            ->first();
        $this->assertNotNull($coverage->last_successful_sync_at);
    }

    private function boundAsset(CoreExternalResource $resource): DigitalAsset
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'meta_ads',
            'name' => 'Bound Meta Asset',
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'meta_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        return $asset;
    }

    private function queueImportRun(): Run
    {
        Bus::fake([MetaHistoricalImportJob::class]);

        $run = app(AsyncOperationService::class)->queueMetaHistoryImport($this->integration, $this->admin)['run'];
        $this->assertNotNull($run);

        return $run;
    }

    private function runImportJob(Run $run): void
    {
        // Bound window so tests do not walk the full 37-month provider range.
        $run->update([
            'metadata' => array_merge($run->metadata ?? [], [
                'import_from' => $this->historyDate,
                'import_to' => $this->historyDate,
            ]),
        ]);

        app(MetaHistoricalImportJob::class, ['runId' => $run->id])->handle(
            app(AsyncOperationService::class),
            app(MetaHistoricalImportService::class),
            app(MetaResourceDiscoveryService::class),
            app(MetaHistoricalImportProgress::class),
        );

        // Orchestrator dispatches per-account jobs via Bus::batch (sync in tests).
        // If the queue driver did not drain them, run each account job explicitly.
        $run->refresh();
        if (! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            foreach ([$this->accountOne, $this->accountTwo] as $account) {
                if ($account->status !== CoreExternalResource::STATUS_AVAILABLE) {
                    continue;
                }
                app(MetaHistoricalAccountImportJob::class, [
                    'parentRunId' => $run->id,
                    'externalResourceId' => $account->id,
                    'window' => [
                        'from' => $this->historyDate,
                        'to' => $this->historyDate,
                    ],
                ])->handle(
                    app(AsyncOperationService::class),
                    app(MetaHistoricalImportService::class),
                    app(MetaHistoricalImportProgress::class),
                );
            }
        }
    }

    private function fakeMetaGraph(?string $forbidAccount = null): void
    {
        $date = $this->historyDate;

        Http::fake(function (Request $request) use ($date, $forbidAccount): mixed {
            $url = $request->url();
            $query = $request->data();

            if (str_contains($url, '/me/adaccounts')) {
                return Http::response(['data' => [
                    ['account_id' => '1001', 'id' => 'act_1001', 'name' => 'Account One', 'currency' => 'USD', 'timezone_name' => 'UTC'],
                    ['account_id' => '2001', 'id' => 'act_2001', 'name' => 'Account Two', 'currency' => 'USD', 'timezone_name' => 'UTC'],
                ]], 200);
            }

            if (str_contains($url, '/me/businesses')) {
                return Http::response(['data' => []], 200);
            }

            if (str_contains($url, '/insights')) {
                if ($forbidAccount !== null && str_contains($url, $forbidAccount)) {
                    return Http::response(['error' => ['code' => 200, 'message' => 'Permission missing']], 403);
                }

                // Exact-period reach/frequency enrichment.
                if (($query['fields'] ?? '') === 'reach,frequency') {
                    return Http::response(['data' => [['reach' => '400', 'frequency' => '2.0']]], 200);
                }

                $level = (string) ($query['level'] ?? 'account');

                $row = match ($level) {
                    'campaign' => [
                        'campaign_id' => 'camp-1', 'campaign_name' => 'Campaign 1',
                        'impressions' => '1000', 'spend' => '50', 'clicks' => '40',
                        'date_start' => $date, 'date_stop' => $date,
                    ],
                    'adset' => [
                        'adset_id' => 'adset-1', 'campaign_id' => 'camp-1',
                        'impressions' => '500', 'spend' => '25',
                        'date_start' => $date, 'date_stop' => $date,
                    ],
                    'ad' => [
                        'ad_id' => 'ad-1', 'adset_id' => 'adset-1',
                        'impressions' => '250', 'spend' => '12',
                        'date_start' => $date, 'date_stop' => $date,
                    ],
                    default => [
                        'account_id' => str_contains($url, 'act_2001') ? '2001' : '1001',
                        'impressions' => '1000', 'spend' => '50', 'clicks' => '40',
                        'actions' => [['action_type' => 'lead', 'value' => '5']],
                        'date_start' => $date, 'date_stop' => $date,
                    ],
                };

                return Http::response(['data' => [$row]], 200);
            }

            if (str_contains($url, '/campaigns')) {
                return Http::response(['data' => [['id' => 'camp-1', 'name' => 'Campaign 1', 'effective_status' => 'ACTIVE', 'objective' => 'LEADS']]], 200);
            }

            if (str_contains($url, '/adsets')) {
                return Http::response(['data' => [['id' => 'adset-1', 'name' => 'Ad Set 1', 'campaign_id' => 'camp-1', 'effective_status' => 'ACTIVE']]], 200);
            }

            if (str_contains($url, '/ads')) {
                return Http::response(['data' => [['id' => 'ad-1', 'name' => 'Ad 1', 'adset_id' => 'adset-1', 'effective_status' => 'ACTIVE']]], 200);
            }

            return Http::response(['data' => []], 200);
        });
    }
}
