<?php

namespace Tests\Feature\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\DatasetExecutionOutcome;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DataPool\DatasetMaterialization;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleCentralDatasetExecutor;
use App\Services\Collection\Providers\SearchConsole\SearchConsoleRequestFamilyCatalog;
use App\Services\Collection\Support\DatasetExecutionContext;
use App\Services\Collection\Support\DatasetExecutionResult;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Resource-first (central) Search Console collector: Search Appearance two-step flow and
 * durable zero-row coverage.
 */
class GscCentralCollectorTest extends TestCase
{
    use RefreshDatabase;

    private CoreExternalResource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('raw_ingestion');
        config([
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'moxdop-gsc-collector.page_size' => 25000,
            'moxdop-gsc-collector.max_pages_per_tick' => 50,
        ]);

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::SEARCH_CONSOLE_READONLY]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'gsc-access-token',
                'refresh_token' => 'gsc-refresh-token',
                'scope' => GoogleScopes::SEARCH_CONSOLE_READONLY,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GSC_PROPERTY,
            'external_id' => 'sc-domain:example.com',
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
    }

    #[Test]
    public function search_appearance_discovers_values_then_filters_and_writes_each_fixed_value(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => function (Request $request) {
                if ($request['dimensions'] === ['searchAppearance']) {
                    return Http::response(['rows' => [
                        ['keys' => ['AMP_BLUE_LINK'], 'clicks' => 3, 'impressions' => 30],
                        ['keys' => ['VIDEO'], 'clicks' => 1, 'impressions' => 10],
                    ]], 200);
                }

                $appearance = $request['dimensionFilterGroups'][0]['filters'][0]['expression'] ?? null;

                return Http::response(['rows' => [
                    ['keys' => ['2026-07-01'], 'clicks' => $appearance === 'VIDEO' ? 1 : 3, 'impressions' => 10, 'ctr' => 0.1, 'position' => 4.0],
                ]], 200);
            },
        ]);

        $result = $this->runToCompletion(
            SearchConsoleRequestFamilyCatalog::FAMILY_SEARCH_APPEARANCE_DAILY,
            'gsc_search_appearance_daily',
            ['date', 'searchAppearance'],
            '2026-07-01',
            '2026-07-03',
        );

        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome);
        $rows = DB::table('gsc_search_appearance_daily')->orderBy('searchAppearance')->get();
        $this->assertSame(['AMP_BLUE_LINK', 'VIDEO'], $rows->pluck('searchAppearance')->all());
        $this->assertSame([3, 1], $rows->pluck('clicks')->map(fn ($v): int => (int) $v)->all());
        $this->assertTrue($rows->every(fn ($row): bool => $row->digital_asset_id === null));

        foreach (Http::recorded() as [$request]) {
            $dimensions = $request['dimensions'];
            $this->assertTrue(
                $dimensions === ['searchAppearance'] || ! in_array('searchAppearance', $dimensions, true),
                'searchAppearance must never be grouped with other dimensions.',
            );
        }
    }

    #[Test]
    public function zero_row_slices_record_durable_central_coverage(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []], 200),
        ]);

        $result = $this->runToCompletion(
            SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY,
            'gsc_property_daily',
            ['date'],
            '2026-07-01',
            '2026-07-10',
            sliceDays: 7,
        );

        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome);
        $this->assertSame(0, DB::table('gsc_property_daily')->count());

        $materialization = $this->centralMaterialization('gsc_property_daily');
        $this->assertNotNull($materialization);
        $expected = array_map(fn (int $day): string => sprintf('2026-07-%02d', $day), range(1, 10));
        $this->assertSame($expected, $materialization->freshness_metadata['successful_coverage_dates']);
        $this->assertSame($expected, $materialization->freshness_metadata['zero_row_success_dates']);
    }

    #[Test]
    public function days_without_rows_inside_a_non_empty_slice_are_still_covered(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => [
                ['keys' => ['2026-07-02'], 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 3.0],
            ]], 200),
        ]);

        $this->runToCompletion(
            SearchConsoleRequestFamilyCatalog::FAMILY_PROPERTY_DAILY,
            'gsc_property_daily',
            ['date'],
            '2026-07-01',
            '2026-07-03',
            sliceDays: 7,
        );

        $materialization = $this->centralMaterialization('gsc_property_daily');
        $this->assertSame(['2026-07-01', '2026-07-02', '2026-07-03'], $materialization->freshness_metadata['successful_coverage_dates']);
        $this->assertArrayNotHasKey('zero_row_success_dates', $materialization->freshness_metadata);
    }

    #[Test]
    public function search_appearance_without_any_appearance_records_zero_row_coverage(): void
    {
        Http::fake([
            'https://www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => []], 200),
        ]);

        $result = $this->runToCompletion(
            SearchConsoleRequestFamilyCatalog::FAMILY_SEARCH_APPEARANCE_DAILY,
            'gsc_search_appearance_daily',
            ['date', 'searchAppearance'],
            '2026-07-01',
            '2026-07-02',
        );

        $this->assertSame(DatasetExecutionOutcome::Completed, $result->outcome);
        $materialization = $this->centralMaterialization('gsc_search_appearance_daily');
        $this->assertSame(['2026-07-01', '2026-07-02'], $materialization->freshness_metadata['zero_row_success_dates']);
    }

    private function centralMaterialization(string $datasetId): ?DatasetMaterialization
    {
        return DatasetMaterialization::query()
            ->where('dataset_id', $datasetId)
            ->whereNull('digital_asset_id')
            ->where('external_resource_id', $this->resource->id)
            ->first();
    }

    /**
     * @param  list<string>  $dimensions
     */
    private function runToCompletion(
        string $sourceFamilyId,
        string $datasetId,
        array $dimensions,
        string $start,
        string $end,
        int $sliceDays = 1,
    ): DatasetExecutionResult {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Running]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'resource_kind' => 'provider_resource',
            'external_resource_id' => $this->resource->id,
            'digital_asset_id' => null,
            'core_asset_binding_id' => null,
            'status' => CollectionRunStatus::Running,
            'metadata' => ['collection_scope' => 'provider_resource_first'],
        ]);
        $definition = array_merge(SearchConsoleRequestFamilyCatalog::definition($sourceFamilyId), [
            'dataset_id' => $datasetId,
            'dimensions' => $dimensions,
            'search_type' => 'web',
            'data_state' => 'final',
            'slice_days' => $sliceDays,
        ]);
        $datasetRun = CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id,
            'collection_resource_run_id' => $resourceRun->id,
            'provider_or_source' => 'SEARCH_CONSOLE',
            'dataset_contract_id' => $datasetId,
            'request_family_id' => SearchConsoleCentralDatasetExecutor::FAMILY_ANALYTICS,
            'status' => CollectionRunStatus::Running,
            'metadata' => [
                'collection_scope' => 'provider_resource_first',
                'central_definition' => $definition,
                'date_range' => ['start' => $start, 'end' => $end],
                'search_type' => 'web',
            ],
        ]);

        $executor = app(SearchConsoleCentralDatasetExecutor::class);
        $checkpoint = [];
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $result = $executor->execute(new DatasetExecutionContext(
                collectionRun: $run->fresh(),
                resourceRun: $resourceRun->fresh(),
                datasetRun: $datasetRun->fresh(),
                checkpoint: $checkpoint,
                registryDataset: [],
                registryRequestFamily: [],
                attemptNumber: $attempt,
            ));
            if ($result->outcome !== DatasetExecutionOutcome::Continue) {
                return $result;
            }
            $checkpoint = $result->checkpoint ?? [];
        }

        $this->fail('Central GSC executor did not complete.');
    }
}
