<?php

namespace Tests\Feature;

use App\Jobs\Async\MetaHistoricalAccountImportJob;
use App\Jobs\Async\MetaHistoricalImportJob;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\Meta\MetaResourceDiscoveryService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use MoxDop\MetaAds\History\MetaHistoricalImportProgress;
use MoxDop\MetaAds\History\MetaHistoricalImportService;
use MoxDop\MetaAds\Models\MetaAdsAccountImportState;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use Tests\TestCase;

class MetaHistoricalImportProgressTest extends TestCase
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

        $this->accountOne = $this->makeAccount('act_1001', 'Account One');
        $this->accountTwo = $this->makeAccount('act_2001', 'Account Two');

        $this->historyDate = now('UTC')->subMonths(2)->toDateString();

        config(['moxdop.meta.access_token' => 'EAAG-synthetic-only']);
    }

    public function test_discovered_count_matches_available_resources_only(): void
    {
        $progress = app(MetaHistoricalImportProgress::class);

        $this->assertSame(2, $progress->authoritativeDiscoveredCount($this->integration));
    }

    public function test_unavailable_resources_are_excluded_from_discovered(): void
    {
        $this->makeAccount('act_9999', 'Retired Account', CoreExternalResource::STATUS_UNAVAILABLE);

        // A non-meta_ads resource under the same Integration must never be counted.
        CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'instagram',
            'external_id' => 'ig_5555',
            'display_name' => 'Instagram Account',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $progress = app(MetaHistoricalImportProgress::class);

        $this->assertSame(2, $progress->authoritativeDiscoveredCount($this->integration));
    }

    public function test_ensure_states_does_not_invent_accounts(): void
    {
        $this->makeAccount('act_9999', 'Retired Account', CoreExternalResource::STATUS_UNAVAILABLE);

        $progress = app(MetaHistoricalImportProgress::class);
        $progress->ensureStatesForDiscovered($this->integration);

        $this->assertSame(2, MetaAdsAccountImportState::query()->count());
        $this->assertSame(
            [MetaAdsAccountImportState::STATUS_WAITING],
            MetaAdsAccountImportState::query()->distinct()->pluck('status')->all(),
        );
    }

    public function test_overall_summary_ready_and_total_are_consistent(): void
    {
        $progress = app(MetaHistoricalImportProgress::class);
        $progress->ensureStatesForDiscovered($this->integration);

        $progress->markReady($this->accountOne);
        $progress->markQueued($this->accountTwo);

        $summary = $progress->overallSummary($this->integration);

        $this->assertSame(2, $summary['discovered']);
        $this->assertSame(1, $summary['ready']);
        $this->assertSame(1, $summary['queued']);
        $this->assertSame('1 / 2 accounts ready', $summary['accounts_ready_label']);

        // The label denominator can never disagree with the discovered count.
        $this->assertStringContainsString('/ '.$summary['discovered'].' accounts ready', $summary['accounts_ready_label']);
        $this->assertLessThanOrEqual($summary['discovered'], $summary['ready']);
    }

    public function test_summary_never_counts_retired_account_state(): void
    {
        $progress = app(MetaHistoricalImportProgress::class);
        $progress->ensureStatesForDiscovered($this->integration);
        $progress->markReady($this->accountOne);
        $progress->markReady($this->accountTwo);

        // Retire account two after it was imported: it must drop out of both the
        // numerator and denominator so ready can never exceed discovered.
        $this->accountTwo->update(['status' => CoreExternalResource::STATUS_UNAVAILABLE]);

        $summary = $progress->overallSummary($this->integration);

        $this->assertSame(1, $summary['discovered']);
        $this->assertSame(1, $summary['ready']);
        $this->assertSame('1 / 1 account ready', $summary['accounts_ready_label']);
    }

    public function test_one_account_failure_leaves_others_ready_and_parent_partial(): void
    {
        $this->fakeMetaGraph(forbidAccount: 'act_2001');

        $run = $this->runOrchestrator();

        $run->refresh();
        $this->assertSame('partial', $run->status);

        $stateOne = $this->stateFor($this->accountOne);
        $stateTwo = $this->stateFor($this->accountTwo);

        $this->assertContains($stateOne->status, [
            MetaAdsAccountImportState::STATUS_READY,
            MetaAdsAccountImportState::STATUS_PARTIAL,
        ]);
        $this->assertContains($stateTwo->status, [
            MetaAdsAccountImportState::STATUS_FAILED,
            MetaAdsAccountImportState::STATUS_NEEDS_ATTENTION,
        ]);
        $this->assertNotNull($stateTwo->last_error_summary);

        $summary = app(MetaHistoricalImportProgress::class)->overallSummary($this->integration);
        $this->assertSame(2, $summary['discovered']);
        $this->assertGreaterThanOrEqual(1, $summary['ready'] + $summary['partial']);
        $this->assertSame(1, $summary['failed']);
    }

    public function test_states_persist_across_reload(): void
    {
        $progress = app(MetaHistoricalImportProgress::class);
        $progress->ensureStatesForDiscovered($this->integration);
        $progress->markReady($this->accountOne);

        $this->assertDatabaseHas('meta_ads_account_import_states', [
            'core_external_resource_id' => $this->accountOne->id,
            'status' => MetaAdsAccountImportState::STATUS_READY,
        ]);

        $reloaded = MetaAdsAccountImportState::query()
            ->where('core_external_resource_id', $this->accountOne->id)
            ->firstOrFail();

        $this->assertSame(MetaAdsAccountImportState::STATUS_READY, $reloaded->status);
        $this->assertSame($this->integration->id, (int) $reloaded->core_integration_id);
    }

    public function test_import_creates_no_asset_binding(): void
    {
        $this->fakeMetaGraph();

        $this->runOrchestrator();

        $this->assertSame(0, CoreAssetBinding::query()->count());
    }

    public function test_accounts_total_on_run_equals_discovered_after_orchestrator(): void
    {
        $this->fakeMetaGraph();

        $run = $this->runOrchestrator();
        $run->refresh();

        $discovered = app(MetaHistoricalImportProgress::class)->authoritativeDiscoveredCount($this->integration);

        $this->assertSame(2, $discovered);
        $this->assertSame($discovered, (int) data_get($run->metadata, 'accounts_total'));
        $this->assertSame($discovered, (int) data_get($run->metadata, 'accounts_done'));

        // Facts landed for both accounts and states are terminal-ready.
        $this->assertGreaterThan(0, MetaAdsDailyFact::query()->where('core_external_resource_id', $this->accountOne->id)->count());
        $this->assertSame(MetaAdsAccountImportState::STATUS_READY, $this->stateFor($this->accountOne)->status);
        $this->assertSame(MetaAdsAccountImportState::STATUS_READY, $this->stateFor($this->accountTwo)->status);
    }

    private function stateFor(CoreExternalResource $resource): MetaAdsAccountImportState
    {
        return MetaAdsAccountImportState::query()
            ->where('core_external_resource_id', $resource->id)
            ->firstOrFail();
    }

    private function makeAccount(string $externalId, string $name, string $status = CoreExternalResource::STATUS_AVAILABLE): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => ProviderRegistry::META,
            'resource_type' => 'meta_ads',
            'external_id' => $externalId,
            'display_name' => $name,
            'metadata' => ['currency' => 'USD', 'timezone_name' => 'UTC'],
            'status' => $status,
        ]);
    }

    private function runOrchestrator(): Run
    {
        Bus::fake([MetaHistoricalImportJob::class]);

        $run = app(AsyncOperationService::class)->queueMetaHistoryImport($this->integration, $this->admin)['run'];
        $this->assertNotNull($run);

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

        // Fallback for queue drivers that do not drain dispatched account jobs.
        $run->refresh();
        if (! in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            foreach ([$this->accountOne, $this->accountTwo] as $account) {
                if ($account->status !== CoreExternalResource::STATUS_AVAILABLE) {
                    continue;
                }
                app(MetaHistoricalAccountImportJob::class, [
                    'parentRunId' => $run->id,
                    'externalResourceId' => $account->id,
                    'window' => ['from' => $this->historyDate, 'to' => $this->historyDate],
                ])->handle(
                    app(AsyncOperationService::class),
                    app(MetaHistoricalImportService::class),
                    app(MetaHistoricalImportProgress::class),
                );
            }
        }

        return $run;
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
