<?php

namespace Tests\Feature\DataPool;

use App\Models\Collection\CollectionDatasetRun;
use App\Models\CoreExternalResource;
use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Resource-first natural keys exclude digital_asset_id, so a central (digital_asset_id = null)
 * restatement conflicts with the asset-bound row for the same resource/grain. The upsert must
 * refresh metrics without clearing the bound asset attribution. Runs on SQLite by default and on
 * native PostgreSQL ON CONFLICT under the `postgres` group workflow.
 */
#[Group('postgres')]
class CentralRestatementPreservesAssetAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('raw_ingestion');
        config(['moxdop-data-pool.raw_disk' => 'raw_ingestion']);
    }

    #[Test]
    public function central_ga4_restatement_keeps_bound_digital_asset_id(): void
    {
        $resourceId = (int) CoreExternalResource::factory()->create()->id;

        $this->writeGa4($resourceId, digitalAssetId: 42, sessions: 10, batchKey: 'bound');
        $this->writeGa4($resourceId, digitalAssetId: null, sessions: 25, batchKey: 'central-restatement');

        $this->assertSame(1, DB::table('ga4_property_daily')->count());
        $row = DB::table('ga4_property_daily')->first();
        $this->assertSame(25, (int) $row->sessions);
        $this->assertSame(42, (int) $row->digital_asset_id);
        $this->assertSame($resourceId, (int) $row->external_resource_id);
    }

    #[Test]
    public function non_null_asset_still_sets_or_rebinds_attribution(): void
    {
        $resourceId = (int) CoreExternalResource::factory()->create()->id;

        $this->writeGa4($resourceId, digitalAssetId: null, sessions: 3, batchKey: 'central-first');
        $this->assertNull(DB::table('ga4_property_daily')->value('digital_asset_id'));

        $this->writeGa4($resourceId, digitalAssetId: 42, sessions: 4, batchKey: 'bound-later');
        $this->assertSame(42, (int) DB::table('ga4_property_daily')->value('digital_asset_id'));

        $this->writeGa4($resourceId, digitalAssetId: 43, sessions: 5, batchKey: 'rebound');
        $this->assertSame(1, DB::table('ga4_property_daily')->count());
        $this->assertSame(43, (int) DB::table('ga4_property_daily')->value('digital_asset_id'));
        $this->assertSame(5, (int) DB::table('ga4_property_daily')->value('sessions'));
    }

    #[Test]
    public function central_gsc_restatement_keeps_bound_digital_asset_id(): void
    {
        $resourceId = (int) CoreExternalResource::factory()->searchConsole()->create()->id;

        foreach ([['bound', 7, 1], ['central-restatement', null, 9]] as [$batchKey, $assetId, $clicks]) {
            $run = CollectionDatasetRun::factory()->create([
                'dataset_contract_id' => 'gsc_query_daily',
                'provider_or_source' => 'SEARCH_CONSOLE',
            ]);
            app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
                datasetId: 'gsc_query_daily',
                datasetRunId: (int) $run->id,
                contractVersion: 1,
                batchKey: $batchKey,
                records: [[
                    'digital_asset_id' => $assetId,
                    'external_resource_id' => $resourceId,
                    'site_url' => 'https://example.com/',
                    'reporting_date' => '2026-08-05',
                    'search_type' => 'web',
                    'query' => 'moxdop',
                    'clicks' => $clicks,
                    'impressions' => 40,
                ]],
                digitalAssetId: $assetId,
                externalResourceId: $resourceId,
                collectionRunId: (int) $run->collection_run_id,
                providerOrSource: 'SEARCH_CONSOLE',
            ));
        }

        $this->assertSame(1, DB::table('gsc_query_daily')->count());
        $this->assertSame(9, (int) DB::table('gsc_query_daily')->value('clicks'));
        $this->assertSame(7, (int) DB::table('gsc_query_daily')->value('digital_asset_id'));
    }

    private function writeGa4(int $resourceId, ?int $digitalAssetId, int $sessions, string $batchKey): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'ga4_property_daily',
            'provider_or_source' => 'GA4',
        ]);

        $receipt = app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
            datasetId: 'ga4_property_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: $batchKey,
            records: [[
                'digital_asset_id' => $digitalAssetId,
                'external_resource_id' => $resourceId,
                'property_id' => 'properties/123',
                'reporting_date' => '2026-08-01',
                'sessions' => $sessions,
                'engagedSessions' => 1,
                'screenPageViews' => 2,
                'totalUsers' => 3,
                'activeUsers' => 3,
            ]],
            digitalAssetId: $digitalAssetId,
            externalResourceId: $resourceId,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'GA4',
        ));

        $this->assertTrue($receipt->isCommitted());
    }
}
