<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\ProgressMode;
use App\Enums\Collection\RequirementLevel;
use App\Enums\DataPool\MaterializationStatus;
use App\Events\Collection\CollectionRunChanged;
use App\Events\Collection\CollectionRunCompleted;
use App\Livewire\Collection\MonitoringPanel;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\Monitoring\CollectionProgressPresenter;
use App\Services\Collection\Monitoring\CollectionRunMonitorQuery;
use App\Services\Collection\Monitoring\CollectionStatusPresenter;
use App\Services\Collection\ProgressReporter;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CollectionMonitoringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
    }

    #[Test]
    public function active_summary_uses_dataset_plan_completion_not_fake_transfer_percent(): void
    {
        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Running,
            'requested_by_user_id' => $this->admin->id,
            'datasets_total' => 11,
            'started_at' => now()->subMinutes(5),
            'last_activity_at' => now(),
        ]);

        $gsc = $this->resource($run, 'SEARCH_CONSOLE');
        $this->datasets($gsc, [
            CollectionRunStatus::Completed,
            CollectionRunStatus::Completed,
            CollectionRunStatus::Completed,
        ]);

        $ga4 = $this->resource($run, 'GA4');
        $this->datasets($ga4, [
            CollectionRunStatus::Completed,
            CollectionRunStatus::Completed,
            CollectionRunStatus::Retrying,
        ]);

        $ads = $this->resource($run, 'GOOGLE_ADS');
        $this->datasets($ads, [
            CollectionRunStatus::Completed,
            CollectionRunStatus::Completed,
            CollectionRunStatus::Queued,
            CollectionRunStatus::Queued,
            CollectionRunStatus::Queued,
        ]);

        $summary = app(CollectionRunMonitorQuery::class)->summary($run->fresh(['resourceRuns.datasetRuns']));

        $byProvider = collect($summary['resources'])->keyBy('provider_or_source');
        $this->assertSame(100.0, $byProvider['SEARCH_CONSOLE']['plan_completion']['percentage']);
        $this->assertSame(66.7, $byProvider['GA4']['plan_completion']['percentage']);
        $this->assertSame(40.0, $byProvider['GOOGLE_ADS']['plan_completion']['percentage']);
        $this->assertSame('DATASET_PLAN_COMPLETION', $byProvider['GA4']['plan_completion']['type']);
        $this->assertSame(1, $summary['summary']['datasets_retrying']);
        $this->assertNotEmpty($summary['exceptions']);
    }

    #[Test]
    public function indeterminate_progress_never_fabricates_percentage(): void
    {
        $dataset = CollectionDatasetRun::factory()->create([
            'status' => CollectionRunStatus::Running,
            'progress_mode' => ProgressMode::Indeterminate,
            'progress_current' => null,
            'progress_total' => null,
            'rows_received' => 24820,
            'rows_written' => 24820,
        ]);

        $progress = app(CollectionProgressPresenter::class)->datasetTransferProgress($dataset);
        $this->assertSame('INDETERMINATE', $progress['type']);
        $this->assertNull($progress['percentage']);
        $this->assertFalse($progress['allows_percentage']);
        $this->assertSame(24820, $progress['rows_received']);
        $this->assertNull($dataset->percentage());
    }

    #[Test]
    public function counted_and_page_based_progress_expose_known_percent_only(): void
    {
        $counted = CollectionDatasetRun::factory()->create([
            'progress_mode' => ProgressMode::Counted,
            'progress_current' => 25,
            'progress_total' => 100,
        ]);
        $this->assertSame(25.0, $counted->percentage());

        $page = CollectionDatasetRun::factory()->create([
            'progress_mode' => ProgressMode::PageBased,
            'progress_current' => 12,
            'progress_total' => 20,
        ]);
        app(ProgressReporter::class)->report($page, ProgressMode::PageBased, current: 12, total: 20);
        $this->assertSame(60.0, $page->fresh()->percentage());

        $unknownPage = CollectionDatasetRun::factory()->create([
            'progress_mode' => ProgressMode::PageBased,
            'progress_current' => 12,
            'progress_total' => null,
            'pages_completed' => 12,
        ]);
        $this->assertNull($unknownPage->percentage());
    }

    #[Test]
    public function gsc_retrying_while_siblings_continue_then_partial_after_retry_exhausted(): void
    {
        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Running,
            'requested_by_user_id' => $this->admin->id,
            'started_at' => now()->subMinutes(3),
            'last_activity_at' => now(),
            'datasets_total' => 3,
            'resources_total' => 3,
        ]);

        $gsc = $this->resource($run, 'SEARCH_CONSOLE');
        $ga4 = $this->resource($run, 'GA4');
        $ads = $this->resource($run, 'GOOGLE_ADS');

        $gscQueryPage = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $gsc->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'dataset_contract_id' => 'gsc_query_page_daily',
            'request_family_id' => 'GSC_RF_QUERY_PAGE_DAILY',
            'requirement_level' => RequirementLevel::Required,
            'status' => CollectionRunStatus::Retrying,
            'attempt_count' => 2,
            'max_attempts' => 3,
            'retry_at' => now()->addSeconds(30),
            'error_category' => CollectionErrorCategory::RateLimit,
            'error_message' => 'temporary quota',
            'progress_mode' => ProgressMode::Indeterminate,
        ]);

        $ga4Dataset = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $ga4->id,
            'provider_or_source' => 'GA4',
            'dataset_contract_id' => 'ga4_property_daily',
            'request_family_id' => 'GA4_RF_PROPERTY_DAILY',
            'requirement_level' => RequirementLevel::Required,
            'status' => CollectionRunStatus::Running,
            'progress_mode' => ProgressMode::Indeterminate,
        ]);

        $adsDataset = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $ads->id,
            'provider_or_source' => 'GOOGLE_ADS',
            'dataset_contract_id' => 'google_ads_campaign_daily',
            'request_family_id' => 'GADS_RF_CAMPAIGN_DAILY',
            'requirement_level' => RequirementLevel::Required,
            'status' => CollectionRunStatus::Running,
            'progress_mode' => ProgressMode::Indeterminate,
        ]);

        foreach ([$gsc, $ga4, $ads] as $resource) {
            $resource->forceFill(['datasets_total' => 1, 'status' => CollectionRunStatus::Running])->save();
        }

        // Mid-flight: GSC query×page RETRYING while GA4 + Google Ads continue.
        app(CollectionStatusAggregator::class)->refreshFromDataset($gscQueryPage->fresh());
        $mid = app(CollectionRunMonitorQuery::class)->summary($run->fresh(['resourceRuns.datasetRuns']));
        $this->assertFalse($mid['is_terminal']);
        $this->assertSame(1, $mid['summary']['datasets_retrying']);
        $this->assertSame('running', $mid['status']['key']); // siblings still active — run continues
        $byProvider = collect($mid['resources'])->keyBy('provider_or_source');
        $this->assertSame('retrying', $byProvider['SEARCH_CONSOLE']['datasets_retrying'] > 0 ? 'retrying' : 'no');
        $this->assertGreaterThan(0, $byProvider['SEARCH_CONSOLE']['datasets_retrying']);

        // Retry exhausted → GSC FAILED; siblings COMPLETED → CollectionRun PARTIAL.
        $gscQueryPage->forceFill([
            'status' => CollectionRunStatus::Failed,
            'attempt_count' => 3,
            'retry_at' => null,
            'finished_at' => now(),
            'error_message' => 'Retry exhausted',
        ])->save();
        $ga4Dataset->forceFill([
            'status' => CollectionRunStatus::Completed,
            'rows_written' => 100,
            'finished_at' => now(),
        ])->save();
        $adsDataset->forceFill([
            'status' => CollectionRunStatus::Completed,
            'rows_written' => 50,
            'finished_at' => now(),
        ])->save();

        $aggregator = app(CollectionStatusAggregator::class);
        $aggregator->refreshFromDataset($gscQueryPage->fresh());
        $aggregator->refreshFromDataset($ga4Dataset->fresh());
        $aggregator->refreshFromDataset($adsDataset->fresh());

        $run->refresh();
        $this->assertSame(CollectionRunStatus::Failed, $gscQueryPage->fresh()->status);
        $this->assertSame(CollectionRunStatus::Completed, $ga4Dataset->fresh()->status);
        $this->assertSame(CollectionRunStatus::Completed, $adsDataset->fresh()->status);
        $this->assertSame(CollectionRunStatus::Partial, $run->status);

        $final = app(CollectionRunMonitorQuery::class)->detail($run->fresh([
            'resourceRuns.datasetRuns.attempts',
            'requestedBy:id,name',
        ]));
        $this->assertSame('partial', $final['status']['key']);
        $this->assertFalse($final['summary']['plan_completion']['success_only']);
        $this->assertSame(2, $final['summary']['plan_completion']['completed']);
        $this->assertSame(3, $final['summary']['plan_completion']['total']);
        $this->assertNotSame(100.0, $final['summary']['plan_completion']['percentage']);

        $datasetsById = collect($final['resources'])->flatMap(fn ($r) => $r['datasets'])->keyBy('dataset_contract_id');
        $this->assertSame('failed', $datasetsById['gsc_query_page_daily']['status']['key']);
        $this->assertSame('completed', $datasetsById['ga4_property_daily']['status']['key']);
        $this->assertSame('completed', $datasetsById['google_ads_campaign_daily']['status']['key']);
    }

    #[Test]
    public function partial_run_is_not_success_100_percent(): void
    {
        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Partial,
            'finished_at' => now(),
        ]);
        $resource = $this->resource($run, 'GA4');
        $this->datasets($resource, [
            CollectionRunStatus::Completed,
            CollectionRunStatus::Completed,
            CollectionRunStatus::Failed,
        ]);

        $plan = app(CollectionProgressPresenter::class)->runPlanCompletion($run->fresh(['datasetRuns']));
        $this->assertFalse($plan['success_only']);
        $this->assertSame(2, $plan['completed']);
        $this->assertSame(3, $plan['total']);
        $this->assertNotSame(100.0, $plan['percentage']);
    }

    #[Test]
    public function browser_independent_state_and_polling_payload(): void
    {
        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Queued,
            'requested_by_user_id' => $this->admin->id,
            'uuid' => '11111111-1111-1111-1111-111111111111',
        ]);
        $resource = $this->resource($run, 'GA4');
        $dataset = $this->datasets($resource, [CollectionRunStatus::Queued])[0];

        // Simulate later worker progress without any browser session.
        $dataset->forceFill([
            'status' => CollectionRunStatus::Completed,
            'rows_written' => 10,
            'finished_at' => now(),
        ])->save();
        $run->forceFill([
            'status' => CollectionRunStatus::Completed,
            'datasets_completed' => 1,
            'finished_at' => now(),
        ])->save();

        $poll = app(CollectionRunMonitorQuery::class)->pollPayload($run->uuid, $this->admin);
        $this->assertTrue($poll['is_terminal']);
        $this->assertSame('completed', $poll['status']['key']);
        $this->assertArrayNotHasKey('attempts', $poll['resources'][0]['datasets'][0]);
    }

    #[Test]
    public function materialization_distinct_from_failed_refresh(): void
    {
        $asset = DigitalAsset::factory()->create();
        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Failed,
            'digital_asset_id' => $asset->id,
            'finished_at' => now(),
        ]);
        DatasetMaterialization::query()->create([
            'dataset_id' => 'ga4_property_daily',
            'digital_asset_id' => $asset->id,
            'external_resource_id' => null,
            'provider_or_source' => 'GA4',
            'contract_version' => 1,
            'coverage_start_date' => '2026-08-01',
            'coverage_end_date' => '2026-08-12',
            'status' => MaterializationStatus::Stale,
            'partial' => false,
            'row_count_approx' => 100,
            'last_collected_at' => now()->subDay(),
        ]);

        $detail = app(CollectionRunMonitorQuery::class)->detail($run->fresh());
        $this->assertSame('failed', $detail['materialization']['latest_run_status']['key']);
        $this->assertSame('STALE', $detail['materialization']['pool']['status']);
        $this->assertSame('2026-08-12', $detail['materialization']['pool']['coverage_end_date']);
        $this->assertNotNull($detail['materialization']['note']);
    }

    #[Test]
    public function history_paginates_terminal_runs_only(): void
    {
        CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Running,
            'requested_by_user_id' => $this->admin->id,
        ]);
        CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Completed,
            'requested_by_user_id' => $this->admin->id,
            'finished_at' => now(),
        ]);
        CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Failed,
            'requested_by_user_id' => $this->admin->id,
            'finished_at' => now(),
        ]);

        $history = app(CollectionRunMonitorQuery::class)->history($this->admin, [], 15);
        $this->assertSame(2, $history->total());
        $active = app(CollectionRunMonitorQuery::class)->activeSummaries($this->admin);
        $this->assertCount(1, $active);
    }

    #[Test]
    public function authorization_denies_cross_tenant_view(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Roles::TEAM_MEMBER);
        $other = User::factory()->create();
        $other->assignRole(Roles::TEAM_MEMBER);

        $run = CollectionRun::factory()->create([
            'requested_by_user_id' => $owner->id,
            'status' => CollectionRunStatus::Completed,
            'finished_at' => now(),
        ]);

        $this->assertTrue($owner->can('view', $run));
        $this->assertFalse($other->can('view', $run));

        $this->expectException(ModelNotFoundException::class);
        // Scoped query returns nothing for other user — detailByUuid uses scoped() which filters.
        // Actually scoped for non-admin filters requested_by — will throw ModelNotFoundException.
        app(CollectionRunMonitorQuery::class)->detailByUuid($run->uuid, $other);
    }

    #[Test]
    public function error_sanitization_and_zero_record_success(): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Completed]);
        $resource = $this->resource($run, 'SEARCH_CONSOLE');
        $dataset = $this->datasets($resource, [CollectionRunStatus::Completed])[0];
        $dataset->forceFill([
            'rows_written' => 0,
            'rows_received' => 0,
            'error_message' => 'Bearer SECRETTOKEN failed',
            'error_category' => CollectionErrorCategory::RateLimit,
        ])->save();

        $detail = app(CollectionRunMonitorQuery::class)->detail($run->fresh([
            'resourceRuns.datasetRuns.attempts',
        ]));
        $message = $detail['resources'][0]['datasets'][0]['error']['message'];
        $this->assertStringContainsString('[redacted]', (string) $message);
        $this->assertStringNotContainsString('SECRETTOKEN', (string) $message);
        $this->assertSame(0, $detail['resources'][0]['datasets'][0]['progress']['rows_written']);
    }

    #[Test]
    public function broadcast_event_payload_is_minimal_and_private(): void
    {
        Event::fake([CollectionRunChanged::class]);

        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Completed,
            'finished_at' => now(),
            'requested_by_user_id' => $this->admin->id,
        ]);

        CollectionRunCompleted::dispatch($run);

        Event::assertDispatched(CollectionRunChanged::class, function (CollectionRunChanged $event) use ($run): bool {
            $payload = $event->broadcastWith();

            return $event->collectionRunUuid === $run->uuid
                && isset($payload['uuid'], $payload['status'])
                && ! array_key_exists('access_token', $payload)
                && ! array_key_exists('resources', $payload);
        });
    }

    #[Test]
    public function livewire_panel_lists_active_runs_without_provider_calls(): void
    {
        $run = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Running,
            'requested_by_user_id' => $this->admin->id,
            'started_at' => now(),
            'last_activity_at' => now(),
        ]);
        $resource = $this->resource($run, 'GA4');
        $this->datasets($resource, [CollectionRunStatus::Running]);

        Livewire::actingAs($this->admin)
            ->test(MonitoringPanel::class)
            ->assertSee(__('operator.collection.active_heading'))
            ->assertSee(__('operator.collection.providers.ga4'))
            ->call('reloadStatus')
            ->assertSet('statusError', null);
    }

    #[Test]
    public function queued_dataset_does_not_show_zero_percent_as_started_work(): void
    {
        $dataset = CollectionDatasetRun::factory()->create([
            'status' => CollectionRunStatus::Queued,
            'progress_mode' => ProgressMode::Indeterminate,
            'progress_current' => null,
            'progress_total' => null,
            'rows_written' => 0,
        ]);
        $progress = app(CollectionProgressPresenter::class)->datasetTransferProgress($dataset);
        $this->assertNull($progress['percentage']);
        $status = app(CollectionStatusPresenter::class)->present(CollectionRunStatus::Queued);
        $this->assertSame('queued', $status['key']);
    }

    /**
     * @param  list<CollectionRunStatus>  $statuses
     * @return list<CollectionDatasetRun>
     */
    private function datasets(CollectionResourceRun $resource, array $statuses): array
    {
        $out = [];
        foreach ($statuses as $i => $status) {
            $out[] = CollectionDatasetRun::factory()->create([
                'collection_run_id' => $resource->collection_run_id,
                'collection_resource_run_id' => $resource->id,
                'provider_or_source' => $resource->provider_or_source,
                'dataset_contract_id' => 'test_dataset_'.$i,
                'request_family_id' => 'RF_'.$i,
                'requirement_level' => RequirementLevel::Required,
                'status' => $status,
                'progress_mode' => ProgressMode::Indeterminate,
                'rows_written' => $status === CollectionRunStatus::Completed ? 5 : 0,
            ]);
        }

        $resource->forceFill([
            'datasets_total' => count($statuses),
            'datasets_completed' => collect($statuses)->filter(fn ($s) => $s === CollectionRunStatus::Completed)->count(),
            'datasets_failed' => collect($statuses)->filter(fn ($s) => $s === CollectionRunStatus::Failed)->count(),
        ])->save();

        return $out;
    }

    private function resource(CollectionRun $run, string $provider): CollectionResourceRun
    {
        return CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => $provider,
            'status' => CollectionRunStatus::Running,
            'digital_asset_id' => $run->digital_asset_id,
        ]);
    }
}
