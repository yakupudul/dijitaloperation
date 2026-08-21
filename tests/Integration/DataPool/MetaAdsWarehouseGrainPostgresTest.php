<?php

namespace Tests\Integration\DataPool;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\DataPool\PartitionManager;
use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PostgreSQL grain proof for Meta daily facts / breakdowns (native partitions + upsert).
 */
#[Group('postgres')]
class MetaAdsWarehouseGrainPostgresTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('PostgreSQL integration tests require DB_CONNECTION=pgsql');
        }

        Storage::fake('raw_ingestion');
        config(['moxdop-data-pool.raw_disk' => 'raw_ingestion']);
    }

    #[Test]
    public function meta_daily_and_breakdown_upserts_across_month_partitions_without_multiplying_rows(): void
    {
        $manager = app(PartitionManager::class);
        $manager->ensureRange('meta_campaign_daily', '2026-07-31', '2026-08-01');
        $manager->ensureRange('meta_delivery_breakdown_daily', '2026-07-31', '2026-08-01');

        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'meta_campaign_daily',
            'provider_or_source' => 'META_ADS',
        ]);
        $writer = app(PostgresWarehouseWriter::class);

        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'meta_campaign_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'meta-pg-campaign',
            records: [
                $this->campaignDaily('2026-07-31', '1.00'),
                $this->campaignDaily('2026-08-01', '2.00'),
            ],
            digitalAssetId: 11,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'META_ADS',
        ));
        $this->assertSame(2, DB::table('meta_campaign_daily')->count());

        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'meta_campaign_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'meta-pg-campaign-upsert',
            records: [$this->campaignDaily('2026-08-01', '9.99')],
            digitalAssetId: 11,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'META_ADS',
        ));
        $this->assertSame(2, DB::table('meta_campaign_daily')->count());
        $this->assertSame(
            '9.990000',
            number_format((float) DB::table('meta_campaign_daily')->where('reporting_date', '2026-08-01')->value('spend'), 6, '.', ''),
        );

        $breakdownRun = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'meta_delivery_breakdown_daily',
            'provider_or_source' => 'META_ADS',
        ]);
        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'meta_delivery_breakdown_daily',
            datasetRunId: (int) $breakdownRun->id,
            contractVersion: 1,
            batchKey: 'meta-pg-bd',
            records: [
                $this->breakdown('2026-07-31', 'age', '25-34', '1.00'),
                $this->breakdown('2026-08-01', 'age', '25-34', '2.00'),
                $this->breakdown('2026-08-01', 'gender', 'female', '2.00'),
            ],
            digitalAssetId: 11,
            collectionRunId: (int) $breakdownRun->collection_run_id,
            providerOrSource: 'META_ADS',
        ));
        $this->assertSame(3, DB::table('meta_delivery_breakdown_daily')->count());

        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'meta_delivery_breakdown_daily',
            datasetRunId: (int) $breakdownRun->id,
            contractVersion: 1,
            batchKey: 'meta-pg-bd-upsert',
            records: [$this->breakdown('2026-08-01', 'age', '25-34', '7.77')],
            digitalAssetId: 11,
            collectionRunId: (int) $breakdownRun->collection_run_id,
            providerOrSource: 'META_ADS',
        ));
        $this->assertSame(3, DB::table('meta_delivery_breakdown_daily')->count());
        $this->assertSame(
            '7.770000',
            number_format((float) DB::table('meta_delivery_breakdown_daily')
                ->where('reporting_date', '2026-08-01')
                ->where('breakdown_type', 'age')
                ->value('spend'), 6, '.', ''),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function campaignDaily(string $date, string $spend): array
    {
        return [
            'digital_asset_id' => 11,
            'account_id' => '11110001',
            'reporting_date' => $date,
            'campaign_id' => '1001',
            'spend' => $spend,
            'impressions' => 10,
            'clicks' => 1,
            'currency' => 'EUR',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function breakdown(string $date, string $type, string $value, string $spend): array
    {
        return [
            'digital_asset_id' => 11,
            'account_id' => '11110001',
            'reporting_date' => $date,
            'entity_id' => '11110001',
            'breakdown_type' => $type,
            'breakdown_value' => $value,
            'spend' => $spend,
            'impressions' => 10,
            'clicks' => 1,
            'currency' => 'EUR',
        ];
    }
}
