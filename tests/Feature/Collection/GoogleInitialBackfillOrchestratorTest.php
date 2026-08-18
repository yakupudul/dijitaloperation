<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DigitalAssetStatus;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Livewire\Demo\Integrations\GoogleIntegrationPage;
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
use App\Services\Collection\Google\GoogleInitialBackfillOrchestrator;
use App\Services\Collection\Monitoring\CollectionProgressPresenter;
use App\Services\Collection\Monitoring\CollectionRunMonitorQuery;
use App\Services\Collection\ProgressReporter;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Collection\Testing\FakeDatasetExecutor;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GoogleInitialBackfillOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private CoreIntegration $integration;

    private DigitalAsset $gscAsset;

    private DigitalAsset $ga4Asset;

    private DigitalAsset $adsAsset;

    private CoreAssetBinding $gscBinding;

    private CoreAssetBinding $ga4Binding;

    private CoreAssetBinding $adsBinding;

    private CoreExternalResource $unboundGsc;

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

        $this->gscAsset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $this->ga4Asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $this->adsAsset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        $this->integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => [
                'auth_status' => 'connected',
                'granted_scopes' => [
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::ADWORDS,
                ],
            ],
        ]);

        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                'developer_token' => 'dev-token',
            ],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'scope' => implode(' ', [
                    GoogleScopes::SEARCH_CONSOLE_READONLY,
                    GoogleScopes::ANALYTICS_READONLY,
                    GoogleScopes::ADWORDS,
                ]),
            ],
            'expires_at' => now()->addHour(),
        ]);

        $gscResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:example.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $ga4Resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'ga4',
            'external_id' => 'properties/123',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        $adsResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'google_ads',
            'external_id' => '1112223333',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'Europe/Berlin'],
        ]);
        $this->unboundGsc = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'search_console',
            'external_id' => 'sc-domain:unbound.example',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        $this->gscBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->gscAsset->id,
            'external_resource_id' => $gscResource->id,
            'capability' => 'search_console',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->ga4Binding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->ga4Asset->id,
            'external_resource_id' => $ga4Resource->id,
            'capability' => 'ga4',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
        $this->adsBinding = CoreAssetBinding::factory()->create([
            'digital_asset_id' => $this->adsAsset->id,
            'external_resource_id' => $adsResource->id,
            'capability' => 'google_ads',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);
    }

    #[Test]
    public function complete_three_provider_plan_creates_one_run_with_registry_datasets(): void
    {
        Queue::fake();

        $result = app(GoogleInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $result->outcome);
        $run = $result->collectionRun;
        $this->assertNotNull($run);
        $this->assertSame(CollectionTriggerType::InitialBackfill, $run->trigger_type);
        $this->assertSame('Initial Google Collection', $run->metadata['collection_intent_label']);
        $this->assertSame(1, CollectionRun::query()->count());
        $this->assertSame(3, $run->resourceRuns()->count());

        $providers = $run->resourceRuns()->pluck('provider_or_source')->sort()->values()->all();
        $this->assertSame(['GA4', 'GOOGLE_ADS', 'SEARCH_CONSOLE'], $providers);

        $queued = $run->datasetRuns()->where('status', CollectionRunStatus::Queued)->get();
        $this->assertNotEmpty($queued);

        foreach ($queued as $dataset) {
            $this->assertContains($dataset->provider_or_source, ['SEARCH_CONSOLE', 'GA4', 'GOOGLE_ADS']);
            $meta = $dataset->metadata ?? [];
            if (in_array($dataset->request_family_id, [
                'GSC_RF_PROPERTY_DAILY',
                'GA4_RF_PROPERTY_DAILY',
                'GADS_RF_CAMPAIGN_DAILY',
            ], true)) {
                $this->assertNotEmpty($meta['date_range']['start'] ?? null);
                $this->assertNotEmpty($meta['date_range']['end'] ?? null);
            }
            if ($dataset->request_family_id === 'GADS_RF_ENTITY_SNAPSHOT') {
                $this->assertNull($meta['date_range'] ?? null);
            }
        }

        $this->assertSame(0, $run->datasetRuns()->where('provider_or_source', 'GBP')->count());
        $bindingIds = $run->resourceRuns()->pluck('core_asset_binding_id')->all();
        $this->assertNotContains(null, $bindingIds);
        $this->assertFalse(
            CoreAssetBinding::query()
                ->where('external_resource_id', $this->unboundGsc->id)
                ->whereIn('id', $bindingIds)
                ->exists()
        );

        Queue::assertPushed(ExecuteDatasetRunJob::class);
        $this->assertFalse($run->status->isTerminal());
    }

    #[Test]
    public function unbound_and_gbp_are_excluded_without_blocking_eligible_connectors(): void
    {
        Queue::fake();

        $gbpAsset = DigitalAsset::factory()->create([
            'brand_id' => $this->gscAsset->brand_id,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $gbpResource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id,
            'provider' => 'google',
            'resource_type' => 'google_business_profile',
            'external_id' => 'accounts/1/locations/2',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        CoreAssetBinding::factory()->create([
            'digital_asset_id' => $gbpAsset->id,
            'external_resource_id' => $gbpResource->id,
            'capability' => 'google_business_profile',
            'status' => CoreAssetBinding::STATUS_ACTIVE,
        ]);

        $preflight = app(GoogleInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $this->assertTrue($preflight->canStart);
        $gbpDisposition = collect($preflight->dispositions)->firstWhere('provider_or_source', 'GBP');
        $this->assertNotNull($gbpDisposition);
        $this->assertSame('collector_unavailable', $gbpDisposition['type']);

        $result = app(GoogleInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $this->assertSame('started', $result->outcome);
        $this->assertSame(0, $result->collectionRun->datasetRuns()->where('provider_or_source', 'GBP')->count());
        $this->assertGreaterThan(0, $result->collectionRun->datasetRuns()->where('provider_or_source', 'SEARCH_CONSOLE')->count());
    }

    #[Test]
    public function no_selected_resources_returns_actionable_state_without_run(): void
    {
        CoreAssetBinding::query()->update(['status' => CoreAssetBinding::STATUS_DISABLED]);

        $result = app(GoogleInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);

        $this->assertSame('no_resources_selected', $result->outcome);
        $this->assertNull($result->collectionRun);
        $this->assertSame(0, CollectionRun::query()->count());
    }

    #[Test]
    public function multiple_resources_per_connector_are_planned_independently(): void
    {
        Queue::fake();

        $brandId = $this->gscAsset->brand_id;
        $secondGscAsset = DigitalAsset::factory()->create([
            'brand_id' => $brandId,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $secondGa4Asset = DigitalAsset::factory()->create([
            'brand_id' => $brandId,
            'type' => 'website',
            'status' => DigitalAssetStatus::Active,
        ]);
        $secondAdsAsset = DigitalAsset::factory()->create([
            'brand_id' => $brandId,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);
        $thirdAdsAsset = DigitalAsset::factory()->create([
            'brand_id' => $brandId,
            'type' => 'google_ads',
            'status' => DigitalAssetStatus::Active,
        ]);

        foreach ([
            [$secondGscAsset, 'search_console', 'sc-domain:two.example'],
            [$secondGa4Asset, 'ga4', 'properties/999'],
            [$secondAdsAsset, 'google_ads', '4445556666'],
            [$thirdAdsAsset, 'google_ads', '7778889999'],
        ] as [$asset, $type, $externalId]) {
            $resource = CoreExternalResource::factory()->create([
                'integration_id' => $this->integration->id,
                'provider' => 'google',
                'resource_type' => $type === 'search_console' ? 'search_console' : $type,
                'external_id' => $externalId,
                'status' => CoreExternalResource::STATUS_AVAILABLE,
                'metadata' => $type === 'google_ads' ? ['is_manager' => false] : [],
            ]);
            CoreAssetBinding::factory()->create([
                'digital_asset_id' => $asset->id,
                'external_resource_id' => $resource->id,
                'capability' => $type,
                'status' => CoreAssetBinding::STATUS_ACTIVE,
            ]);
        }

        $result = app(GoogleInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $run = $result->collectionRun;
        $this->assertNotNull($run);
        $this->assertSame(1, CollectionRun::query()->count());
        $this->assertSame(2, $run->resourceRuns()->where('provider_or_source', 'SEARCH_CONSOLE')->count());
        $this->assertSame(2, $run->resourceRuns()->where('provider_or_source', 'GA4')->count());
        $this->assertSame(3, $run->resourceRuns()->where('provider_or_source', 'GOOGLE_ADS')->count());
    }

    #[Test]
    public function already_satisfied_datasets_are_not_recollected(): void
    {
        DatasetMaterialization::query()->create([
            'dataset_id' => 'ga4_property_metadata',
            'digital_asset_id' => $this->ga4Asset->id,
            'external_resource_id' => $this->ga4Binding->external_resource_id,
            'provider_or_source' => 'GA4',
            'contract_version' => 1,
            'status' => MaterializationStatus::Available,
            'partial' => false,
            'last_collected_at' => now(),
            'row_count_approx' => 1,
            'row_count_semantics' => 'approximate_from_batches',
        ]);

        $preflight = app(GoogleInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $satisfiedFamilies = collect($preflight->alreadySatisfied)->pluck('request_family_id')->all();
        $this->assertContains('GA4_RF_PROPERTY_METADATA', $satisfiedFamilies);

        Queue::fake();
        $result = app(GoogleInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $run = $result->collectionRun;
        $metaFamily = $run->datasetRuns()->where('request_family_id', 'GA4_RF_PROPERTY_METADATA')->first();
        $this->assertNotNull($metaFamily);
        $this->assertSame(CollectionRunStatus::Skipped, $metaFamily->status);
    }

    #[Test]
    public function ads_config_missing_does_not_block_gsc_and_ga4(): void
    {
        CoreIntegrationCredential::query()
            ->where('integration_id', $this->integration->id)
            ->where('credential_type', CoreIntegrationCredential::TYPE_PROVIDER)
            ->delete();
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => [
                'client_id' => 'cid',
                'client_secret' => 'csecret',
                // developer_token intentionally omitted
            ],
        ]);

        $preflight = app(GoogleInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $this->assertTrue($preflight->canStart);
        $adsAction = collect($preflight->actionRequired)->firstWhere('capability', 'google_ads');
        $this->assertNotNull($adsAction);
        $this->assertStringContainsString('Developer token', $adsAction['label']);

        Queue::fake();
        $result = app(GoogleInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $providers = $result->collectionRun->resourceRuns()->pluck('provider_or_source')->all();
        $this->assertContains('SEARCH_CONSOLE', $providers);
        $this->assertContains('GA4', $providers);
        $this->assertNotContains('GOOGLE_ADS', $providers);
    }

    #[Test]
    public function double_click_reuses_active_equivalent_run(): void
    {
        Queue::fake();
        $orchestrator = app(GoogleInitialBackfillOrchestrator::class);

        $first = $orchestrator->start($this->integration->fresh(), $this->admin);
        $second = $orchestrator->start($this->integration->fresh(), $this->admin);

        $this->assertSame('started', $first->outcome);
        $this->assertSame('active_equivalent', $second->outcome);
        $this->assertTrue($second->reusedExisting);
        $this->assertSame($first->collectionRun->id, $second->collectionRun->id);
        $this->assertSame(1, CollectionRun::query()->count());
    }

    #[Test]
    public function browser_close_does_not_cancel_and_state_reconstructs_from_db(): void
    {
        Queue::fake();
        $result = app(GoogleInitialBackfillOrchestrator::class)->start($this->integration->fresh(), $this->admin);
        $uuid = $result->collectionRun->uuid;

        // Simulate browser closed: only DB remains.
        $run = CollectionRun::query()->where('uuid', $uuid)->first();
        $this->assertNotNull($run);
        $payload = app(CollectionRunMonitorQuery::class)->summary($run);
        $this->assertSame($uuid, $payload['uuid']);
        $this->assertFalse($payload['is_terminal']);
        $this->assertSame('initial_backfill', $payload['trigger_type']);
        $this->assertSame('Initial Google Collection', $payload['trigger_label']);
    }

    #[Test]
    public function provider_failure_isolation_yields_partial_and_preserves_siblings(): void
    {
        Queue::fake();

        $families = [
            'GSC_RF_SITEMAPS',
            'GA4_RF_PROPERTY_METADATA',
            'GADS_RF_ENTITY_SNAPSHOT',
        ];

        $this->app->instance(
            DatasetExecutorResolver::class,
            new DatasetExecutorResolver([
                FakeDatasetExecutor::map([
                    'GSC_RF_SITEMAPS' => DatasetExecutionResult::failed(
                        CollectionErrorCategory::Provider5xx,
                        'gsc boom',
                    ),
                    'GA4_RF_PROPERTY_METADATA' => DatasetExecutionResult::completed(1),
                    'GADS_RF_ENTITY_SNAPSHOT' => DatasetExecutionResult::completed(2),
                ]),
            ]),
        );

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->gscAsset,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: [$this->gscBinding->id, $this->ga4Binding->id, $this->adsBinding->id],
            requestFamilyIds: $families,
            context: ['allow_multi_asset_bindings' => true, 'collection_intent' => 'google_initial_backfill'],
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
        $this->assertSame(
            CollectionRunStatus::Failed,
            $run->datasetRuns()->where('request_family_id', 'GSC_RF_SITEMAPS')->first()->status
        );
        $this->assertSame(
            CollectionRunStatus::Completed,
            $run->datasetRuns()->where('request_family_id', 'GA4_RF_PROPERTY_METADATA')->first()->status
        );
        $this->assertSame(
            CollectionRunStatus::Completed,
            $run->datasetRuns()->where('request_family_id', 'GADS_RF_ENTITY_SNAPSHOT')->first()->status
        );
    }

    #[Test]
    public function progress_connector_percentages_use_dataset_plan_completion(): void
    {
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $this->gscAsset->id,
            'trigger_type' => CollectionTriggerType::InitialBackfill,
            'status' => CollectionRunStatus::Running,
            'metadata' => ['collection_intent_label' => 'Initial Google Collection'],
        ]);

        $gsc = $run->resourceRuns()->create([
            'provider_or_source' => 'SEARCH_CONSOLE',
            'resource_kind' => 'bound_provider_resource',
            'digital_asset_id' => $this->gscAsset->id,
            'core_asset_binding_id' => $this->gscBinding->id,
            'status' => CollectionRunStatus::Completed,
            'datasets_total' => 3,
            'datasets_completed' => 3,
        ]);
        $ga4 = $run->resourceRuns()->create([
            'provider_or_source' => 'GA4',
            'resource_kind' => 'bound_provider_resource',
            'digital_asset_id' => $this->ga4Asset->id,
            'core_asset_binding_id' => $this->ga4Binding->id,
            'status' => CollectionRunStatus::Running,
            'datasets_total' => 3,
            'datasets_completed' => 2,
        ]);
        $ads = $run->resourceRuns()->create([
            'provider_or_source' => 'GOOGLE_ADS',
            'resource_kind' => 'bound_provider_resource',
            'digital_asset_id' => $this->adsAsset->id,
            'core_asset_binding_id' => $this->adsBinding->id,
            'status' => CollectionRunStatus::Running,
            'datasets_total' => 12,
            'datasets_completed' => 5,
        ]);

        foreach (range(1, 3) as $i) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id,
                'collection_resource_run_id' => $gsc->id,
                'provider_or_source' => 'SEARCH_CONSOLE',
                'request_family_id' => 'GSC_F_'.$i,
                'status' => CollectionRunStatus::Completed,
            ]);
        }
        foreach ([CollectionRunStatus::Completed, CollectionRunStatus::Completed, CollectionRunStatus::Queued] as $i => $status) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id,
                'collection_resource_run_id' => $ga4->id,
                'provider_or_source' => 'GA4',
                'request_family_id' => 'GA4_F_'.$i,
                'status' => $status,
            ]);
        }
        foreach (range(1, 12) as $i) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id,
                'collection_resource_run_id' => $ads->id,
                'provider_or_source' => 'GOOGLE_ADS',
                'request_family_id' => 'GADS_F_'.$i,
                'status' => $i <= 5 ? CollectionRunStatus::Completed : ($i === 6 ? CollectionRunStatus::Retrying : CollectionRunStatus::Queued),
            ]);
        }

        $presenter = app(CollectionProgressPresenter::class);
        $run->load('resourceRuns.datasetRuns');

        $gscPlan = $presenter->connectorPlanCompletion('SEARCH_CONSOLE', $run->resourceRuns);
        $ga4Plan = $presenter->connectorPlanCompletion('GA4', $run->resourceRuns);
        $adsPlan = $presenter->connectorPlanCompletion('GOOGLE_ADS', $run->resourceRuns);

        $this->assertSame(100.0, $gscPlan['percentage']);
        $this->assertSame(66.7, $ga4Plan['percentage']);
        $this->assertSame(41.7, $adsPlan['percentage']);
        $this->assertSame(1, $adsPlan['retrying']);
        $this->assertSame('DATASET_PLAN_COMPLETION', $gscPlan['type']);
    }

    #[Test]
    public function cancellation_preserves_completed_data_and_blocks_delayed_retry(): void
    {
        Queue::fake();

        $this->app->instance(
            DatasetExecutorResolver::class,
            new DatasetExecutorResolver([
                FakeDatasetExecutor::succeed('GA4_RF_PROPERTY_METADATA'),
            ]),
        );

        $run = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->ga4Asset,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: [$this->ga4Binding->id],
            requestFamilyIds: ['GA4_RF_PROPERTY_METADATA'],
            context: ['collection_intent' => 'google_initial_backfill'],
        ));

        $ga4 = $run->datasetRuns()->where('request_family_id', 'GA4_RF_PROPERTY_METADATA')->first();
        $this->assertNotNull($ga4);

        (new ExecuteDatasetRunJob($ga4->id))->handle(
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

        $this->assertSame(CollectionRunStatus::Completed, $ga4->fresh()->status);

        $siblingRun = app(StartCollectionService::class)->start(new StartCollectionRequest(
            digitalAsset: $this->gscAsset,
            triggerType: CollectionTriggerType::InitialBackfill,
            bindingIds: [$this->gscBinding->id],
            requestFamilyIds: ['GSC_RF_SITEMAPS'],
            idempotencyKey: 'cancel-sibling-'.uniqid(),
            context: ['collection_intent' => 'google_initial_backfill'],
        ));
        $queued = $siblingRun->datasetRuns()->first();
        $this->assertSame(CollectionRunStatus::Queued, $queued->status);

        app(CancellationService::class)->requestCancellation($siblingRun->fresh());
        $siblingRun->refresh();
        $this->assertNotNull($siblingRun->cancel_requested_at);

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
        $this->assertSame(CollectionRunStatus::Completed, $ga4->fresh()->status);
    }

    #[Test]
    public function collect_data_action_is_enabled_on_google_integration_surface(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(GoogleIntegrationPage::class)
            ->assertSee('Collect Data')
            ->call('collectData')
            ->assertHasNoErrors();

        $this->assertSame(1, CollectionRun::query()->where('trigger_type', CollectionTriggerType::InitialBackfill)->count());
    }

    #[Test]
    public function partial_materialization_schedules_continuation_range(): void
    {
        DatasetMaterialization::query()->create([
            'dataset_id' => 'ga4_property_daily',
            'digital_asset_id' => $this->ga4Asset->id,
            'external_resource_id' => $this->ga4Binding->external_resource_id,
            'provider_or_source' => 'GA4',
            'contract_version' => 1,
            'coverage_start_date' => now()->subDays(180)->toDateString(),
            'coverage_end_date' => now()->subDays(90)->toDateString(),
            'status' => MaterializationStatus::Partial,
            'partial' => true,
            'last_collected_at' => now()->subDay(),
            'row_count_approx' => 90,
            'row_count_semantics' => 'approximate_from_batches',
        ]);

        $preflight = app(GoogleInitialBackfillOrchestrator::class)->preflight($this->integration->fresh());
        $daily = collect($preflight->plannedDatasets)->firstWhere('request_family_id', 'GA4_RF_PROPERTY_DAILY');
        $this->assertNotNull($daily);
        $this->assertNotEmpty($daily['date_range']['start'] ?? null);
        $this->assertSame('eligible', $daily['plan_disposition']);
    }
}
