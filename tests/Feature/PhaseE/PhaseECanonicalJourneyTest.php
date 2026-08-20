<?php

namespace Tests\Feature\PhaseE;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\DigitalAssetStatus;
use App\Jobs\Async\EvaluateFindingsForAssetJob;
use App\Livewire\Demo\Operations\RecommendationsIndex;
use App\Livewire\Demo\Portfolio\AssetCreate;
use App\Livewire\Demo\Portfolio\BrandCreate;
use App\Livewire\Demo\Portfolio\CustomerCreate;
use App\Livewire\Operator\AssetDataSourcesPage;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Services\Activity\ActivityReadService;
use App\Services\Analysis\CollectedFactsAnalysisService;
use App\Services\Async\AsyncOperationService;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\Providers\Website\WebsiteDatasetExecutor;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Findings\FindingEvaluationService;
use App\Services\Tasks\TaskLifecycleService;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Roles;
use App\Support\Tasks\TaskOutcomeStatus;
use Database\Seeders\RoleAndPermissionSeeder;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use MoxDop\Website\Diagnosis\DocumentHeadCatalog;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PhaseECanonicalJourneyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        Storage::fake('raw_ingestion');
        config([
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
            ],
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-collection.queue_connection' => 'database',
        ]);

        $this->admin = User::factory()->create(['locale' => 'en']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->travelTo('2026-08-20 10:00:00');
    }

    #[Test]
    public function operator_journey_collects_analyzes_and_observes_outcome_without_auto_task(): void
    {
        Http::fake($this->httpFake());

        Livewire::test(CustomerCreate::class)
            ->set('name', 'Northwind Clinics')
            ->set('type', 'company')
            ->set('status', 'active')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $customer = Customer::query()->where('name', 'Northwind Clinics')->firstOrFail();

        Livewire::test(BrandCreate::class, ['customerId' => (string) $customer->id])
            ->set('name', 'Northwind Brand')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $brand = Brand::query()->where('name', 'Northwind Brand')->firstOrFail();
        $this->assertSame($customer->id, $brand->customer_id);

        Livewire::test(AssetCreate::class, ['brandId' => (string) $brand->id])
            ->set('name', 'Northwind Website')
            ->set('type', 'website')
            ->set('domain', '1.1.1.1')
            ->set('primary_url', 'http://1.1.1.1/')
            ->call('save')
            ->assertHasNoErrors();

        $website = DigitalAsset::query()->where('name', 'Northwind Website')->firstOrFail();
        $this->assertSame('website', $website->type);
        $this->assertSame($brand->id, $website->brand_id);

        $integration = $this->agencyGoogle();
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => ProviderRegistry::GOOGLE,
            'resource_type' => 'ga4',
            'external_id' => 'properties/424242',
            'display_name' => 'Northwind GA4',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $website->id])
            ->set('selectedResource.ga4', (string) $resource->id)
            ->call('bind', 'ga4')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('core_asset_bindings', [
            'digital_asset_id' => $website->id,
            'external_resource_id' => $resource->id,
            'capability' => 'ga4',
            'status' => 'active',
        ]);

        Livewire::test(AssetDataSourcesPage::class, ['assetId' => (string) $website->id])
            ->call('collectNow')
            ->assertHasNoErrors();

        $this->assertTrue(
            Evidence::query()->where('digital_asset_id', $website->id)->where('type', 'ga4_performance_summary')->exists()
        );

        $activity = app(ActivityReadService::class)->forList([
            'digital_asset_id' => $website->id,
            'period' => 'last_7',
            'limit' => 50,
        ]);
        $this->assertTrue(collect($activity)->contains(
            fn (array $row): bool => str_starts_with((string) ($row['id'] ?? ''), 'run:')
        ));

        $this->assertSame(DigitalAssetStatus::Active, $website->fresh()->status);
        $this->collectWebsiteHomepage($website, missingTitle: true);

        $this->assertGreaterThan(0, DB::table('website_metadata_snapshot')->where('digital_asset_id', $website->id)->count());

        app(AsyncOperationService::class)->queueFindingEvaluation($website, $this->admin);
        $this->assertSame(0, Task::query()->count());

        $finding = Finding::query()
            ->where('digital_asset_id', $website->id)
            ->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)
            ->firstOrFail();
        $recommendation = Recommendation::query()->where('finding_id', $finding->id)->firstOrFail();
        $this->assertSame('open', $recommendation->status);

        Livewire::test(RecommendationsIndex::class)
            ->call('createTask', (string) $recommendation->id)
            ->assertHasNoErrors();

        $task = Task::query()->where('recommendation_id', $recommendation->id)->firstOrFail();
        $this->assertNull($task->assignee_id);
        $this->assertNull($task->due_date);
        $this->assertSame($website->id, $task->digital_asset_id);

        $task = app(TaskLifecycleService::class)->complete($task, [
            'completion_note' => 'Published a title outside MoxDOP.',
        ], $this->admin);
        $this->assertSame(TaskOutcomeStatus::AWAITING_FOLLOW_UP, $task->outcome_status);

        $this->travelTo('2026-08-20 11:00:00');
        $this->collectWebsiteHomepage($website, missingTitle: false);

        (new EvaluateFindingsForAssetJob($website->id))->handle(
            app(FindingEvaluationService::class),
            app(CollectedFactsAnalysisService::class),
        );

        $this->assertSame('resolved', $finding->fresh()->status);
        $this->assertSame(TaskOutcomeStatus::IMPROVEMENT_OBSERVED, $task->fresh()->outcome_status);
        $this->assertFalse(data_get($task->fresh()->outcome_json, 'causal_attribution'));
        $this->assertSame(1, Finding::query()->where('fingerprint', DocumentHeadCatalog::RULE_TITLE_MISSING)->count());
        $this->assertSame(1, Task::query()->count());
        $this->assertFalse(class_exists('App\\Models\\Result'));
    }

    /**
     * @return callable(Request): PromiseInterface
     */
    private function httpFake(): callable
    {
        return function ($request) {
            $url = $request->url();
            if (str_contains($url, 'analyticsdata.googleapis.com')) {
                return Http::response([
                    'metricHeaders' => [
                        ['name' => 'totalUsers'],
                        ['name' => 'newUsers'],
                        ['name' => 'sessions'],
                        ['name' => 'engagedSessions'],
                        ['name' => 'engagementRate'],
                        ['name' => 'screenPageViews'],
                        ['name' => 'keyEvents'],
                    ],
                    'totals' => [[
                        'metricValues' => [
                            ['value' => '10'],
                            ['value' => '4'],
                            ['value' => '12'],
                            ['value' => '8'],
                            ['value' => '0.66'],
                            ['value' => '30'],
                            ['value' => '1'],
                        ],
                    ]],
                    'rows' => [],
                ], 200);
            }

            if (str_contains($url, 'robots.txt')) {
                return Http::response("User-agent: *\nAllow: /\n", 200, ['Content-Type' => 'text/plain']);
            }

            if (str_contains($url, 'sitemap.xml')) {
                return Http::response('<?xml version="1.0"?><urlset></urlset>', 200, ['Content-Type' => 'application/xml']);
            }

            $html = str_contains($url, 'titled')
                ? '<html><head><title>Northwind Clinics</title><meta name="description" content="Clinic in Istanbul offers implant care."></head><body><h1>Clinic</h1></body></html>'
                : '<html><head><meta name="description" content="Clinic in Istanbul offers implant care."></head><body><h1>Clinic</h1></body></html>';

            return Http::response($html, 200, ['Content-Type' => 'text/html']);
        };
    }

    private function agencyGoogle(): CoreIntegration
    {
        $integration = CoreIntegration::query()->firstOrCreate(
            ['provider' => ProviderRegistry::GOOGLE],
            [
                'name' => ProviderRegistry::defaultName(ProviderRegistry::GOOGLE),
                'status' => CoreIntegration::STATUS_ACTIVE,
                'config' => [],
            ],
        );
        $integration->update(['status' => CoreIntegration::STATUS_ACTIVE]);

        if ($integration->providerCredential()->doesntExist()) {
            CoreIntegrationCredential::factory()->provider()->create([
                'integration_id' => $integration->id,
                'encrypted_payload' => [
                    'client_id' => 'cid',
                    'client_secret' => 'csecret',
                    'developer_token' => 'dev-token',
                ],
            ]);
        }
        if ($integration->authorizationCredential()->doesntExist()) {
            CoreIntegrationCredential::factory()->authorization()->create([
                'integration_id' => $integration->id,
                'encrypted_payload' => [
                    'access_token' => 'atok',
                    'refresh_token' => 'rtok',
                ],
                'expires_at' => now()->addHour(),
            ]);
        }

        return $integration->fresh(['providerCredential', 'authorizationCredential']) ?? $integration;
    }

    private function collectWebsiteHomepage(DigitalAsset $asset, bool $missingTitle): void
    {
        $definition = WebsiteRequestFamilyCatalog::definition(WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS);
        $run = CollectionRun::factory()->create([
            'digital_asset_id' => $asset->id,
            'brand_id' => $asset->brand_id,
            'customer_id' => $asset->brand?->customer_id,
            'status' => CollectionRunStatus::Running,
            'request_context' => [
                'context' => ['collection_intent' => 'website_production_collection'],
            ],
        ]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'WEBSITE_DIRECT',
            'resource_kind' => 'website_asset_capability',
            'external_resource_id' => null,
            'digital_asset_id' => $asset->id,
            'core_asset_binding_id' => null,
            'status' => CollectionRunStatus::Running,
        ]);
        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'WEBSITE_DIRECT',
            'dataset_contract_id' => $definition['dataset_ids'][0],
            'request_family_id' => WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS,
            'contract_registry_version' => 1,
            'status' => CollectionRunStatus::Running,
        ]);

        $seed = $missingTitle ? 'http://1.1.1.1/' : 'http://1.1.1.1/titled';
        $asset->forceFill(['primary_url' => $seed])->save();

        $context = new DatasetExecutionContext(
            collectionRun: $run,
            resourceRun: $resourceRun,
            datasetRun: $datasetRun,
            checkpoint: [],
            registryDataset: [],
            registryRequestFamily: [],
            attemptNumber: 1,
        );

        $executor = app(WebsiteDatasetExecutor::class);
        $result = $executor->execute($context);
        $this->assertContains(
            $result->outcome,
            [DatasetExecutionOutcome::Continue, DatasetExecutionOutcome::Completed],
            (string) $result->errorMessage,
        );
        if ($result->checkpoint !== null) {
            app(CheckpointManager::class)->advance($datasetRun, $result->checkpoint);
        }
    }
}
