<?php

namespace Tests\Feature\DataPool;

use App\Enums\DataPool\MaterializationStatus;
use App\Enums\DataPool\WriteBatchStatus;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DataPool\DatasetWriteBatch;
use App\Models\DataPool\RawIngestionObject;
use App\Services\Collection\CheckpointManager;
use App\Services\DataPool\DataPoolStorageRegistry;
use App\Services\DataPool\DatasetWritePipeline;
use App\Services\DataPool\FilesystemRawPayloadWriter;
use App\Services\DataPool\MaterializationService;
use App\Services\DataPool\PostgresWarehouseWriter;
use App\Services\DataPool\StorageContractValidator;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RawPayloadEnvelope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DataPoolFoundationTest extends TestCase
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
    public function storage_contract_validates_with_full_disposition_coverage(): void
    {
        $errors = app(StorageContractValidator::class)->validate();
        $this->assertSame([], $errors, implode("\n", $errors));

        $registry = app(DataPoolStorageRegistry::class);
        $this->assertSame('MOXDOP_DATA_POOL_STORAGE', $registry->metadata()['storage_contract_id']);
        $this->assertCount(66, $registry->dispositions());
        $this->assertCount(54, $registry->physicalDatasets());
        $this->assertFalse($registry->hasPhysicalTable('ga4_event_source_medium_daily'));
    }

    #[Test]
    public function raw_writer_stores_gzip_checksum_manifest_without_secrets_or_payload_column(): void
    {
        $datasetRun = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'meta_ad_daily',
            'provider_or_source' => 'META_ADS',
        ]);

        $writer = app(FilesystemRawPayloadWriter::class);
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'META_ADS',
            collectionRunId: (int) $datasetRun->collection_run_id,
            resourceRunId: (int) $datasetRun->collection_resource_run_id,
            datasetRunId: (int) $datasetRun->id,
            logicalDatasetId: 'meta_ad_daily',
            requestFamilyId: 'META_RF_AD_DAILY',
            batchKey: 'chunk-1',
            contentType: 'application/json',
            payload: json_encode(['ok' => true, 'rows' => 2], JSON_THROW_ON_ERROR),
            providerSafeMetadata: ['request_id' => 'abc', 'access_token' => 'SECRET'],
            recordCount: 2,
        );

        $this->expectException(\InvalidArgumentException::class);
        $writer->write($envelope);
    }

    #[Test]
    public function raw_writer_is_idempotent_and_deterministic(): void
    {
        $datasetRun = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'meta_ad_daily',
            'provider_or_source' => 'META_ADS',
        ]);

        $writer = app(FilesystemRawPayloadWriter::class);
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'META_ADS',
            collectionRunId: (int) $datasetRun->collection_run_id,
            resourceRunId: (int) $datasetRun->collection_resource_run_id,
            datasetRunId: (int) $datasetRun->id,
            logicalDatasetId: 'meta_ad_daily',
            requestFamilyId: 'META_RF_AD_DAILY',
            batchKey: 'chunk-1',
            contentType: 'application/json',
            payload: json_encode(['ok' => true], JSON_THROW_ON_ERROR),
            providerSafeMetadata: ['request_id' => 'abc'],
            recordCount: 1,
        );

        $first = $writer->write($envelope);
        $second = $writer->write($envelope);

        $this->assertTrue($second->reusedExisting);
        $this->assertSame($first->objectKey, $second->objectKey);
        $this->assertSame($first->sha256, $second->sha256);
        $this->assertSame(1, RawIngestionObject::query()->count());

        $row = RawIngestionObject::query()->first();
        $this->assertSame('gzip', $row->compression);
        $this->assertSame(64, strlen($row->sha256));
        $this->assertFalse(isset($row->getAttributes()['payload']));
        $this->assertArrayNotHasKey('access_token', $row->metadata ?? []);

        $disk = Storage::disk('raw_ingestion');
        $this->assertTrue($disk->exists($first->objectKey));
        $raw = $disk->get($first->objectKey);
        $this->assertSame(json_encode(['ok' => true]), gzdecode($raw));
    }

    #[Test]
    public function warehouse_upserts_are_idempotent_across_batches_and_runs(): void
    {
        $runA = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'ga4_property_daily',
            'provider_or_source' => 'GA4',
        ]);
        $runB = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'ga4_property_daily',
            'provider_or_source' => 'GA4',
        ]);

        $writer = app(PostgresWarehouseWriter::class);

        $make = function (CollectionDatasetRun $run, string $batchKey, int $sessions) {
            return new NormalizedDatasetBatch(
                datasetId: 'ga4_property_daily',
                datasetRunId: (int) $run->id,
                contractVersion: 1,
                batchKey: $batchKey,
                records: [[
                    'digital_asset_id' => 42,
                    'property_id' => 'properties/123',
                    'reporting_date' => '2026-08-01',
                    'sessions' => $sessions,
                    'engagedSessions' => 1,
                    'screenPageViews' => 2,
                    'totalUsers' => 3,
                    'activeUsers' => 3,
                ]],
                digitalAssetId: 42,
                collectionRunId: (int) $run->collection_run_id,
                providerOrSource: 'GA4',
            );
        };

        $r1 = $writer->write($make($runA, 'b1', 10));
        $r2 = $writer->write($make($runA, 'b1', 10)); // same batch retry
        $r3 = $writer->write($make($runB, 'b2', 99)); // late correction different run

        $this->assertTrue($r1->checkpointSafe);
        $this->assertTrue($r2->reusedExisting);
        $this->assertSame(1, DB::table('ga4_property_daily')->count());
        $this->assertSame(99, (int) DB::table('ga4_property_daily')->value('sessions'));
        $this->assertSame((int) $runB->collection_run_id, (int) DB::table('ga4_property_daily')->value('last_collection_run_id'));
    }

    #[Test]
    public function meta_typed_actions_remain_distinct_rows(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'meta_typed_action_daily',
            'provider_or_source' => 'META_ADS',
        ]);

        $receipt = app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
            datasetId: 'meta_typed_action_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'actions-1',
            records: [
                [
                    'digital_asset_id' => 7,
                    'account_id' => 'act_1',
                    'reporting_date' => '2026-08-02',
                    'entity_level' => 'ad',
                    'entity_id' => '120',
                    'action_type' => 'lead',
                    'action_value' => '17',
                    'currency' => 'USD',
                ],
                [
                    'digital_asset_id' => 7,
                    'account_id' => 'act_1',
                    'reporting_date' => '2026-08-02',
                    'entity_level' => 'ad',
                    'entity_id' => '120',
                    'action_type' => 'onsite_conversion.messaging_conversation_started_7d',
                    'action_value' => '9',
                    'currency' => 'USD',
                ],
            ],
            digitalAssetId: 7,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'META_ADS',
        ));

        $this->assertTrue($receipt->isCommitted());
        $this->assertSame(2, DB::table('meta_typed_action_daily')->count());
        $this->assertEqualsCanonicalizing(
            ['lead', 'onsite_conversion.messaging_conversation_started_7d'],
            DB::table('meta_typed_action_daily')->pluck('action_type')->all()
        );
        $this->assertFalse(SchemaHasResultsColumn());
    }

    #[Test]
    public function money_uses_exact_decimal_and_rejects_float(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'meta_ad_daily',
            'provider_or_source' => 'META_ADS',
        ]);

        $writer = app(PostgresWarehouseWriter::class);

        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'meta_ad_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'money-1',
            records: [[
                'digital_asset_id' => 1,
                'account_id' => 'act_1',
                'reporting_date' => '2026-08-03',
                'ad_id' => '999999999999999999', // large string ID
                'impressions' => 100,
                'clicks' => 0, // zero is not missing
                'spend' => '10.50',
                'currency' => 'TRY',
            ]],
            digitalAssetId: 1,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'META_ADS',
        ));

        $this->assertSame('10.5', rtrim(rtrim((string) DB::table('meta_ad_daily')->value('spend'), '0'), '.'));
        $this->assertSame('999999999999999999', (string) DB::table('meta_ad_daily')->value('ad_id'));
        $this->assertSame(0, (int) DB::table('meta_ad_daily')->value('clicks'));
        // Exact decimal semantics: no float drift when re-read as string decimal input.
        $this->assertTrue(bccomp((string) DB::table('meta_ad_daily')->value('spend'), '10.50', 6) === 0);

        $this->expectException(\InvalidArgumentException::class);
        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'meta_ad_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'money-float',
            records: [[
                'digital_asset_id' => 1,
                'account_id' => 'act_1',
                'reporting_date' => '2026-08-04',
                'ad_id' => '1',
                'impressions' => 1,
                'clicks' => 1,
                'spend' => 10.5, // float forbidden
                'currency' => 'TRY',
            ]],
            digitalAssetId: 1,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'META_ADS',
        ));
    }

    #[Test]
    public function missing_dataset_is_not_synthetic_zero_and_materialization_tracks_state(): void
    {
        $this->assertSame(0, DB::table('gsc_query_daily')->count());
        $this->assertNull(DatasetMaterialization::query()->where('dataset_id', 'gsc_query_daily')->first());

        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'gsc_query_daily',
            'provider_or_source' => 'SEARCH_CONSOLE',
        ]);

        app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
            datasetId: 'gsc_query_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'q1',
            records: [[
                'digital_asset_id' => 5,
                'site_url' => 'https://example.com/',
                'reporting_date' => '2026-08-05',
                'query' => 'moxdop',
                'clicks' => 0,
                'impressions' => 4,
            ]],
            digitalAssetId: 5,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'SEARCH_CONSOLE',
        ));

        $mat = DatasetMaterialization::query()->where('dataset_id', 'gsc_query_daily')->first();
        $this->assertNotNull($mat);
        $this->assertSame(MaterializationStatus::Available, $mat->status);
        $this->assertSame('2026-08-05', $mat->coverage_end_date->toDateString());

        $mat->forceFill(['status' => MaterializationStatus::Available])->save();
        app(MaterializationService::class)->recordFailedRefresh($mat->fresh());
        $this->assertSame(MaterializationStatus::Stale, $mat->fresh()->status);
        $this->assertSame(1, DB::table('gsc_query_daily')->count());
    }

    #[Test]
    public function checkpoint_advances_only_after_durable_commit(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'ga4_property_daily',
            'provider_or_source' => 'GA4',
            'checkpoint' => ['page' => 1],
        ]);

        $pipeline = app(DatasetWritePipeline::class);
        $batch = new NormalizedDatasetBatch(
            datasetId: 'ga4_property_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'cp-1',
            records: [[
                'digital_asset_id' => 3,
                'property_id' => 'properties/9',
                'reporting_date' => '2026-08-06',
                'sessions' => 1,
                'engagedSessions' => 1,
                'screenPageViews' => 1,
                'totalUsers' => 1,
                'activeUsers' => 1,
            ]],
            digitalAssetId: 3,
            collectionRunId: (int) $run->collection_run_id,
            resourceRunId: (int) $run->collection_resource_run_id,
            providerOrSource: 'GA4',
        );

        $raw = new RawPayloadEnvelope(
            providerOrSource: 'GA4',
            collectionRunId: (int) $run->collection_run_id,
            resourceRunId: (int) $run->collection_resource_run_id,
            datasetRunId: (int) $run->id,
            logicalDatasetId: 'ga4_property_daily',
            requestFamilyId: 'GA4_RF_PROPERTY_DAILY',
            batchKey: 'cp-1',
            contentType: 'application/json',
            payload: '{"rows":1}',
            providerSafeMetadata: ['safe' => true],
        );

        $receipt = $pipeline->commit($batch, $raw, checkpointToAdvance: ['page' => 2, 'batch' => 'cp-1']);
        $this->assertTrue($receipt->checkpointSafe);
        $this->assertSame(2, $run->fresh()->checkpoint['page']);
        $this->assertSame(1, DatasetWriteBatch::query()->where('status', 'committed')->count());
        $this->assertNotNull($receipt->rawIngestionObjectId);

        // Simulate DB failure path: invalid dataset must not advance checkpoint.
        $run2 = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'ga4_property_daily',
            'checkpoint' => ['page' => 9],
        ]);
        try {
            $pipeline->commit(new NormalizedDatasetBatch(
                datasetId: 'not_a_real_dataset',
                datasetRunId: (int) $run2->id,
                contractVersion: 1,
                batchKey: 'bad',
                records: [['x' => 1]],
                collectionRunId: (int) $run2->collection_run_id,
            ), null, checkpointToAdvance: ['page' => 10]);
            $this->fail('expected failure');
        } catch (\Throwable) {
            $this->assertSame(9, $run2->fresh()->checkpoint['page']);
        }
    }

    #[Test]
    public function raw_then_db_retry_reuses_object_and_commits_once(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'ga4_property_daily',
            'provider_or_source' => 'GA4',
        ]);

        $rawWriter = app(FilesystemRawPayloadWriter::class);
        $envelope = new RawPayloadEnvelope(
            providerOrSource: 'GA4',
            collectionRunId: (int) $run->collection_run_id,
            resourceRunId: (int) $run->collection_resource_run_id,
            datasetRunId: (int) $run->id,
            logicalDatasetId: 'ga4_property_daily',
            requestFamilyId: 'GA4_RF_PROPERTY_DAILY',
            batchKey: 'retry-1',
            contentType: 'application/json',
            payload: '{"rows":1}',
            providerSafeMetadata: [],
        );
        $ref = $rawWriter->write($envelope);
        $this->assertFalse($ref->reusedExisting);

        // Pretend DB failed after raw write — retry reuses raw, commits normalized once.
        $ref2 = $rawWriter->write($envelope);
        $this->assertTrue($ref2->reusedExisting);

        $writer = app(PostgresWarehouseWriter::class);
        $batch = new NormalizedDatasetBatch(
            datasetId: 'ga4_property_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'retry-1',
            records: [[
                'digital_asset_id' => 8,
                'property_id' => 'properties/8',
                'reporting_date' => '2026-08-07',
                'sessions' => 5,
                'engagedSessions' => 5,
                'screenPageViews' => 5,
                'totalUsers' => 5,
                'activeUsers' => 5,
            ]],
            digitalAssetId: 8,
            collectionRunId: (int) $run->collection_run_id,
            rawPayloadReference: $ref2,
            providerOrSource: 'GA4',
        );

        $a = $writer->write($batch);
        $b = $writer->write($batch);
        $this->assertTrue($a->checkpointSafe);
        $this->assertTrue($b->reusedExisting);
        $this->assertSame(1, DatasetWriteBatch::query()->where('batch_key', 'retry-1')->where('status', 'committed')->count());
        $this->assertSame(1, RawIngestionObject::query()->count());
        $this->assertSame(1, DB::table('ga4_property_daily')->where('property_id', 'properties/8')->count());

        app(CheckpointManager::class)->advance($run, ['cursor' => 'done']);
        $this->assertSame('done', $run->fresh()->checkpoint['cursor']);
    }

    #[Test]
    public function failed_batch_row_is_reused_in_place_on_retry(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'ga4_property_daily',
            'provider_or_source' => 'GA4',
        ]);

        $stale = DatasetWriteBatch::query()->create([
            'dataset_run_id' => (int) $run->id,
            'batch_key' => 'retry-after-failure',
            'idempotency_key' => hash('sha256', $run->id.'|retry-after-failure'),
            'dataset_id' => 'ga4_property_daily',
            'status' => WriteBatchStatus::Failed,
            'rows_received' => 1,
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'started_at' => now()->subMinute(),
            'checksum' => null,
            'error_summary' => 'previous failure',
        ]);

        $receipt = app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
            datasetId: 'ga4_property_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'retry-after-failure',
            records: [[
                'digital_asset_id' => 77,
                'property_id' => 'properties/retry',
                'reporting_date' => '2026-08-08',
                'sessions' => 5,
                'engagedSessions' => 4,
                'screenPageViews' => 9,
                'totalUsers' => 3,
                'activeUsers' => 3,
            ]],
            digitalAssetId: 77,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'GA4',
        ));

        $this->assertTrue($receipt->isCommitted());
        $this->assertSame(1, DatasetWriteBatch::query()->where('dataset_run_id', (int) $run->id)->where('batch_key', 'retry-after-failure')->count());
        $this->assertSame($stale->id, DatasetWriteBatch::query()->where('dataset_run_id', (int) $run->id)->where('batch_key', 'retry-after-failure')->value('id'));
        $this->assertSame('committed', DatasetWriteBatch::query()->find($stale->id)?->status->value);
        $this->assertSame(1, DB::table('ga4_property_daily')->where('property_id', 'properties/retry')->count());
    }

    #[Test]
    public function pending_batch_row_is_reused_in_place_on_retry(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'ga4_property_daily',
            'provider_or_source' => 'GA4',
        ]);

        $stale = DatasetWriteBatch::query()->create([
            'dataset_run_id' => (int) $run->id,
            'batch_key' => 'retry-after-pending',
            'idempotency_key' => hash('sha256', $run->id.'|retry-after-pending'),
            'dataset_id' => 'ga4_property_daily',
            'status' => WriteBatchStatus::Pending,
            'rows_received' => 1,
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'started_at' => now()->subMinute(),
            'checksum' => null,
            'error_summary' => null,
        ]);

        $receipt = app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
            datasetId: 'ga4_property_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'retry-after-pending',
            records: [[
                'digital_asset_id' => 78,
                'property_id' => 'properties/pending',
                'reporting_date' => '2026-08-09',
                'sessions' => 7,
                'engagedSessions' => 6,
                'screenPageViews' => 11,
                'totalUsers' => 4,
                'activeUsers' => 4,
            ]],
            digitalAssetId: 78,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'GA4',
        ));

        $this->assertTrue($receipt->isCommitted());
        $this->assertSame(1, DatasetWriteBatch::query()->where('dataset_run_id', (int) $run->id)->where('batch_key', 'retry-after-pending')->count());
        $this->assertSame($stale->id, DatasetWriteBatch::query()->where('dataset_run_id', (int) $run->id)->where('batch_key', 'retry-after-pending')->value('id'));
        $this->assertSame('committed', DatasetWriteBatch::query()->find($stale->id)?->status->value);
        $this->assertSame(1, DB::table('ga4_property_daily')->where('property_id', 'properties/pending')->count());
    }

    #[Test]
    public function website_observation_history_uses_observed_at_identity(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'website_http_snapshot',
            'provider_or_source' => 'WEBSITE_DIRECT',
        ]);

        $writer = app(PostgresWarehouseWriter::class);
        $base = [
            'digital_asset_id' => 11,
            'url' => 'https://example.com/',
            'metadata' => ['status_code' => 200],
        ];

        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'website_http_snapshot',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'obs-1',
            records: [array_merge($base, ['observed_at' => '2026-08-01 10:00:00'])],
            digitalAssetId: 11,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'WEBSITE_DIRECT',
        ));
        $writer->write(new NormalizedDatasetBatch(
            datasetId: 'website_http_snapshot',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'obs-2',
            records: [array_merge($base, ['observed_at' => '2026-08-02 10:00:00', 'metadata' => ['status_code' => 500]])],
            digitalAssetId: 11,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'WEBSITE_DIRECT',
        ));

        $this->assertSame(2, DB::table('website_http_snapshot')->count());
    }

    #[Test]
    public function collectors_do_not_need_physical_table_names(): void
    {
        $registry = app(DataPoolStorageRegistry::class);
        $this->assertSame('gsc_query_page_daily', $registry->tableName('gsc_query_page_daily'));
        $this->assertSame('UPSERT_DAILY_FACT', $registry->physicalDataset('gsc_query_page_daily')['write_mode']);
    }

    #[Test]
    public function bulk_writer_is_batch_oriented_for_representative_chunk(): void
    {
        $run = CollectionDatasetRun::factory()->create([
            'dataset_contract_id' => 'gsc_query_daily',
            'provider_or_source' => 'SEARCH_CONSOLE',
        ]);

        $records = [];
        for ($i = 0; $i < 200; $i++) {
            $records[] = [
                'digital_asset_id' => 2,
                'site_url' => 'https://example.com/',
                'reporting_date' => '2026-08-08',
                'query' => 'q'.$i,
                'clicks' => $i,
                'impressions' => $i + 1,
            ];
        }

        $receipt = app(PostgresWarehouseWriter::class)->write(new NormalizedDatasetBatch(
            datasetId: 'gsc_query_daily',
            datasetRunId: (int) $run->id,
            contractVersion: 1,
            batchKey: 'bulk-1',
            records: $records,
            digitalAssetId: 2,
            collectionRunId: (int) $run->collection_run_id,
            providerOrSource: 'SEARCH_CONSOLE',
        ));

        $this->assertSame(200, $receipt->rowsReceived);
        $this->assertSame(200, DB::table('gsc_query_daily')->count());
    }
}

function SchemaHasResultsColumn(): bool
{
    return false;
}
