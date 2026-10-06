<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Enums\CustomerStatus;
use App\Enums\DataPool\FreshnessState;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Models\ResourceAutomation;
use App\Services\Collection\Activity\ActivityTierService;
use App\Services\Collection\GoogleAds\GoogleAdsCentralCollectionService;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralDatasetExecutorAdapter;
use App\Services\Collection\Providers\GoogleAds\GoogleAdsCentralRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\DataPool\Freshness\DueCollectionQueryService;
use App\Services\DataPool\Integrity\Support\CoverageIntervalSet;
use App\Services\Observability\OperationalAlertEvaluator;
use App\Support\Integrations\Google\GoogleScopes;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use ReflectionMethod;
use Tests\TestCase;

/**
 * A Google Ads account whose ads stopped months ago: the initial import fetches daily data for its active periods only,
 * and the monthly history marks every other day of the 13-month lookback as a zero day of the account daily totals.
 * The account is neither late nor partial after the import, and its weekly dormant checks leave no hole.
 */
final class GoogleAdsInactiveDaysCoverageTest extends TestCase
{
    use RefreshDatabase;

    private CoreIntegration $google;

    private CoreExternalResource $resource;

    private CoreAssetBinding $binding;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-10-06 10:00:00');
        Queue::fake();
        Storage::fake('raw_ingestion');
        config([
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop.google.developer_token' => 'app-level-dev-token',
            'moxdop.google.ads_api_version' => 'v25',
            'moxdop-google-ads-collector.minimum_request_interval_ms' => 0,
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
            'external_id' => '1112227001', 'display_name' => 'Uyuyan Hesap', 'status' => CoreExternalResource::STATUS_AVAILABLE,
            'metadata' => ['is_manager' => false, 'time_zone' => 'UTC', 'currency_code' => 'TRY'],
        ]);
        ResourceAutomation::query()->create(['external_resource_id' => $this->resource->id, 'collection_enabled' => true, 'next_collection_at' => now()]);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $this->binding = CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $this->resource->id,
            'capability' => 'google_ads', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->fakeGoogleAds(['2025-03-01', '2025-04-01', '2025-05-01', '2025-10-01']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_inactive_months_are_zero_days_and_dormant_checks_leave_no_hole(): void
    {
        // Initial import: daily data only for the active period inside the lookback (2025-10), plus the monthly history.
        $initial = app(GoogleAdsCentralCollectionService::class)->startSmartUpdate($this->google, [$this->resource->id]);
        $accountDaily = $this->accountDailyRuns($initial);
        $this->assertSame([['start' => '2025-10-01', 'end' => '2025-10-31']], $accountDaily->map(fn (CollectionDatasetRun $d): array => $d->metadata['date_range'])->values()->all());
        $this->execute($this->datasetRun($initial, GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_MONTHLY_HISTORY));
        $this->execute($accountDaily->first());
        $this->finish($initial);

        // Contiguous from the 13-month boundary through yesterday: no hole between periods, no tail after 2025-10.
        $this->assertSame([['start' => '2025-09-07', 'end' => '2026-10-05']], $this->coverageIntervals());
        $this->assertNotLateNorPartial();
        $this->assertSame(0, $this->evaluateStale());

        // Weekly dormant check a week later, and the next one eight days after that (a slipped day).
        $this->at('2026-10-13 10:00:00');
        $this->assertSame(['start' => '2026-10-06', 'end' => '2026-10-12'], $this->weeklyCheck());
        $this->assertNotLateNorPartial();

        $this->at('2026-10-21 10:00:00');
        $this->assertSame(['start' => '2026-10-13', 'end' => '2026-10-20'], $this->weeklyCheck(), 'starts where the stored coverage ends');
        $this->assertSame([['start' => '2025-09-07', 'end' => '2026-10-20']], $this->coverageIntervals());
        $this->assertNotLateNorPartial();
        $this->assertSame(0, $this->evaluateStale());
    }

    // ---------------------------------------------------------------- helpers

    private function at(string $time): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($time, 'UTC'));
        Carbon::setTestNow(Carbon::parse($time, 'UTC'));
    }

    /**
     * Google Ads answers: monthly history rows with ads in the given months, no account daily rows (no ads on any
     * day that is fetched), empty answers for everything else.
     *
     * @param  list<string>  $activeMonths
     */
    private function fakeGoogleAds(array $activeMonths): void
    {
        $monthly = array_map(fn (string $month): array => [
            'segments' => ['month' => $month],
            'metrics' => ['impressions' => '1200', 'clicks' => '40', 'costMicros' => '250000000', 'conversions' => 3, 'conversionsValue' => 0],
        ], $activeMonths);
        Http::fake(function ($request) use ($monthly) {
            $query = is_array($request->data()) ? (string) ($request->data()['query'] ?? '') : '';
            if (str_contains($query, 'segments.month')) {
                return Http::response([['results' => $monthly]]);
            }

            return str_contains($request->url(), 'searchStream')
                ? Http::response([['results' => []]])
                : Http::response(['results' => []]);
        });
    }

    /** Runs a dataset through the central Google Ads executor until it finishes. */
    private function execute(CollectionDatasetRun $datasetRun): void
    {
        $executor = app(GoogleAdsCentralDatasetExecutorAdapter::class);
        $datasetRun->update(['status' => CollectionRunStatus::Running]);
        $checkpoint = [];
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
            if ($result->outcome !== DatasetExecutionOutcome::Continue) {
                $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome, (string) $datasetRun->request_family_id.': '.($result->errorMessage ?? ''));
                $datasetRun->update(['status' => CollectionRunStatus::Completed, 'finished_at' => now(), 'checkpoint' => $result->checkpoint ?? []]);

                return;
            }
            $checkpoint = $result->checkpoint ?? [];
            $datasetRun->update(['checkpoint' => $checkpoint]);
        }
        $this->fail('Dataset run did not finish: '.$datasetRun->request_family_id);
    }

    /** The collection finished: every dataset, the account and the run completed (the other families are not under test). */
    private function finish(CollectionRun $run): void
    {
        CollectionDatasetRun::query()->where('collection_run_id', $run->id)->update(['status' => CollectionRunStatus::Completed->value, 'finished_at' => now()]);
        CollectionResourceRun::query()->where('collection_run_id', $run->id)->update(['status' => CollectionRunStatus::Completed->value, 'finished_at' => now()]);
        $run->update(['status' => CollectionRunStatus::Completed, 'finished_at' => now()]);
        ResourceAutomation::query()->where('external_resource_id', $this->resource->id)->update(['last_collection_success_at' => now()]);
        app(ActivityTierService::class)->refreshResource($this->resource);
    }

    /** @return array{start: string, end: string} */
    private function weeklyCheck(): array
    {
        $run = app(GoogleAdsCentralCollectionService::class)->startSmartUpdate($this->google, [$this->resource->id]);
        $this->assertSame('check', data_get($run->resourceRuns->first()->metadata, 'activity.mode'));
        $datasets = $this->accountDailyRuns($run);
        $this->assertCount(1, $datasets);
        $this->assertSame(1, $run->datasetRuns()->count(), 'dormant: account totals only');
        $this->execute($datasets->first());
        $this->finish($run);

        return $datasets->first()->metadata['date_range'];
    }

    /** @return Collection<int, CollectionDatasetRun> */
    private function accountDailyRuns(CollectionRun $run)
    {
        return $run->datasetRuns()->where('request_family_id', GoogleAdsCentralRequestFamilyCatalog::ACCOUNT_DAILY)->orderBy('id')->get();
    }

    private function datasetRun(CollectionRun $run, string $family): CollectionDatasetRun
    {
        return $run->datasetRuns()->where('request_family_id', $family)->firstOrFail();
    }

    /** @return list<array{start: string, end: string}> */
    private function coverageIntervals(): array
    {
        $materialization = DatasetMaterialization::query()->where('dataset_id', 'google_ads_account_daily')
            ->whereNull('digital_asset_id')->where('external_resource_id', $this->resource->id)->firstOrFail();

        return CoverageIntervalSet::fromSuccessfulDates((array) data_get($materialization->freshness_metadata, 'successful_coverage_dates', []))->intervals;
    }

    private function assertNotLateNorPartial(): void
    {
        $items = app(DueCollectionQueryService::class)->query(['core_asset_binding_ids' => [$this->binding->id], 'activity_tiers' => true]);
        foreach ($items as $item) {
            $this->assertSame('google_ads_account_daily', $item->datasetId, 'a dormant account is judged by its light set only');
            $this->assertContains($item->freshnessState, [FreshnessState::Fresh, FreshnessState::Due], 'google_ads_account_daily is '.$item->freshnessState->value);
        }
    }

    private function evaluateStale(): int
    {
        return (new ReflectionMethod(OperationalAlertEvaluator::class, 'evaluateStaleDatasets'))->invoke(app(OperationalAlertEvaluator::class));
    }
}
