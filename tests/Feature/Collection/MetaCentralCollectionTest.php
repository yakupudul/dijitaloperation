<?php

namespace Tests\Feature\Collection;

use App\Jobs\Async\ResourceCollectionJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\ResourceAutomation;
use App\Services\Collection\Meta\MetaCentralCollectionService;
use App\Services\Collection\Providers\MetaAds\MetaAdsEligibilityGuard;
use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\Integrations\Meta\MetaCredentialResolver;
use App\Services\Integrations\ResourceAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class MetaCentralCollectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['moxdop-resource-automation.queue_connection' => 'database']);
        Queue::fake();
    }

    private function account(): CoreExternalResource
    {
        $integration = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);

        return CoreExternalResource::factory()->create([
            'provider' => 'meta', 'resource_type' => 'meta_ads', 'integration_id' => $integration->id,
            'external_id' => 'act_123456', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['timezone_name' => 'Europe/Istanbul', 'currency' => 'TRY'],
        ]);
    }

    public function test_unbound_meta_account_is_admitted_and_collected_from_the_resource(): void
    {
        $resource = $this->account();
        $service = app(ResourceAutomationService::class);
        $this->assertNull($service->readiness($resource));

        $service->tick();
        $automation = ResourceAutomation::query()->where('external_resource_id', $resource->id)->firstOrFail();
        Queue::assertPushed(ResourceCollectionJob::class, fn ($job) => $job->automationId === $automation->id);

        $service->collect($automation->id);
        $automation->refresh();
        $this->assertSame('collecting', $automation->collection_status);
        $run = CollectionRun::query()->findOrFail($automation->collection_run_id);
        $resourceRun = CollectionResourceRun::query()->where('collection_run_id', $run->id)->sole();
        $this->assertNull($resourceRun->digital_asset_id);
        $this->assertNull($resourceRun->core_asset_binding_id);
        $this->assertSame('provider_resource_first', data_get($resourceRun->metadata, 'collection_scope'));

        $datasets = CollectionDatasetRun::query()->where('collection_run_id', $run->id)->pluck('dataset_contract_id')->all();
        $this->assertEqualsCanonicalizing(array_keys(MetaCentralCollectionService::plannedDatasets()), $datasets);
        $this->assertContains('meta_ad_daily', $datasets);
        $this->assertNotContains('meta_hourly_daily', $datasets, 'Only catalogue datasets are collected.');
        $daily = CollectionDatasetRun::query()->where('collection_run_id', $run->id)->where('dataset_contract_id', 'meta_campaign_daily')->sole();
        $end = now('Europe/Istanbul')->subDay()->toDateString();
        $this->assertSame($end, data_get($daily->metadata, 'date_range.end'));
        $this->assertSame(now('Europe/Istanbul')->subDay()->subDays(394)->toDateString(), data_get($daily->metadata, 'date_range.start'));
    }

    public function test_later_runs_restate_only_the_late_attribution_window(): void
    {
        $resource = $this->account();
        $end = now('Europe/Istanbul')->subDay()->toDateString();
        $old = CollectionRun::factory()->create(['status' => 'completed']);
        $oldResource = CollectionResourceRun::factory()->create(['collection_run_id' => $old->id, 'external_resource_id' => $resource->id, 'status' => 'completed']);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $old->id, 'collection_resource_run_id' => $oldResource->id, 'provider_or_source' => 'META_ADS',
            'dataset_contract_id' => 'meta_campaign_daily', 'request_family_id' => 'META_V2_RF_CAMPAIGN_DAILY', 'status' => 'completed',
            'metadata' => ['date_range' => ['start' => '2025-01-01', 'end' => $end]],
        ]);

        $run = app(MetaCentralCollectionService::class)->startSmartUpdate($resource->integration, [$resource->id]);
        $daily = CollectionDatasetRun::query()->where('collection_run_id', $run->id)->where('dataset_contract_id', 'meta_campaign_daily')->sole();
        $this->assertSame(now('Europe/Istanbul')->subDay()->subDays(6)->toDateString(), data_get($daily->metadata, 'date_range.start'));
    }

    public function test_eligibility_accepts_a_resource_first_run_without_binding(): void
    {
        $resource = $this->account();
        $credentials = $this->mock(MetaCredentialResolver::class);
        $credentials->shouldReceive('hasTenantAuthorization')->andReturn(true);
        $credentials->shouldReceive('accessToken')->andReturn('token-for-test');
        $run = CollectionRun::factory()->create();
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'external_resource_id' => $resource->id, 'core_asset_binding_id' => null,
            'digital_asset_id' => null, 'metadata' => ['collection_scope' => 'provider_resource_first'],
        ]);

        $scope = app(MetaAdsEligibilityGuard::class)->assertEligible($run, $resourceRun);
        $this->assertIsArray($scope);
        $this->assertNull($scope['asset']);
        $this->assertSame('act_123456', $scope['act_id']);
        $this->assertSame((int) $resource->id, (int) $scope['resource']->id);
    }

    public function test_central_rows_without_asset_are_upserted_by_account(): void
    {
        $datasetRun = CollectionDatasetRun::factory()->create(['dataset_contract_id' => 'meta_campaign_snapshot', 'provider_or_source' => 'META_ADS']);
        $write = fn (string $name) => app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
            datasetId: 'meta_campaign_snapshot', datasetRunId: (int) $datasetRun->id, contractVersion: 1, batchKey: 'b-'.$name,
            records: [['external_resource_id' => 77, 'account_id' => '123456', 'campaign_id' => 'c1', 'campaign_name' => $name]],
            digitalAssetId: null, externalResourceId: 77, collectionRunId: (int) $datasetRun->collection_run_id, providerOrSource: 'META_ADS',
        ));
        $write('Kampanya A');
        $write('Kampanya B');

        $rows = DB::table('meta_campaign_snapshot')->where('external_resource_id', 77)->get();
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->digital_asset_id);
    }
}
