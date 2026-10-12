<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\ResourceAutomation;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralDatasetExecutorAdapter;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Support\Integrations\Google\GoogleScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Pool #36: a large account's structure (Avrupadent) has more Search pages than one tick may read. It is neither
 * stopped (PAGE_BOUND_EXCEEDED) nor re-read whole as one unbounded stream: each tick writes its pages and the next
 * tick resumes from the saved page token, so every page is requested exactly once.
 */
final class GoogleAdsStructurePagingResumeTest extends TestCase
{
    use RefreshDatabase;

    private CoreIntegration $google;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('raw_ingestion');
        config([
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop.google.developer_token' => 'app-level-dev-token',
            'moxdop.google.ads_api_version' => 'v25',
            'moxdop-google-ads-collector.minimum_request_interval_ms' => 0,
            'moxdop-google-ads-collector.max_search_pages_per_tick' => 2,
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
        ]);
        $this->google = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->google->id,
            'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $this->google->id,
            'encrypted_payload' => ['access_token' => 'ads-access-token', 'refresh_token' => 'ads-refresh-token', 'scope' => GoogleScopes::ADWORDS],
            'expires_at' => now()->addYear(),
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->google->id, 'provider' => 'google', 'resource_type' => 'google_ads',
            'external_id' => '1112227002', 'display_name' => 'Büyük Hesap', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'UTC', 'currency_code' => 'TRY'],
        ]);
        ResourceAutomation::query()->create(['external_resource_id' => $this->resource->id, 'collection_enabled' => true, 'next_collection_at' => now()]);
    }

    public function test_a_structure_longer_than_one_tick_resumes_from_the_page_token_without_a_stream(): void
    {
        $searches = [];
        $streamed = 0;
        Http::fake(function ($request) use (&$searches, &$streamed) {
            if (str_contains($request->url(), 'searchStream')) {
                if (preg_match('/FROM campaign\s/', (string) ($request->data()['query'] ?? '')) === 1) {
                    $streamed++;
                }

                return Http::response([['results' => []]]);
            }
            $data = is_array($request->data()) ? $request->data() : [];
            $query = (string) ($data['query'] ?? '');
            $token = (string) ($data['pageToken'] ?? data_get($data, 'page_token', ''));
            $isCampaigns = preg_match('/FROM campaign\s/', $query) === 1 && ! str_contains($query, 'segments.date');
            if (! $isCampaigns) {
                return Http::response(['results' => []]);
            }
            $searches[] = $token;
            // Five pages of one campaign each: p1 … p5.
            $page = $token === '' ? 1 : (int) substr($token, 1);
            $body = ['results' => [[
                'campaign' => ['id' => (string) (700 + $page), 'name' => 'Kampanya '.$page, 'status' => 'ENABLED', 'advertisingChannelType' => 'SEARCH'],
                'campaignBudget' => ['id' => (string) (900 + $page), 'amountMicros' => '50000000', 'deliveryMethod' => 'STANDARD', 'explicitlyShared' => false],
            ]]];
            if ($page < 5) {
                $body['nextPageToken'] = 'p'.($page + 1);
            }

            return Http::response($body);
        });

        $run = app(GoogleAdsCentralCollectionService::class)->startSmartUpdate($this->google, [$this->resource->id]);
        $datasetRun = $run->datasetRuns()->where('request_family_id', GoogleAdsCentralRequestFamilyCatalog::ENTITY_SNAPSHOT)->firstOrFail();
        $outcomes = $this->execute($datasetRun);

        $this->assertSame(DatasetExecutionOutcome::Completed, end($outcomes));
        $this->assertSame(0, $streamed, 'the structure is not re-read whole as one stream');
        $this->assertSame(['', 'p2', 'p3', 'p4', 'p5'], $searches, 'every page once, resumed from the saved token');
        $this->assertSame(['701', '702', '703', '704', '705'], DB::table('google_ads_campaign_snapshot')
            ->where('external_resource_id', $this->resource->id)->orderBy('campaign_id')->pluck('campaign_id')->map(fn ($id): string => (string) $id)->all());
    }

    /** @return list<DatasetExecutionOutcome> */
    private function execute(CollectionDatasetRun $datasetRun): array
    {
        $executor = app(GoogleAdsCentralDatasetExecutorAdapter::class);
        $datasetRun->update(['status' => CollectionRunStatus::Running]);
        $checkpoint = [];
        $outcomes = [];
        for ($attempt = 1; $attempt <= 40; $attempt++) {
            $datasetRun = $datasetRun->fresh();
            $result = $executor->execute(new DatasetExecutionContext(
                collectionRun: $datasetRun->collectionRun,
                resourceRun: $datasetRun->resourceRun,
                datasetRun: $datasetRun,
                checkpoint: $checkpoint,
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: $attempt,
            ));
            $outcomes[] = $result->outcome;
            if ($result->outcome !== DatasetExecutionOutcome::Continue) {
                $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) ($result->errorMessage ?? ''));

                return $outcomes;
            }
            $checkpoint = $result->checkpoint ?? [];
            $datasetRun->update(['checkpoint' => $checkpoint]);
        }
        $this->fail('Dataset run did not finish.');
    }
}
