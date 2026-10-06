<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Services\Collection\CancellationService;
use App\Services\Collection\CheckpointManager;
use App\Services\Collection\CollectionErrorRecorder;
use App\Services\Collection\CollectionStateMachine;
use App\Services\Collection\CollectionStatusAggregator;
use App\Services\Collection\Contracts\DatasetExecutor;
use App\Services\Collection\Contracts\RetryPolicy;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\Collection\DatasetExecutorResolver;
use App\Services\Collection\ProgressReporter;
use App\Services\Collection\Providers\MetaAds\MetaAdsProfessionalDatasetExecutor;
use App\Services\Collection\Providers\MetaAds\MetaAdsProviderErrorMapper;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Services\Integrations\Meta\MetaApiClient;
use App\Services\Integrations\Meta\MetaException;
use App\Support\Integrations\Meta\MetaResourceType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Meta answers a too heavy insights request with code 1 "Please reduce the amount of data" (HTTP 500). It is not a
 * transient 5xx: the request is made smaller (page size, then date range), and only a request still too large at the
 * smallest size fails — as a request fix, never as a "geçici" provider error retried three times a day.
 */
final class MetaInsightsTooMuchDataTest extends TestCase
{
    use RefreshDatabase;

    private const string REDUCE = "Please reduce the amount of data you're asking for, then retry your request";

    private CoreIntegration $integration;

    private CoreExternalResource $resource;

    /** @var list<array{limit: int, since: string, until: string}> */
    private array $requests = [];

    /** @var array<string, mixed> the Graph error the faked client answers next */
    private array $graphError = [];

