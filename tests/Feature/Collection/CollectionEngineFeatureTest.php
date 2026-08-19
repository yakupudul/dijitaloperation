<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DigitalAssetStatus;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CancellationService;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionErrorRecorder;
use App\Services\Collection\CollectionStateMachine;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\CollectionStatusQuery;
use App\Services\Collection\Contracts\RetryPolicy;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\ProgressReporter;
use App\Services\Collection\ResumeDatasetRunService;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Collection\Testing\FakeDatasetExecutor;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CollectionEngineFeatureTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private DigitalAsset $asset;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        config([
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-collection.queue' => 'collection',
        ]);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $this->asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'resource_type' => 'ga4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $this->binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'external_resource_id' => $resource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function start_collection_persists_plan_dispatches_job_and_returns_before_completion(): void
    {
        Queue::fake();

        $family = 'GA4_RF_PROPERTY_METADATA';
        $this->app->instance(
            DatasetExecutorResolver::class,
            new DatasetExecutorResolver([FakeDatasetExecutor::succeed($family)]),
        );

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->asset,
            triggerType: CollectionTriggerType::Manual,
            requestedBy: $this->admin,
            bindingIds: [$this->binding->id],
            requestFamilyIds: [$family],
        ));

        $this->assertNotNull($run->uuid);
        $this->assertSame(1, $run->contract_registry_version);
        $this->assertSame('MOXDOP_DATA_CONTRACT_REGISTRY', $run->contract_registry_id);
        $this->assertNotEmpty($run->contract_registry_checksum);
        $this->assertSame(1, $run->datasets_total);
        $this->assertDatabaseHas('collection_dataset_runs', [
            'collection_run_id' => $run->id,
            'request_family_id' => $family,
            'contract_registry_version' => 1,
        ]);

        Queue::assertPushed(ExecuteDatasetRunJob::class);
        $this->assertFalse($run->status->isTerminal());
    }

    #[Test]
    public function sibling_isolation_one_failure_does_not_block_other_success(): void
    {
        Queue::fake();

        $ok = 'GA4_RF_PROPERTY_METADATA';
        $bad = 'GA4_RF_CHANNEL_DAILY';

        $this->app->instance(
            DatasetExecutorResolver::class,
            new DatasetExecutorResolver([
                FakeDatasetExecutor::map([
                    $ok => DatasetExecutionResult::completed(2),
                    $bad => DatasetExecutionResult::failed(CollectionErrorCategory::InvalidRequest, 'boom'),
                ]),
            ]),
        );

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            requestFamilyIds: [$ok, $bad],
        ));

        foreach ($run->datasetRuns as $datasetRun) {
            (new ExecuteDatasetRunJob($datasetRun->id))->handle(
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

        $run->refresh();
        $statuses = $run->datasetRuns()->pluck('status')->map(fn ($s) => $s->value)->sort()->values()->all();
        $this->assertContains(CollectionRunStatus::Completed->value, $statuses);
        $this->assertContains(CollectionRunStatus::Failed->value, $statuses);
        $this->assertSame(CollectionRunStatus::Partial, $run->fresh()->status);
    }

    #[Test]
    public function cancellation_cancels_queued_children_and_does_not_auto_retry(): void
    {
        Queue::fake();

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            requestFamilyIds: ['GA4_RF_PROPERTY_METADATA'],
        ));

        $cancelled = app(CancellationService::class)->requestCancellation($run->fresh());
        $this->assertNotNull($cancelled->cancel_requested_at);
        $this->assertTrue(
            in_array($cancelled->status, [
                CollectionRunStatus::CancellationRequested,
                CollectionRunStatus::Cancelled,
                CollectionRunStatus::Partial,
            ], true),
        );

        foreach ($cancelled->datasetRuns as $datasetRun) {
            $this->assertSame(CollectionRunStatus::Cancelled, $datasetRun->status);
        }
    }

    #[Test]
    public function resume_and_replay_preserve_history(): void
    {
        Queue::fake();

        $family = 'GA4_RF_PROPERTY_METADATA';
        $this->app->instance(
            DatasetExecutorResolver::class,
            new DatasetExecutorResolver([
                FakeDatasetExecutor::fail(CollectionErrorCategory::Timeout, 'temp', $family),
            ]),
        );

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            requestFamilyIds: [$family],
        ));

        $dataset = $run->datasetRuns()->first();
        $dataset->forceFill([
            'status' => CollectionRunStatus::Failed,
            'attempt_count' => 1,
            'checkpoint' => ['page' => 3],
            'finished_at' => now(),
        ])->save();

        Queue::fake();
        $resumed = app(ResumeDatasetRunService::class)->resume($dataset->fresh());
        $this->assertSame(CollectionRunStatus::Queued, $resumed->status);
        $this->assertSame(['page' => 3], $resumed->checkpoint);
        Queue::assertPushed(ExecuteDatasetRunJob::class);
    }

    #[Test]
    public function resume_reopens_partial_collection_and_failed_resource(): void
    {
        Queue::fake();

        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->asset->id,
            'status' => CollectionRunStatus::Partial,
            'finished_at' => now(),
        ]);
        $resource = $run->resourceRuns()->create([
            'provider_or_source' => 'GA4',
            'resource_kind' => 'bound_provider_resource',
            'digital_asset_id' => $this->asset->id,
            'core_asset_binding_id' => $this->binding->id,
            'status' => CollectionRunStatus::Failed,
            'finished_at' => now(),
            'datasets_total' => 1,
            'datasets_failed' => 1,
        ]);
        $dataset = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resource->id,
            'status' => CollectionRunStatus::Failed,
            'attempt_count' => 1,
            'finished_at' => now(),
        ]);

        $resumed = app(ResumeDatasetRunService::class)->resume($dataset->fresh());

        $this->assertSame(CollectionRunStatus::Queued, $resumed->status);
        $this->assertSame(CollectionRunStatus::Queued, $resource->fresh()->status);
        $this->assertNull($resource->fresh()->finished_at);
        $this->assertSame(CollectionRunStatus::Queued, $run->fresh()->status);
        $this->assertNull($run->fresh()->finished_at);
        Queue::assertPushed(ExecuteDatasetRunJob::class);
    }

    #[Test]
    public function completed_dataset_cannot_be_resumed(): void
    {
        $completed = CollectionRun::factory()->create([
            'status' => CollectionRunStatus::Completed,
            'digital_asset_id' => $this->asset->id,
        ]);
        $this->expectException(\InvalidArgumentException::class);
        app(ResumeDatasetRunService::class)->resume(
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $completed->id,
                'status' => CollectionRunStatus::Completed,
            ]),
        );
    }

    #[Test]
    public function idempotent_start_with_same_key_returns_same_run(): void
    {
        Queue::fake();

        $request = new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            requestFamilyIds: ['GA4_RF_PROPERTY_METADATA'],
            idempotencyKey: 'idem-1',
        );

        $a = app(StartCollectionService::class)->start($request);
        $b = app(StartCollectionService::class)->start($request);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, CollectionRun::query()->count());
    }

    #[Test]
    public function planner_is_registry_driven_and_status_query_is_browser_independent(): void
    {
        Queue::fake();

        $loader = app(DataContractRegistryLoader::class);
        $this->assertSame(1, $loader->version());
        $this->assertNotEmpty($loader->requestFamilies());

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            requestFamilyIds: ['GA4_RF_PROPERTY_METADATA'],
        ));

        $payload = app(CollectionStatusQuery::class)->byUuid($run->uuid);
        $this->assertSame($run->uuid, $payload['uuid']);
        $this->assertSame(1, $payload['contract_registry_version']);
        $this->assertArrayHasKey('datasets', $payload);
        $this->assertSame('GA4_RF_PROPERTY_METADATA', $payload['datasets'][0]['request_family_id']);
    }

    #[Test]
    public function unimplemented_required_executor_fails_dataset_explicitly(): void
    {
        Queue::fake();

        $this->app->instance(DatasetExecutorResolver::class, new DatasetExecutorResolver([]));

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            requestFamilyIds: ['GA4_RF_PROPERTY_METADATA'],
        ));

        $dataset = $run->datasetRuns()->first();
        (new ExecuteDatasetRunJob($dataset->id))->handle(
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

        $dataset->refresh();
        $this->assertSame(CollectionRunStatus::Failed, $dataset->status);
        $this->assertSame(CollectionErrorCategory::UnimplementedCapability, $dataset->error_category);
        $this->assertSame(1, $dataset->attempts()->count());
    }

    #[Test]
    public function sync_queue_connection_is_rejected(): void
    {
        config(['moxdop-collection.queue_connection' => 'sync', 'moxdop-collection.require_queue_connection' => true]);

        $this->expectException(\RuntimeException::class);
        app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->asset,
            bindingIds: [$this->binding->id],
            requestFamilyIds: ['GA4_RF_PROPERTY_METADATA'],
        ));
    }
}
