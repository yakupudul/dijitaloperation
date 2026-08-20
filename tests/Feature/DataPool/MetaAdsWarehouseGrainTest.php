<?php

namespace Tests\Feature\DataPool;

use App\Models\Collection\CollectionDatasetRun;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsWarehouseGrainTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('raw_ingestion');
        config([
            'moxdop-data-pool.raw_disk' => 'raw_ingestion',
            'moxdop-data-pool.raw_compression' => 'gzip',
            'filesystems.disks.raw_ingestion' => [
                'driver' => 'local',
                'root' => storage_path('framework/testing/raw_ingestion'),
                'throw' => true,
            ],
        ]);
    }

    #[Test]
    public function all_meta_physical_tables_upsert_on_storage_natural_keys(): void
    {
        $cases = [
            'meta_ad_account_snapshot' => [
                'first' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'source_timezone' => 'Europe/Berlin',
                    'metadata' => ['name' => 'Account A'],
                ],
                'second' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'source_timezone' => 'Europe/Berlin',
                    'metadata' => ['name' => 'Account A renamed'],
                ],
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_ad_account_snapshot')->count());
                    $meta = json_decode((string) DB::table('meta_ad_account_snapshot')->value('metadata'), true);
                    $this->assertSame('Account A renamed', $meta['name'] ?? null);
                },
            ],
            'meta_campaign_snapshot' => [
                'first' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'campaign_id' => '1001',
                    'source_timezone' => 'Europe/Berlin',
                    'metadata' => ['name' => 'Campaign'],
                ],
                'second' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'campaign_id' => '1001',
                    'source_timezone' => 'Europe/Berlin',
                    'metadata' => ['name' => 'Campaign renamed'],
                ],
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_campaign_snapshot')->count());
                    $meta = json_decode((string) DB::table('meta_campaign_snapshot')->value('metadata'), true);
                    $this->assertSame('Campaign renamed', $meta['name'] ?? null);
                },
            ],
            'meta_adset_snapshot' => [
                'first' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'adset_id' => '2001',
                    'source_timezone' => 'Europe/Berlin',
                    'metadata' => ['name' => 'Ad set'],
                ],
                'second' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'adset_id' => '2001',
                    'source_timezone' => 'Europe/Berlin',
                    'metadata' => ['name' => 'Ad set renamed'],
                ],
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_adset_snapshot')->count());
                    $meta = json_decode((string) DB::table('meta_adset_snapshot')->value('metadata'), true);
                    $this->assertSame('Ad set renamed', $meta['name'] ?? null);
                },
            ],
            'meta_creative_snapshot' => [
                'first' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'creative_id' => '4001',
                    'source_timezone' => 'Europe/Berlin',
                    'metadata' => ['name' => 'Creative'],
                ],
                'second' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'creative_id' => '4001',
                    'source_timezone' => 'Europe/Berlin',
                    'metadata' => ['name' => 'Creative renamed'],
                ],
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_creative_snapshot')->count());
                    $meta = json_decode((string) DB::table('meta_creative_snapshot')->value('metadata'), true);
                    $this->assertSame('Creative renamed', $meta['name'] ?? null);
                },
            ],
            'meta_campaign_daily' => [
                'first' => $this->dailyFact(['campaign_id' => '1001', 'spend' => '12.34']),
                'second' => $this->dailyFact(['campaign_id' => '1001', 'spend' => '99.99']),
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_campaign_daily')->count());
                    $this->assertSame('99.990000', number_format((float) DB::table('meta_campaign_daily')->value('spend'), 6, '.', ''));
                },
            ],
            'meta_adset_daily' => [
                'first' => $this->dailyFact(['adset_id' => '2001', 'spend' => '1.00']),
                'second' => $this->dailyFact(['adset_id' => '2001', 'spend' => '2.50']),
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_adset_daily')->count());
                    $this->assertSame('2.500000', number_format((float) DB::table('meta_adset_daily')->value('spend'), 6, '.', ''));
                },
            ],
            'meta_ad_daily' => [
                'first' => $this->dailyFact(['ad_id' => '3001', 'spend' => '3.00']),
                'second' => $this->dailyFact(['ad_id' => '3001', 'spend' => '4.25']),
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_ad_daily')->count());
                    $this->assertSame('4.250000', number_format((float) DB::table('meta_ad_daily')->value('spend'), 6, '.', ''));
                },
            ],
            'meta_typed_action_daily' => [
                'first' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'reporting_date' => '2026-08-01',
                    'entity_level' => 'campaign',
                    'entity_id' => '1001',
                    'action_type' => 'lead',
                    'action_value' => '10.000000',
                    'currency' => 'EUR',
                    'source_timezone' => 'Europe/Berlin',
                ],
                'second' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'reporting_date' => '2026-08-01',
                    'entity_level' => 'campaign',
                    'entity_id' => '1001',
                    'action_type' => 'lead',
                    'action_value' => '17.000000',
                    'currency' => 'EUR',
                    'source_timezone' => 'Europe/Berlin',
                ],
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_typed_action_daily')->where('action_type', 'lead')->count());
                    $this->assertSame('17.000000', number_format((float) DB::table('meta_typed_action_daily')->value('action_value'), 6, '.', ''));
                },
            ],
            'meta_delivery_breakdown_daily' => [
                'first' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'reporting_date' => '2026-08-01',
                    'entity_id' => '11110001',
                    'breakdown_type' => 'age',
                    'breakdown_value' => '25-34',
                    'spend' => '1.00',
                    'impressions' => 10,
                    'clicks' => 1,
                    'currency' => 'EUR',
                    'source_timezone' => 'Europe/Berlin',
                ],
                'second' => [
                    'digital_asset_id' => 11,
                    'external_resource_id' => 21,
                    'account_id' => '11110001',
                    'reporting_date' => '2026-08-01',
                    'entity_id' => '11110001',
                    'breakdown_type' => 'age',
                    'breakdown_value' => '25-34',
                    'spend' => '8.50',
                    'impressions' => 10,
                    'clicks' => 1,
                    'currency' => 'EUR',
                    'source_timezone' => 'Europe/Berlin',
                ],
                'assert' => function (): void {
                    $this->assertSame(1, DB::table('meta_delivery_breakdown_daily')->where('breakdown_type', 'age')->count());
                    $this->assertSame('8.500000', number_format((float) DB::table('meta_delivery_breakdown_daily')->value('spend'), 6, '.', ''));
                },
            ],
        ];

        $pipeline = app(DatasetWritePipeline::class);

        foreach ($cases as $datasetId => $case) {
            $run = CollectionDatasetRun::factory()->create([
                'dataset_contract_id' => $datasetId,
                'request_family_id' => 'RF_META_INSIGHTS_DAILY',
                'provider_or_source' => 'META_ADS',
            ]);

            $this->commit($pipeline, $run, $datasetId, 'grain-1', $case['first']);
            $this->commit($pipeline, $run, $datasetId, 'grain-2', $case['second']);
            ($case['assert'])();
        }
    }

    #[Test]
    public function typed_action_and_breakdown_dimensions_remain_distinct_rows(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'meta_typed_action_daily',
            'provider_or_source' => 'META_ADS',
        ]);
        $pipeline = app(DatasetWritePipeline::class);

        $this->commit($pipeline, $run, 'meta_typed_action_daily', 'actions-distinct', [
            'digital_asset_id' => 11,
            'external_resource_id' => 21,
            'account_id' => '11110001',
            'reporting_date' => '2026-08-01',
            'entity_level' => 'campaign',
            'entity_id' => '1001',
            'action_type' => 'lead',
            'action_value' => '10.000000',
            'currency' => 'EUR',
        ], extraRecords: [[
            'digital_asset_id' => 11,
            'external_resource_id' => 21,
            'account_id' => '11110001',
            'reporting_date' => '2026-08-01',
            'entity_level' => 'campaign',
            'entity_id' => '1001',
            'action_type' => 'purchase',
            'action_value' => '2.000000',
            'currency' => 'EUR',
        ]]);
        $this->assertSame(2, DB::table('meta_typed_action_daily')->count());

        $breakdownRun = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'meta_delivery_breakdown_daily',
            'provider_or_source' => 'META_ADS',
        ]);
        $this->commit($pipeline, $breakdownRun, 'meta_delivery_breakdown_daily', 'bd-distinct', [
            'digital_asset_id' => 11,
            'external_resource_id' => 21,
            'account_id' => '11110001',
            'reporting_date' => '2026-08-01',
            'entity_id' => '11110001',
            'breakdown_type' => 'age',
            'breakdown_value' => '25-34',
            'spend' => '1.00',
            'impressions' => 10,
            'clicks' => 1,
            'currency' => 'EUR',
        ], extraRecords: [[
            'digital_asset_id' => 11,
            'external_resource_id' => 21,
            'account_id' => '11110001',
            'reporting_date' => '2026-08-01',
            'entity_id' => '11110001',
            'breakdown_type' => 'gender',
            'breakdown_value' => 'female',
            'spend' => '1.00',
            'impressions' => 10,
            'clicks' => 1,
            'currency' => 'EUR',
        ]]);
        $this->assertSame(2, DB::table('meta_delivery_breakdown_daily')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dailyFact(array $overrides): array
    {
        return array_merge([
            'digital_asset_id' => 11,
            'external_resource_id' => 21,
            'account_id' => '11110001',
            'reporting_date' => '2026-08-01',
            'spend' => '1.00',
            'impressions' => 10,
            'clicks' => 1,
            'currency' => 'EUR',
            'source_timezone' => 'Europe/Berlin',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<array<string, mixed>>  $extraRecords
     */
    private function commit(
        DatasetWritePipeline $pipeline,
        CollectionDatasetRun $run,
        string $datasetId,
        string $batchKey,
        array $record,
        array $extraRecords = [],
    ): void {
        $records = array_merge([$record], $extraRecords);
        $receipt = $pipeline->commit(
            new NormalizedDatasetBatch(
                datasetId: $datasetId,
                datasetRunId: (int) $run->id,
                contractVersion: 1,
                batchKey: $batchKey,
                records: $records,
                digitalAssetId: 11,
                externalResourceId: 21,
                collectionRunId: (int) $run->collection_run_id,
                resourceRunId: (int) $run->collection_resource_run_id,
                providerOrSource: 'META_ADS',
            ),
            new RawPayloadEnvelope(
                providerOrSource: 'META_ADS',
                collectionRunId: (int) $run->collection_run_id,
                resourceRunId: (int) $run->collection_resource_run_id,
                datasetRunId: (int) $run->id,
                logicalDatasetId: $datasetId,
                requestFamilyId: $run->request_family_id,
                batchKey: $batchKey,
                contentType: 'application/json',
                payload: json_encode(['data' => $records, 'request_id' => 'fb-req-'.$batchKey], JSON_THROW_ON_ERROR),
                providerSafeMetadata: ['request_id' => 'fb-req-'.$batchKey],
            ),
        );

        $this->assertTrue($receipt->isCommitted());
        $this->assertTrue($receipt->checkpointSafe);
        $this->assertNotNull($receipt->rawIngestionObjectId);
    }
}