    private int $graphStatus = 500;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('raw_ingestion');
        config([
            'moxdop.meta.app_id' => '111222333',
            'moxdop.meta.app_secret' => 'synthetic-app-secret',
            'moxdop.meta.use_appsecret_proof' => false,
            'moxdop.meta.api_version' => 'v26.0',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
        ]);
        $this->integration = CoreIntegration::factory()->meta()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['auth_method' => 'oauth', 'auth_status' => 'connected', 'connection_status' => 'connected',
                'credential_status' => 'valid', 'granted_permissions' => ['ads_read']],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $this->integration->id,
            'encrypted_payload' => ['access_token' => 'EAAG-synthetic-meta-token-never-real', 'granted_permissions' => ['ads_read']],
        ]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $this->integration->id, 'provider' => 'meta', 'resource_type' => MetaResourceType::META_AD_ACCOUNT,
            'external_id' => 'act_11110001', 'display_name' => 'Synthetic Meta Ads', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['currency' => 'TRY', 'timezone_name' => 'UTC'],
        ]);
    }

    public function test_the_client_tells_too_much_data_apart_from_a_temporary_5xx(): void
    {
        Http::fake(fn (Request $request) => Http::response(['error' => $this->graphError], $this->graphStatus));
        $this->assertSame([MetaException::KIND_DATA_TOO_LARGE, 1, 99], $this->clientError(['code' => 1, 'error_subcode' => 99, 'message' => self::REDUCE], 500));
        $this->assertSame([MetaException::KIND_DATA_TOO_LARGE, 1, null], $this->clientError(['code' => 1, 'message' => self::REDUCE], 400));
        // Code 1 / subcode 99 is also Meta's generic "unknown error"; code 2 is a temporary outage.
        $this->assertSame([MetaException::KIND_HTTP, 1, 99], $this->clientError(['code' => 1, 'error_subcode' => 99, 'message' => 'An unknown error occurred'], 500));
        $this->assertSame([MetaException::KIND_HTTP, 2, null], $this->clientError(['code' => 2, 'message' => 'Service temporarily unavailable'], 500));

        $mapper = app(MetaAdsProviderErrorMapper::class);
        $tooLarge = $mapper->fromThrowable(new MetaException(self::REDUCE.' (code 1, subcode 99)', MetaException::KIND_DATA_TOO_LARGE, 500, 1, providerSubcode: 99));
        $this->assertSame([DatasetExecutionOutcome::Failed, CollectionErrorCategory::InvalidRequest, 'META_DATA_TOO_LARGE'], [$tooLarge->outcome, $tooLarge->errorCategory, $tooLarge->errorCode]);
        $this->assertStringContainsString('[meta-error-v2 · http 500 · code 1 · subcode 99]', (string) $tooLarge->errorMessage);
        $temporary = $mapper->fromThrowable(new MetaException('Service temporarily unavailable', MetaException::KIND_HTTP, 500, 2));
        $this->assertSame([DatasetExecutionOutcome::Retry, CollectionErrorCategory::Provider5xx, 'META_HTTP_2'], [$temporary->outcome, $temporary->errorCategory, $temporary->errorCode]);
    }

    public function test_a_one_day_ad_level_slice_is_asked_again_with_a_smaller_page_and_keeps_that_size(): void
    {
        $this->fakeInsights(fn (int $limit, int $days): bool => $limit > 100);
        $datasetRun = $this->datasetRun('META_V2_RF_AD_DAILY', 'meta_ad_daily', '2026-09-01', '2026-09-03');

        $first = $this->execute($datasetRun, []);

        $this->assertSame([500, 100], array_column($this->requests, 'limit'));
        $this->assertSame(DatasetExecutionOutcome::Continue, $first->outcome);
        $this->assertSame(['next_start' => '2026-09-02', 'limit' => 100, 'slice_days' => 1, 'last_slice' => ['start' => '2026-09-01', 'end' => '2026-09-01']], $first->checkpoint);
        $this->assertSame([1, 3], [$first->progressCurrent, $first->progressTotal]);

        // The next slices start with the reduced size.
        $this->requests = [];
        $second = $this->execute($datasetRun, $first->checkpoint);
        $third = $this->execute($datasetRun, $second->checkpoint);

        $this->assertSame([[100, '2026-09-02', '2026-09-02'], [100, '2026-09-03', '2026-09-03']], array_map(fn (array $request): array => array_values($request), $this->requests));
        $this->assertSame(DatasetExecutionOutcome::Completed, $third->outcome);
    }

    public function test_a_smaller_page_may_read_more_pages_than_a_full_one(): void
    {
        // 150 pages of 25 rows: over the 100-page cap of a 500-row page, but the same 50,000-row cap.
        $pages = 0;
        Http::fake(function (Request $request) use (&$pages) {
            $pages++;

            return Http::response(['data' => [], 'paging' => $pages < 150 ? ['next' => 'https://graph.facebook.com/v26.0/act_11110001/insights?after='.$pages] : []], 200);
        });
        $datasetRun = $this->datasetRun('META_V2_RF_AD_DAILY', 'meta_ad_daily', '2026-09-01', '2026-09-01');

        $result = $this->execute($datasetRun, ['next_start' => '2026-09-01', 'limit' => 25, 'slice_days' => 1]);

        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome);
        $this->assertSame(150, $pages);
    }

    public function test_a_week_slice_shrinks_its_page_then_its_date_range(): void
    {
        $this->fakeInsights(fn (int $limit, int $days): bool => $limit > 25 || $days > 3);
        $datasetRun = $this->datasetRun('META_V2_RF_CAMPAIGN_DAILY', 'meta_campaign_daily', '2026-09-01', '2026-09-10');

        $result = $this->execute($datasetRun, []);

        $this->assertSame([[500, 7], [100, 7], [25, 7], [25, 3]], array_map(fn (array $request): array => [$request['limit'], $this->days($request)], $this->requests));
        $this->assertSame(DatasetExecutionOutcome::Continue, $result->outcome);
        $this->assertSame(['next_start' => '2026-09-04', 'limit' => 25, 'slice_days' => 3], array_intersect_key($result->checkpoint, array_flip(['next_start', 'limit', 'slice_days'])));

        $this->requests = [];
        $next = $this->execute($datasetRun, $result->checkpoint);
        $last = $this->execute($datasetRun, $next->checkpoint);
        $this->assertSame([['2026-09-04', '2026-09-06'], ['2026-09-07', '2026-09-09']], array_map(fn (array $request): array => [$request['since'], $request['until']], $this->requests));
        $this->assertSame(DatasetExecutionOutcome::Continue, $last->outcome);
        $this->assertSame('2026-09-10', $last->checkpoint['next_start']);
        $this->assertSame(DatasetExecutionOutcome::Completed, $this->execute($datasetRun, $last->checkpoint)->outcome);
    }

    public function test_a_request_still_too_large_at_the_smallest_size_fails_as_a_request_fix(): void
    {
        $this->fakeInsights(fn (int $limit, int $days): bool => true);
        $datasetRun = $this->datasetRun('META_V2_RF_CAMPAIGN_DAILY', 'meta_campaign_daily', '2026-09-01', '2026-09-10');

        $result = $this->execute($datasetRun, []);

        $this->assertSame([[500, 7], [100, 7], [25, 7], [25, 3], [25, 1]], array_map(fn (array $request): array => [$request['limit'], $this->days($request)], $this->requests));
        $this->assertSame(DatasetExecutionOutcome::Failed, $result->outcome);
        $this->assertSame(CollectionErrorCategory::InvalidRequest, $result->errorCategory);
        $this->assertNotSame(CollectionErrorCategory::Provider5xx, $result->errorCategory);
        $this->assertSame('META_DATA_TOO_LARGE', $result->errorCode);
        $this->assertStringContainsString('limit 25 and a 1-day range', (string) $result->errorMessage);
        $this->assertStringContainsString('reduce the amount of data', (string) $result->errorMessage);
    }

    public function test_a_temporary_5xx_is_still_retried_without_shrinking(): void
    {
        Http::fake(function (Request $request) {
            $this->recordRequest($request);

            return Http::response(['error' => ['code' => 2, 'message' => 'Service temporarily unavailable']], 500);
        });
        $datasetRun = $this->datasetRun('META_V2_RF_AD_DAILY', 'meta_ad_daily', '2026-09-01', '2026-09-03');

        $result = $this->execute($datasetRun, []);

        $this->assertCount(1, $this->requests);
        $this->assertSame([DatasetExecutionOutcome::Retry, CollectionErrorCategory::Provider5xx, 'META_HTTP_2'], [$result->outcome, $result->errorCategory, $result->errorCode]);
    }

    public function test_a_transient_error_in_a_later_slice_is_retried_and_three_in_a_row_still_fail(): void
    {
        Queue::fake();
        $afterTwoSlices = $this->jobDatasetRun();
        $results = [
            DatasetExecutionResult::continueWithCheckpoint(['slice_index' => 1]),
            DatasetExecutionResult::continueWithCheckpoint(['slice_index' => 2]),
            DatasetExecutionResult::retry(CollectionErrorCategory::Provider5xx, 'Provider unavailable.', 45, 'META_HTTP'),
            DatasetExecutionResult::retry(CollectionErrorCategory::Provider5xx, 'Provider unavailable.', 45, 'META_HTTP'),
            DatasetExecutionResult::completed(1),
        ];
        $this->useExecutor($results);

        foreach (range(1, 3) as $attempt) {
            $this->runJob($afterTwoSlices);
        }
        $this->assertSame(CollectionRunStatus::Retrying, $afterTwoSlices->fresh()->status, 'the third slice\'s first 5xx is retried');
        $this->runJob($afterTwoSlices);
        $this->runJob($afterTwoSlices);
        $this->assertSame(CollectionRunStatus::Completed, $afterTwoSlices->fresh()->status);
        $this->assertSame(5, (int) $afterTwoSlices->fresh()->attempt_count);

        // Three failures in a row still end the step.
        $failing = $this->jobDatasetRun();
        $this->useExecutor([
            DatasetExecutionResult::continueWithCheckpoint(['slice_index' => 1]),
            ...array_fill(0, 3, DatasetExecutionResult::retry(CollectionErrorCategory::Provider5xx, 'Provider unavailable.', 45, 'META_HTTP')),
        ]);
        foreach (range(1, 4) as $attempt) {
            $this->runJob($failing);
        }
        $this->assertSame(CollectionRunStatus::Failed, $failing->fresh()->status);
        $this->assertSame(CollectionErrorCategory::Provider5xx, $failing->fresh()->error_category);
    }

    /**
     * Graph answers "reduce the amount of data" while `$tooLarge(limit, days)`, an empty page otherwise.
     *
     * @param  callable(int, int): bool  $tooLarge
     */
    private function fakeInsights(callable $tooLarge): void
    {
        Http::fake(function (Request $request) use ($tooLarge) {
            $made = $this->recordRequest($request);

            return $tooLarge($made['limit'], $this->days($made))
                ? Http::response(['error' => ['code' => 1, 'error_subcode' => 99, 'message' => self::REDUCE]], 500)
                : Http::response(['data' => []], 200);
        });
    }

    /** @return array{limit: int, since: string, until: string} */
    private function recordRequest(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        $range = json_decode((string) ($query['time_range'] ?? '{}'), true);

        return $this->requests[] = ['limit' => (int) ($query['limit'] ?? 0), 'since' => (string) ($range['since'] ?? ''), 'until' => (string) ($range['until'] ?? '')];
    }

    /** @param  array{since: string, until: string}  $request */
    private function days(array $request): int
    {
        return (int) round((strtotime($request['until']) - strtotime($request['since'])) / 86400) + 1;
    }

    private function datasetRun(string $family, string $dataset, string $start, string $end): CollectionDatasetRun
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Running, 'digital_asset_id' => null]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'META_ADS', 'external_resource_id' => $this->resource->id,
            'digital_asset_id' => null, 'core_asset_binding_id' => null, 'status' => CollectionRunStatus::Running,
            'metadata' => ['collection_scope' => 'provider_resource_first'],
        ]);

        return CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => 'META_ADS',
            'dataset_contract_id' => $dataset, 'request_family_id' => $family, 'status' => CollectionRunStatus::Running,
            'metadata' => ['date_range' => ['start' => $start, 'end' => $end]],
        ]);
    }

    /** @param  array<string, mixed>  $checkpoint */
    private function execute(CollectionDatasetRun $datasetRun, array $checkpoint): DatasetExecutionResult
    {
        $datasetRun = $datasetRun->fresh(['collectionRun', 'resourceRun']);

        return app(MetaAdsProfessionalDatasetExecutor::class)->execute(new DatasetExecutionContext(
            collectionRun: $datasetRun->collectionRun, resourceRun: $datasetRun->resourceRun, datasetRun: $datasetRun,
            checkpoint: $checkpoint, registryDataset: [], registryRequestFamily: [], attemptNumber: 1,
        ));
    }

    /**
     * @param  array<string, mixed>  $error
     * @return array{0: string, 1: ?int, 2: ?int}
     */
    private function clientError(array $error, int $status): array
    {
        [$this->graphError, $this->graphStatus] = [$error, $status];
        try {
            app(MetaApiClient::class)->get($this->integration, 'act_11110001/insights', ['level' => 'ad']);
        } catch (MetaException $exception) {
            return [$exception->kind, $exception->providerCode, $exception->providerSubcode];
        }
        $this->fail('Expected a MetaException.');
    }

    private function jobDatasetRun(): CollectionDatasetRun
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Running, 'digital_asset_id' => null]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'META_ADS', 'external_resource_id' => $this->resource->id,
            'digital_asset_id' => null, 'status' => CollectionRunStatus::Running,
        ]);

        return CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => 'META_ADS',
            'dataset_contract_id' => 'meta_ad_daily', 'request_family_id' => 'META_V2_RF_AD_DAILY', 'status' => CollectionRunStatus::Queued,
            'attempt_count' => 0, 'max_attempts' => 3, 'depends_on_dataset_run_ids' => null,
        ]);
    }

    /** @param  list<DatasetExecutionResult>  $results  returned in turn, one per execution */
    private function useExecutor(array $results): void
    {
        $this->app->instance(DatasetExecutorResolver::class, new DatasetExecutorResolver([new class($results) implements DatasetExecutor
        {
            /** @param  list<DatasetExecutionResult>  $results */
            public function __construct(private array $results) {}

            public function supportedRequestFamilies(): array
            {
                return ['META_V2_RF_AD_DAILY'];
            }

            public function execute(DatasetExecutionContext $context): DatasetExecutionResult
            {
                return array_shift($this->results) ?? DatasetExecutionResult::completed(1);
            }
        }]));
    }

    /** One worker pick-up of the step, after any retry wait. */
    private function runJob(CollectionDatasetRun $datasetRun): void
    {
        $this->travel(60)->seconds();
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
}
