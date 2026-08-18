<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Livewire\Demo\Integrations\MetaIntegrationPage;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CancellationService;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionErrorRecorder;
use App\Services\Collection\CollectionStateMachine;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\Contracts\RetryPolicy;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\Meta\MetaInitialBackfillOrchestrator;
use App\Services\Collection\Monitoring\CollectionProgressPresenter;
use App\Services\Collection\Monitoring\CollectionRunMonitorQuery;
use App\Services\Collection\ProgressReporter;
use App\Services\Collection\Providers\MetaAds\MetaAdsRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Collection\Testing\FakeDatasetExecutor;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaInitialBackfillOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    private Brand $brandA;

    private Brand $brandB;

    private DigitalAsset $assetA;

    private DigitalAsset $assetB;

    private CoreAssetBinding $bindingA;

    private CoreAssetBinding $bindingB;

    private CoreExternalResource $resourceA;

    private CoreExternalResource $resourceB;

    private CoreExternalResource $unboundAccount;

    private CoreExternalResource $businessResource;

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
            'moxdop.meta.access_token' => '',
        ]);

        Http::fake();

        $customer = Customer::factory()->create();
        $this->brandA = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Brand A']);
        $this->brandB = Brand::factory()->create(['customer_id' => $customer->id, 'name' => 'Brand B']);

        $this->assetA = DigitalAsset::factory()->create([
            'brand_id' => $this->brandA->id,
            'type' => 'meta_ads',
            'module_id' => 'meta-ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $this->assetB = DigitalAsset::factory()->create([
            'brand_id' => $this->brandB->id,
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

        $this->resourceA = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_11110001',
            'display_name' => 'Synthetic Main Ads',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'currency' => 'EUR',
                'timezone_name' => 'Europe/Berlin',
                'business_id' => 'biz_a',
                'business_name' => 'Business A',
            ],
        ]);
        $this->resourceB = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_22220002',
            'display_name' => 'Synthetic International Ads',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => [
                'currency' => 'USD',
                'timezone_name' => 'America/New_York',
                'business_id' => 'biz_b',
                'business_name' => 'Business B',
            ],
        ]);
        $this->unboundAccount = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_33330003',
            'display_name' => 'Discovered Unbound',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $this->businessResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_BUSINESS,
            'external_id' => 'biz_a',
            'display_name' => 'Business A',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->bindingA = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->assetA->id,
            'external_resource_id' => $this->resourceA->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->bindingB = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->assetB->id,
            'external_resource_id' => $this->resourceB->id,
            'capability' => MetaConnectorRegistry::META_ADS,
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function complete_multi_account_plan_creates_one_run_with_registry_datasets(): void
    {
        Queue::fake();

        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $result->outcome);
        $run = $result->collectionRun;
        $this->assertNotNull($run);
        $this->assertSame(CollectionTriggerType::InitialBackfill, $run->trigger_type);
        $this->assertSame('meta_initial_backfill', $run->metadata['collection_intent']);
        $this->assertSame('Initial Meta Ads Collection', $run->metadata['collection_intent_label']);
        $this->assertSame(1, CollectionRun::query()->count());
        $this->assertSame(2, $run->resourceRuns()->count());
        $this->assertSame(2, $run->resourceRuns()->where('provider_or_source', 'META_ADS')->count());

        $bindingIds = $run->resourceRuns()->pluck('core_asset_binding_id')->all();
        $this->assertContains($this->bindingA->id, $bindingIds);
        $this->assertContains($this->bindingB->id, $bindingIds);
        $this->assertFalse(
            CoreAssetBinding::query()
                ->where('external_resource_id', $this->unboundAccount->id)
                ->whereIn('id', $bindingIds)
                ->exists()
        );

        $queued = $run->datasetRuns()->where('status', CollectionRunStatus::Queued)->get();
        $this->assertNotEmpty($queued);
        foreach ($queued as $dataset) {
            $this->assertSame('META_ADS', $dataset->provider_or_source);
            $this->assertContains($dataset->request_family_id, MetaAdsRequestFamilyCatalog::supportedFamilies());
            $this->assertNotSame('RF_META_ASYNC_INSIGHTS', $dataset->request_family_id);
            $meta = $dataset->metadata ?? [];
            if ($dataset->request_family_id === MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT
                || $dataset->request_family_id === MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META) {
                $this->assertTrue(
                    empty($meta['date_range']) || ($meta['date_range']['start'] ?? null) === null,
                    'Snapshot families must not receive fake historical date ranges'
                );
            }
            if ($dataset->request_family_id === MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY) {
                $this->assertNotEmpty($meta['date_range']['start'] ?? null);
                $this->assertNotEmpty($meta['date_range']['end'] ?? null);
            }
        }

        $this->assertSame(0, $run->resourceRuns()->where('resource_kind', 'meta_business')->count());
        $this->assertSame(
            0,
            $run->resourceRuns()->where('external_resource_id', $this->businessResource->id)->count()
        );

        Queue::assertPushed(ExecuteDatasetRunJob::class);
        $this->assertFalse($run->status->isTerminal());
        Http::assertNothingSent();
    }

    #[Test]
    public function unbound_accounts_and_business_resources_are_excluded(): void
    {
        Queue::fake();

        $preflight = app(MetaInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $this->assertTrue($preflight->canStart);
        $eligibleIds = $preflight->eligibleBindingIds;
        $this->assertContains($this->bindingA->id, $eligibleIds);
        $this->assertContains($this->bindingB->id, $eligibleIds);
        $this->assertCount(2, $eligibleIds);

        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $externalIds = $result->collectionRun->resourceRuns()->pluck('external_resource_id')->all();
        $this->assertNotContains($this->unboundAccount->id, $externalIds);
        $this->assertNotContains($this->businessResource->id, $externalIds);
        Http::assertNothingSent();
    }

    #[Test]
    public function different_brand_scope_is_retained_per_resource_run(): void
    {
        Queue::fake();

        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $run = $result->collectionRun;
        $this->assertNotNull($run);

        $resourceA = $run->resourceRuns()->where('core_asset_binding_id', $this->bindingA->id)->first();
        $resourceB = $run->resourceRuns()->where('core_asset_binding_id', $this->bindingB->id)->first();
        $this->assertNotNull($resourceA);
        $this->assertNotNull($resourceB);
        $this->assertSame($this->assetA->id, $resourceA->digital_asset_id);
        $this->assertSame($this->assetB->id, $resourceB->digital_asset_id);
        $this->assertSame($this->brandA->id, DigitalAsset::query()->find($resourceA->digital_asset_id)?->brand_id);
        $this->assertSame($this->brandB->id, DigitalAsset::query()->find($resourceB->digital_asset_id)?->brand_id);
    }

    #[Test]
    public function no_selected_resources_returns_actionable_state_without_run(): void
    {
        CoreAssetBinding::query()->update(['status' => CoreAssetBinding::STATUS_DISABLED]);

        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);

        $this->assertSame('no_resources_selected', $result->outcome);
        $this->assertNull($result->collectionRun);
        $this->assertSame(0, CollectionRun::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function already_satisfied_datasets_are_not_recollected(): void
    {
        DatasetMaterialization::query()->create([
            'dataset_id' => 'meta_ad_account_snapshot',
            'digital_asset_id' => $this->assetA->id,
            'external_resource_id' => $this->resourceA->id,
            'provider_or_source' => 'META_ADS',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'partial' => false,
            'last_collected_at' => now(),
            'row_count_approx' => 1,
            'row_count_semantics' => 'approximate_from_batches',
        ]);

        $preflight = app(MetaInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $satisfiedFamilies = collect($preflight->alreadySatisfied)->pluck('request_family_id')->all();
        $this->assertContains(MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META, $satisfiedFamilies);

        Queue::fake();
        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $run = $result->collectionRun;
        $resourceRunA = $run->resourceRuns()->where('core_asset_binding_id', $this->bindingA->id)->first();
        $this->assertNotNull($resourceRunA);
        $metaFamily = $run->datasetRuns()
            ->where('request_family_id', MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META)
            ->where('collection_resource_run_id', $resourceRunA->id)
            ->first();
        $this->assertNotNull($metaFamily);
        $this->assertSame(CollectionRunStatus::Skipped, $metaFamily->status);
    }

    #[Test]
    public function partial_materialization_schedules_continuation_range(): void
    {
        DatasetMaterialization::query()->create([
            'dataset_id' => 'meta_campaign_daily',
            'digital_asset_id' => $this->assetA->id,
            'external_resource_id' => $this->resourceA->id,
            'provider_or_source' => 'META_ADS',
            'contract_version' => 1,
            'coverage_start_date' => now()->subDays(180)->toDateString(),
            'coverage_end_date' => now()->subDays(90)->toDateString(),
            'status' => MaterializationStatus::Partial,
            'partial' => true,
            'last_collected_at' => now()->subDay(),
            'row_count_approx' => 90,
            'row_count_semantics' => 'approximate_from_batches',
        ]);

        $preflight = app(MetaInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $daily = collect($preflight->plannedDatasets)
            ->where('request_family_id', MetaAdsRequestFamilyCatalog::FAMILY_INSIGHTS_DAILY)
            ->first(fn (array $row): bool => (int) ($row['core_asset_binding_id'] ?? 0) === $this->bindingA->id);
        $this->assertNotNull($daily);
        $this->assertNotEmpty($daily['date_range']['start'] ?? null);
        $this->assertSame('eligible', $daily['plan_disposition']);
        Http::assertNothingSent();
    }

    #[Test]
    public function double_click_reuses_active_equivalent_run(): void
    {
        Queue::fake();
        $orchestrator = app(MetaInitialBackfillOrchestrator::class);

        $first = $orchestrator->start($this->integration->fresh(), $this->admin);
        $second = $orchestrator->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $first->outcome);
        $this->assertSame('active_equivalent', $second->outcome);
        $this->assertTrue($second->reusedExisting);
        $this->assertSame($first->collectionRun->id, $second->collectionRun->id);
        $this->assertSame(1, CollectionRun::query()->count());
    }

    #[Test]
    public function terminal_prior_run_allows_later_collection_when_work_remains(): void
    {
        Queue::fake();
        $orchestrator = app(MetaInitialBackfillOrchestrator::class);

        $first = $orchestrator->start($this->integration->fresh(), $this->admin);
        $this->assertSame('started', $first->outcome);
        $first->collectionRun->forceFill([
            'status' => CollectionRunStatus::Partial,
            'finished_at' => now(),
        ])->save();

        $second = $orchestrator->start($this->integration->fresh(), $this->admin);
        $this->assertSame('started', $second->outcome);
        $this->assertFalse($second->reusedExisting);
        $this->assertNotSame($first->collectionRun->id, $second->collectionRun->id);
        $this->assertSame(2, CollectionRun::query()->count());
    }

    #[Test]
    public function browser_close_does_not_cancel_and_state_reconstructs_from_db(): void
    {
        Queue::fake();
        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $uuid = $result->collectionRun->uuid;

        $run = CollectionRun::query()->where('uuid', $uuid)->first();
        $this->assertNotNull($run);
        $payload = app(CollectionRunMonitorQuery::class)->summary($run);
        $this->assertSame($uuid, $payload['uuid']);
        $this->assertFalse($payload['is_terminal']);
        $this->assertSame('initial_backfill', $payload['trigger_type']);
        $this->assertSame('Initial Meta Ads Collection', $payload['trigger_label']);
    }

    #[Test]
    public function dataset_failure_isolates_siblings_and_other_accounts(): void
    {
        Queue::fake();

        $this->app->instance(
            DatasetExecutorResolver::class,
            new DatasetExecutorResolver([
                FakeDatasetExecutor::map([
                    MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META => DatasetExecutionResult::failed(
                        CollectionErrorCategory::Provider5xx,
                        'meta boom',
                    ),
                    MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT => DatasetExecutionResult::completed(2),
                ]),
            ]),
        );

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->assetA,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: [$this->bindingA->id, $this->bindingB->id],
            providerSources: ['META_ADS'],
            requestFamilyIds: [
                MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META,
                MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT,
            ],
            context: [
                'allow_multi_asset_bindings' => true,
                'collection_intent' => 'meta_initial_backfill',
                'collection_intent_label' => 'Initial Meta Ads Collection',
            ],
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
        $this->assertSame(CollectionRunStatus::Partial, $run->status);

        $resourceRunA = $run->resourceRuns()->where('core_asset_binding_id', $this->bindingA->id)->first();
        $resourceRunB = $run->resourceRuns()->where('core_asset_binding_id', $this->bindingB->id)->first();
        $this->assertNotNull($resourceRunA);
        $this->assertNotNull($resourceRunB);

        $failedA = $run->datasetRuns()
            ->where('request_family_id', MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META)
            ->where('collection_resource_run_id', $resourceRunA->id)
            ->first();
        $completedA = $run->datasetRuns()
            ->where('request_family_id', MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT)
            ->where('collection_resource_run_id', $resourceRunA->id)
            ->first();
        $completedB = $run->datasetRuns()
            ->where('request_family_id', MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT)
            ->where('collection_resource_run_id', $resourceRunB->id)
            ->first();

        $this->assertNotNull($failedA);
        $this->assertNotNull($completedA);
        $this->assertNotNull($completedB);
        $this->assertSame(CollectionRunStatus::Failed, $failedA->status);
        $this->assertSame(CollectionRunStatus::Completed, $completedA->status);
        $this->assertSame(CollectionRunStatus::Completed, $completedB->status);
    }

    #[Test]
    public function meta_failure_does_not_change_google_run_state(): void
    {
        $googleRun = CollectionRun::factory()->create([
            'digital_asset_id' => $this->assetA->id,
            'trigger_type' => CollectionTriggerType::InitialBackfill,
            'status' => CollectionRunStatus::Completed,
            'metadata' => [
                'collection_intent' => 'google_initial_backfill',
                'collection_intent_label' => 'Initial Google Collection',
            ],
            'datasets_total' => 3,
            'datasets_completed' => 3,
        ]);
        $before = $googleRun->fresh()->toArray();

        Queue::fake();
        $meta = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $this->assertSame('started', $meta->outcome);

        $googleRun->refresh();
        $this->assertSame(CollectionRunStatus::Completed, $googleRun->status);
        $this->assertSame($before['datasets_completed'], $googleRun->datasets_completed);
        $this->assertSame('google_initial_backfill', $googleRun->metadata['collection_intent']);
    }

    #[Test]
    public function cancellation_preserves_completed_data(): void
    {
        Queue::fake();

        $this->app->instance(
            DatasetExecutorResolver::class,
            new DatasetExecutorResolver([
                FakeDatasetExecutor::succeed(MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META),
            ]),
        );

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->assetA,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: [$this->bindingA->id],
            providerSources: ['META_ADS'],
            requestFamilyIds: [MetaAdsRequestFamilyCatalog::FAMILY_AD_ACCOUNT_META],
            context: ['collection_intent' => 'meta_initial_backfill'],
        ));

        $meta = $run->datasetRuns()->first();
        (new ExecuteDatasetRunJob($meta->id))->handle(
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
        $this->assertSame(CollectionRunStatus::Completed, $meta->fresh()->status);

        $sibling = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->assetB,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: [$this->bindingB->id],
            providerSources: ['META_ADS'],
            requestFamilyIds: [MetaAdsRequestFamilyCatalog::FAMILY_ENTITY_SNAPSHOT],
            idempotencyKey: 'meta-cancel-sibling-'.uniqid(),
            context: ['collection_intent' => 'meta_initial_backfill'],
        ));
        $queued = $sibling->datasetRuns()->first();
        app(CancellationService::class)->requestCancellation($sibling->fresh());

        (new ExecuteDatasetRunJob($queued->id))->handle(
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

        $this->assertSame(CollectionRunStatus::Cancelled, $queued->fresh()->status);
        $this->assertSame(CollectionRunStatus::Completed, $meta->fresh()->status);
    }

    #[Test]
    public function multi_account_progress_uses_dataset_plan_completion(): void
    {
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->assetA->id,
            'trigger_type' => CollectionTriggerType::InitialBackfill,
            'status' => CollectionRunStatus::Running,
            'metadata' => [
                'collection_intent' => 'meta_initial_backfill',
                'collection_intent_label' => 'Initial Meta Ads Collection',
            ],
        ]);

        $accountA = $run->resourceRuns()->create([
            'provider_or_source' => 'META_ADS',
            'resource_kind' => 'bound_provider_resource',
            'digital_asset_id' => $this->assetA->id,
            'core_asset_binding_id' => $this->bindingA->id,
            'external_resource_id' => $this->resourceA->id,
            'status' => CollectionRunStatus::Running,
            'datasets_total' => 10,
            'datasets_completed' => 8,
        ]);
        $accountB = $run->resourceRuns()->create([
            'provider_or_source' => 'META_ADS',
            'resource_kind' => 'bound_provider_resource',
            'digital_asset_id' => $this->assetB->id,
            'core_asset_binding_id' => $this->bindingB->id,
            'external_resource_id' => $this->resourceB->id,
            'status' => CollectionRunStatus::Running,
            'datasets_total' => 8,
            'datasets_completed' => 4,
        ]);

        foreach (range(1, 10) as $i) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id,
                'collection_resource_run_id' => $accountA->id,
                'provider_or_source' => 'META_ADS',
                'request_family_id' => 'META_A_'.$i,
                'status' => $i <= 8 ? CollectionRunStatus::Completed : CollectionRunStatus::Queued,
            ]);
        }
        foreach (range(1, 8) as $i) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id,
                'collection_resource_run_id' => $accountB->id,
                'provider_or_source' => 'META_ADS',
                'request_family_id' => 'META_B_'.$i,
                'status' => $i <= 4
                    ? CollectionRunStatus::Completed
                    : ($i === 5 ? CollectionRunStatus::Retrying : CollectionRunStatus::Queued),
            ]);
        }

        $presenter = app(CollectionProgressPresenter::class);
        $run->load('resourceRuns.datasetRuns');
        $plan = $presenter->connectorPlanCompletion('META_ADS', $run->resourceRuns);

        $this->assertSame('DATASET_PLAN_COMPLETION', $plan['type']);
        $this->assertSame(66.7, $plan['percentage']);
        $this->assertSame(1, $plan['retrying']);
    }

    #[Test]
    public function rebind_during_active_run_does_not_redirect_persisted_resource_identity(): void
    {
        Queue::fake();
        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $run = $result->collectionRun;
        $original = $run->resourceRuns()->where('core_asset_binding_id', $this->bindingA->id)->first();
        $this->assertSame($this->resourceA->id, $original->external_resource_id);

        $replacement = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'meta',
            'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_99990009',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $this->bindingA->forceFill(['external_resource_id' => $replacement->id])->save();

        $original->refresh();
        $this->assertSame($this->resourceA->id, $original->external_resource_id);
        $this->assertNotSame($replacement->id, $original->external_resource_id);

        $future = app(MetaInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $this->assertContains($this->bindingA->id, $future->eligibleBindingIds);
        $futureBinding = collect($future->bindings)->firstWhere('binding_id', $this->bindingA->id);
        $this->assertSame($replacement->id, $futureBinding['external_resource_id']);
    }

    #[Test]
    public function auth_required_prevents_empty_executable_run(): void
    {
        CoreIntegrationCredential::query()->where('integration_id', $this->integration->id)->delete();
        $this->integration->forceFill([
            'config' => array_merge($this->integration->config ?? [], [
                'auth_status' => 'reauth_required',
                'credential_status' => 'invalid',
                'granted_permissions' => null,
            ]),
        ])->save();

        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);

        $this->assertContains($result->outcome, ['reauth_required', 'permission_required', 'no_eligible_accounts']);
        $this->assertNull($result->collectionRun);
        $this->assertSame(0, CollectionRun::query()->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function unavailable_account_does_not_block_eligible_siblings(): void
    {
        $this->resourceA->forceFill(['status' => CoreExternalResource::STATUS_UNAVAILABLE])->save();

        Queue::fake();
        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $result->outcome);
        $run = $result->collectionRun;
        $this->assertSame(1, $run->resourceRuns()->count());
        $this->assertSame($this->bindingB->id, $run->resourceRuns()->first()->core_asset_binding_id);

        $preflight = app(MetaInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $action = collect($preflight->actionRequired)->firstWhere('binding_id', $this->bindingA->id);
        $this->assertNotNull($action);
    }

    #[Test]
    public function collect_data_action_is_enabled_on_meta_integration_surface(): void
    {
        Queue::fake();
        $this->actingAs($this->admin);

        Livewire::test(MetaIntegrationPage::class)
            ->assertSee('Collect Data')
            ->assertSee('Meta initial collection')
            ->call('collectData')
            ->assertHasNoErrors();

        $this->assertSame(1, CollectionRun::query()->where('trigger_type', CollectionTriggerType::InitialBackfill)->count());
        $run = CollectionRun::query()->first();
        $this->assertSame('Initial Meta Ads Collection', $run->metadata['collection_intent_label']);
        Http::assertNothingSent();
    }

    #[Test]
    public function preflight_makes_zero_analytical_provider_calls(): void
    {
        Http::fake();
        $preflight = app(MetaInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $this->assertTrue($preflight->canStart);
        $this->assertGreaterThan(0, $preflight->summary['planned_datasets'] ?? 0);
        Http::assertNothingSent();
    }

    #[Test]
    public function multi_currency_and_timezone_remain_per_account_resources(): void
    {
        Queue::fake();
        $result = app(MetaInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $run = $result->collectionRun;

        $a = $run->resourceRuns()->where('external_resource_id', $this->resourceA->id)->first();
        $b = $run->resourceRuns()->where('external_resource_id', $this->resourceB->id)->first();
        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertNotSame($a->id, $b->id);

        $this->assertSame('EUR', $this->resourceA->fresh()->metadata['currency']);
        $this->assertSame('USD', $this->resourceB->fresh()->metadata['currency']);
        $this->assertSame('Europe/Berlin', $this->resourceA->fresh()->metadata['timezone_name']);
        $this->assertSame('America/New_York', $this->resourceB->fresh()->metadata['timezone_name']);
    }
}
