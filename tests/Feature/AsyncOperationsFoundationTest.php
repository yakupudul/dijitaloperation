<?php

namespace Tests\Feature;

use App\Enums\DigitalAssetStatus;
use App\Filament\App\Resources\Customers\Resources\Brands\Resources\DigitalAssets\Pages\ViewDigitalAsset;
use App\Filament\App\Resources\Runs\Pages\ListRuns;
use App\Filament\App\Resources\Runs\Pages\ViewRun;
use App\Jobs\Async\CollectLiveBoundDataJob;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\Async\AsyncOperationService;
use App\Services\Integrations\CollectLiveBoundDataService;
use App\Support\Async\AsyncFailureClassifier;
use App\Support\Async\AsyncOperationTypes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

class AsyncOperationsFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $metaAsset;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');

        $customer = Customer::factory()->create();
        $this->brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->metaAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->brand->id,
            'type' => 'meta_ads',
            'status' => DigitalAssetStatus::Active,
            'name' => 'Meta Ads UAT',
        ]);
    }

    public function test_bound_collect_dispatches_job_without_calling_handle_from_ui(): void
    {
        Bus::fake();

        Livewire::test(ViewDigitalAsset::class, [
            'record' => $this->metaAsset->getRouteKey(),
            'parentRecord' => $this->brand,
        ])
            ->callAction('collectLiveData')
            ->assertNotified();

        Bus::assertDispatched(CollectLiveBoundDataJob::class);

        $run = Run::query()->where('digital_asset_id', $this->metaAsset->id)->latest('id')->first();
        $this->assertNotNull($run);
        $this->assertSame('queued', $run->status);
        $this->assertTrue((bool) data_get($run->metadata, 'async'));
        $this->assertSame(AsyncOperationTypes::BOUND_COLLECT, data_get($run->metadata, 'operation_type'));
    }

    public function test_queued_running_completed_lifecycle(): void
    {
        Bus::fake();
        $async = app(AsyncOperationService::class);
        $result = $async->queueBoundCollect($this->metaAsset, $this->admin);
        $this->assertTrue($result['queued']);
        $run = $result['run'];
        $this->assertSame('queued', $run->status);

        $async->markRunning($run, 'collecting', 'Collecting provider data');
        $run->refresh();
        $this->assertSame('running', $run->status);
        $this->assertSame('Collecting provider data', data_get($run->metadata, 'phase_label'));

        $async->markFinished($run->fresh() ?? $run, 'completed', 'Completed', [
            'result_summary' => 'Collected',
        ]);

        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame('Completed', data_get($run->metadata, 'phase_label'));
        $this->assertNotEmpty(data_get($run->metadata, 'stages'));
    }

    public function test_queued_running_partial_lifecycle(): void
    {
        Bus::fake();
        $async = app(AsyncOperationService::class);
        $run = $async->queueBoundCollect($this->metaAsset, $this->admin)['run'];
        $async->markRunning($run, 'collecting', 'Collecting provider data');

        $async->markFinished($run->fresh() ?? $run, 'partial', 'Completed with gaps', [
            'result_summary' => 'Creative stage failed',
            'child_run_ids' => [99],
            'retryable' => true,
        ]);

        $run->refresh();
        $this->assertSame('partial', $run->status);
        $this->assertTrue((bool) data_get($run->metadata, 'retryable'));
        $this->assertContains(99, data_get($run->metadata, 'child_run_ids'));
    }

    public function test_collect_job_marks_failed_without_bindings(): void
    {
        Bus::fake();
        $async = app(AsyncOperationService::class);
        $run = $async->queueBoundCollect($this->metaAsset, $this->admin)['run'];

        (new CollectLiveBoundDataJob($run->id))->handle(
            $async,
            app(CollectLiveBoundDataService::class),
        );

        $run->refresh();
        $this->assertContains($run->status, ['failed', 'partial']);
        $this->assertNotEmpty((string) (data_get($run->metadata, 'failure_summary') ?: data_get($run->metadata, 'result_summary')));
    }

    public function test_duplicate_concurrent_dispatch_is_blocked(): void
    {
        Bus::fake();
        $async = app(AsyncOperationService::class);
        $first = $async->queueBoundCollect($this->metaAsset, $this->admin);
        $second = $async->queueBoundCollect($this->metaAsset, $this->admin);

        $this->assertTrue($first['queued']);
        $this->assertFalse($second['queued']);
        $this->assertNotNull($second['existing_run']);
        $this->assertSame($first['run']->id, $second['existing_run']->id);
        Bus::assertDispatchedTimes(CollectLiveBoundDataJob::class, 1);
    }

    public function test_stale_run_marked_needs_attention(): void
    {
        $run = Run::factory()->create([
            'digital_asset_id' => $this->metaAsset->id,
            'module_id' => 'bound-collect',
            'status' => 'running',
            'started_at' => now()->subHours(2),
            'metadata' => [
                'async' => true,
                'operation_type' => AsyncOperationTypes::BOUND_COLLECT,
                'progress_at' => now()->subHours(2)->toIso8601String(),
                'phase_label' => 'Collecting',
            ],
        ]);

        $count = app(AsyncOperationService::class)->markStaleRuns(45);
        $this->assertSame(1, $count);
        $run->refresh();
        $this->assertSame('stale', data_get($run->metadata, 'needs_attention'));
        $this->assertSame('running', $run->status);
    }

    public function test_activity_center_lists_async_operation(): void
    {
        Bus::fake();
        $run = app(AsyncOperationService::class)->queueBoundCollect($this->metaAsset, $this->admin)['run'];

        Livewire::test(ListRuns::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$run]);
    }

    public function test_operation_detail_shows_operator_fields(): void
    {
        Bus::fake();
        $run = app(AsyncOperationService::class)->queueBoundCollect($this->metaAsset, $this->admin)['run'];

        Livewire::test(ViewRun::class, ['record' => $run->getRouteKey()])
            ->assertOk()
            ->assertSee('Collect live data')
            ->assertSee('Queued');
    }

    public function test_failure_classifier_sanitizes_secrets(): void
    {
        $classified = AsyncFailureClassifier::classify(
            new \RuntimeException('Authorization: Bearer EAAG.secret.token failed')
        );

        $this->assertSame(AsyncFailureClassifier::VALIDATION, $classified['category']);
        $this->assertStringNotContainsString('EAAG', $classified['summary']);
        $this->assertStringNotContainsString('Bearer', $classified['summary']);
    }

    public function test_transient_failures_are_retryable_category(): void
    {
        $classified = AsyncFailureClassifier::classify(new \RuntimeException('Connection timed out to graph.facebook.com'));
        $this->assertSame(AsyncFailureClassifier::TRANSIENT, $classified['category']);
        $this->assertTrue($classified['retryable']);
    }

    public function test_validation_failure_is_not_retryable_by_default(): void
    {
        $classified = AsyncFailureClassifier::classify(new \InvalidArgumentException('Credential missing for Meta'));
        $this->assertSame(AsyncFailureClassifier::VALIDATION, $classified['category']);
        $this->assertFalse($classified['retryable']);
    }

    public function test_retry_creates_new_run_preserving_original(): void
    {
        Bus::fake();
        $async = app(AsyncOperationService::class);
        $original = Run::factory()->create([
            'digital_asset_id' => $this->metaAsset->id,
            'module_id' => 'bound-collect',
            'status' => 'failed',
            'metadata' => [
                'async' => true,
                'operation_type' => AsyncOperationTypes::BOUND_COLLECT,
                'failure_category' => AsyncFailureClassifier::TRANSIENT,
                'retryable' => true,
                'human_title' => 'Collect live data',
            ],
        ]);

        $result = $async->retry($original, $this->admin);
        $this->assertTrue($result['queued']);
        $this->assertNotSame($original->id, $result['run']->id);
        $this->assertSame($original->id, data_get($result['run']->metadata, 'retry_of_run_id'));
        $original->refresh();
        $this->assertSame('failed', $original->status);
    }

    public function test_latest_completed_data_remains_while_new_run_active(): void
    {
        $completed = Run::factory()->create([
            'digital_asset_id' => $this->metaAsset->id,
            'module_id' => 'meta-ads',
            'status' => 'completed',
            'finished_at' => now()->subHour(),
            'metadata' => ['result_summary' => 'Prior successful collect'],
        ]);

        Bus::fake();
        $active = app(AsyncOperationService::class)->queueBoundCollect($this->metaAsset, $this->admin)['run'];

        $this->assertSame('completed', $completed->fresh()->status);
        $this->assertSame('queued', $active->status);
        $this->assertNotSame($completed->id, $active->id);
    }

    public function test_terminal_notification_persists_to_database(): void
    {
        NotificationFacade::fake();

        $run = Run::factory()->create([
            'digital_asset_id' => $this->metaAsset->id,
            'module_id' => 'bound-collect',
            'status' => 'running',
            'metadata' => [
                'async' => true,
                'human_title' => 'Collect live data',
                'triggered_by_user_id' => $this->admin->id,
                'stages' => [],
            ],
        ]);

        app(AsyncOperationService::class)->markFinished($run, 'completed', 'Completed', [
            'result_summary' => 'All good',
        ]);

        NotificationFacade::assertSentTo($this->admin, DatabaseNotification::class);
    }
}
