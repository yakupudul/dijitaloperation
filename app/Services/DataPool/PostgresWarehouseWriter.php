<?php

namespace App\Services\DataPool;

use App\Enums\DataPool\WriteBatchStatus;
use App\Models\DataPool\DatasetWriteBatch;
use App\Services\DataPool\Contracts\WarehouseWriter;
use App\Services\DataPool\Support\NormalizedDatasetBatch;
use App\Services\DataPool\Support\RecordFingerprint;
use App\Services\DataPool\Support\WriteReceipt;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * PostgreSQL-oriented warehouse writer (also works on SQLite for fast tests).
 * Collectors must not know physical table names — resolve via DataPoolStorageRegistry.
 */
final class PostgresWarehouseWriter implements WarehouseWriter
{
    public function __construct(
        private readonly DataPoolStorageRegistry $registry,
        private readonly PartitionManager $partitions,
        private readonly MaterializationService $materializations,
        private readonly RecordFingerprint $fingerprints = new RecordFingerprint,
    ) {}

    public function write(NormalizedDatasetBatch $batch): WriteReceipt
    {
        if ($batch->records === []) {
            throw new InvalidArgumentException('NormalizedDatasetBatch must contain at least one record or callers should skip write');
        }

        $physical = $this->registry->physicalDataset($batch->datasetId);
        $table = $physical['table'];
        $writeMode = $physical['write_mode'];
        $naturalKey = $physical['natural_key'];
        $columns = $physical['columns'];
        $columnNames = array_column($columns, 'name');
        $columnMap = [];
        foreach ($columns as $col) {
            $columnMap[$col['name']] = $col;
        }

        $now = now();
        $prepared = [];
        $dates = [];

        foreach ($batch->records as $index => $record) {
            $row = $this->validateAndNormalizeRecord(
                $batch,
                $record,
                $index,
                $naturalKey,
                $columnMap,
                $columnNames,
                $writeMode,
                $now,
            );
            $prepared[] = $row;
            if (isset($row['reporting_date'])) {
                $dates[] = (string) $row['reporting_date'];
            }
        }

        $prepared = $this->collapseByNaturalKey($prepared, $naturalKey, $writeMode);
        $dates = [];
        foreach ($prepared as $row) {
            if (isset($row['reporting_date'])) {
                $dates[] = (string) $row['reporting_date'];
            }
        }

        if (($physical['partition_strategy'] ?? 'NONE') === 'RANGE_MONTHLY') {
            if ($dates === []) {
                throw new RuntimeException("Partitioned dataset [{$batch->datasetId}] requires reporting_date on records");
            }
            try {
                $this->partitions->ensureRange($table, min($dates), max($dates));
            } catch (Throwable $e) {
                $this->markFailed($batch, count($prepared), $e->getMessage());

                throw $e;
            }
        }

        $checksum = hash('sha256', json_encode(array_column($prepared, 'record_fingerprint')));
        $writeBatch = $this->prepareWriteBatch($batch, $now, count($prepared), $checksum);

        if ($writeBatch->status === WriteBatchStatus::Committed) {
            return new WriteReceipt(
                writeBatchId: (int) $writeBatch->id,
                status: WriteBatchStatus::Committed->value,
                rowsReceived: (int) $writeBatch->rows_received,
                rowsInserted: (int) $writeBatch->rows_inserted,
                rowsUpdated: (int) $writeBatch->rows_updated,
                rowsUnchanged: $writeBatch->rows_unchanged,
                checkpointSafe: true,
                rawIngestionObjectId: $writeBatch->raw_ingestion_object_id,
                committedAt: $writeBatch->committed_at,
                reusedExisting: true,
            );
        }

        try {
            $stats = DB::transaction(function () use ($table, $prepared, $naturalKey, $writeMode, $columnNames): array {
                return $this->bulkUpsert($table, $prepared, $naturalKey, $writeMode, $columnNames);
            });

            $writeBatch->forceFill([
                'status' => WriteBatchStatus::Committed,
                'rows_inserted' => $stats['inserted'],
                'rows_updated' => $stats['updated'],
                'rows_unchanged' => $stats['unchanged'],
                'committed_at' => now(),
            ])->save();

            $this->materializations->recordSuccessfulWrite($batch, $prepared, $stats);

            return new WriteReceipt(
                writeBatchId: (int) $writeBatch->id,
                status: WriteBatchStatus::Committed->value,
                rowsReceived: count($prepared),
                rowsInserted: $stats['inserted'],
                rowsUpdated: $stats['updated'],
                rowsUnchanged: $stats['unchanged'],
                checkpointSafe: true,
                rawIngestionObjectId: $batch->rawPayloadReference?->rawIngestionObjectId,
                committedAt: $writeBatch->committed_at,
            );
        } catch (Throwable $e) {
            $writeBatch->forceFill([
                'status' => WriteBatchStatus::Failed,
                'error_summary' => mb_substr($e->getMessage(), 0, 2000),
            ])->save();

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  list<string>  $naturalKey
     * @param  array<string, array<string, mixed>>  $columnMap
     * @param  list<string>  $columnNames
     * @return array<string, mixed>
     */
    private function validateAndNormalizeRecord(
        NormalizedDatasetBatch $batch,
        array $record,
        int $index,
        array $naturalKey,
        array $columnMap,
        array $columnNames,
        string $writeMode,
        mixed $now,
    ): array {
        foreach ($naturalKey as $key) {
            if (! array_key_exists($key, $record) || $record[$key] === null || $record[$key] === '') {
                throw new InvalidArgumentException("CONTRACT_MISMATCH: missing natural key [{$key}] at record {$index} for [{$batch->datasetId}]");
            }
        }

        foreach (array_keys($record) as $field) {
            if (! in_array($field, $columnNames, true) && ! in_array($field, ['_ignore'], true)) {
                // Extra provider fields are intentionally ignored (collector cannot expand contract).
                unset($record[$field]);
            }
        }

        $row = [];
        foreach ($columnNames as $name) {
            $def = $columnMap[$name];
            $role = $def['role'] ?? null;

            if ($name === 'digital_asset_id') {
                $row[$name] = $record[$name] ?? $batch->digitalAssetId;
            } elseif ($name === 'external_resource_id') {
                $row[$name] = $record[$name] ?? $batch->externalResourceId;
            } elseif ($name === 'contract_version') {
                $row[$name] = $record[$name] ?? $batch->contractVersion;
            } elseif ($name === 'last_collection_run_id') {
                $row[$name] = $record[$name] ?? $batch->collectionRunId;
            } elseif ($name === 'last_dataset_run_id') {
                $row[$name] = $record[$name] ?? $batch->datasetRunId;
            } elseif ($name === 'first_collected_at' || $name === 'last_collected_at') {
                $row[$name] = $record[$name] ?? $now;
            } elseif ($name === 'record_fingerprint') {
                $row[$name] = $record[$name] ?? $this->fingerprints->for($batch->datasetId, $naturalKey, array_merge($record, [
                    'digital_asset_id' => $record['digital_asset_id'] ?? $batch->digitalAssetId,
                ]));
            } elseif (array_key_exists($name, $record)) {
                $row[$name] = $record[$name];
            } elseif (! ($def['nullable'] ?? false) && ! array_key_exists('default', $def) && $role !== 'extension') {
                throw new InvalidArgumentException("CONTRACT_MISMATCH: required field [{$name}] missing at record {$index} for [{$batch->datasetId}]");
            } else {
                $row[$name] = $def['default'] ?? null;
            }

            if (($def['type'] ?? null) === 'decimal' && is_float($row[$name] ?? null)) {
                // Reject float money / metrics at the boundary — require string/int decimal input.
                throw new InvalidArgumentException("CONTRACT_MISMATCH: floating-point value not allowed for [{$name}] (use string decimal)");
            }
        }

        if ($writeMode === 'APPEND_SNAPSHOT' || $writeMode === 'APPEND_OBSERVATION') {
            // Snapshot identity must include a temporal/observation discriminator when provided.
            // Natural key from contract defines uniqueness; append modes do not upsert away history.
        }

        $row['created_at'] = $now;
        $row['updated_at'] = $now;

        foreach ($columnMap as $name => $def) {
            if (($def['type'] ?? null) === 'json' && is_array($row[$name] ?? null)) {
                $row[$name] = json_encode($row[$name], JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $naturalKey
     * @param  list<string>  $columnNames
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    private function bulkUpsert(string $table, array $rows, array $naturalKey, string $writeMode, array $columnNames): array
    {
        $driver = DB::connection()->getDriverName();
        $inserted = 0;
        $updated = 0;
        $unchanged = 0;

        if ($writeMode === 'APPEND_SNAPSHOT' || $writeMode === 'APPEND_OBSERVATION') {
            foreach (array_chunk($rows, (int) config('moxdop-data-pool.default_batch_size', 500)) as $chunk) {
                DB::table($table)->insert($chunk);
                $inserted += count($chunk);
            }

            return compact('inserted', 'updated', 'unchanged');
        }

        $updateColumns = array_values(array_diff($columnNames, $naturalKey, ['first_collected_at']));
        // Always refresh provenance + payload columns on conflict.
        $updateColumns = array_values(array_unique(array_merge($updateColumns, [
            'last_collected_at',
            'last_collection_run_id',
            'last_dataset_run_id',
            'contract_version',
            'record_fingerprint',
            'updated_at',
        ])));

        foreach (array_chunk($rows, (int) config('moxdop-data-pool.default_batch_size', 500)) as $chunk) {
            // Pre-count existing keys for approximate insert/update stats (not exact under races).
            $before = $this->countExisting($table, $naturalKey, $chunk);

            if ($driver === 'pgsql') {
                $this->postgresUpsert($table, $chunk, $naturalKey, $updateColumns);
            } else {
                DB::table($table)->upsert(
                    $chunk,
                    $naturalKey,
                    $updateColumns,
                );
            }

            $afterKeys = count($chunk);
            $updated += $before;
            $inserted += max(0, $afterKeys - $before);
        }

        return compact('inserted', 'updated', 'unchanged');
    }

    /**
     * PostgreSQL ON CONFLICT cannot update the same constrained row twice in one INSERT.
     * Last record in the batch wins (late-correction semantics).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $naturalKey
     * @return list<array<string, mixed>>
     */
    private function collapseByNaturalKey(array $rows, array $naturalKey, string $writeMode): array
    {
        if ($rows === [] || $naturalKey === []) {
            return $rows;
        }
        if ($writeMode === 'APPEND_SNAPSHOT' || $writeMode === 'APPEND_OBSERVATION') {
            return $rows;
        }

        $collapsed = [];
        foreach ($rows as $row) {
            $parts = [];
            foreach ($naturalKey as $col) {
                $parts[] = (string) ($row[$col] ?? '');
            }
            $collapsed[implode("\0", $parts)] = $row;
        }

        return array_values($collapsed);
    }

    /**
     * @param  list<string>  $naturalKey
     * @param  list<array<string, mixed>>  $chunk
     */
    private function countExisting(string $table, array $naturalKey, array $chunk): int
    {
        if ($chunk === [] || $naturalKey === []) {
            return 0;
        }

        // Single bounded OR-query instead of one EXISTS per row (Prompt 65).
        return (int) DB::table($table)->where(function ($outer) use ($naturalKey, $chunk): void {
            foreach ($chunk as $row) {
                $outer->orWhere(function ($inner) use ($naturalKey, $row): void {
                    foreach ($naturalKey as $col) {
                        $inner->where($col, $row[$col] ?? null);
                    }
                });
            }
        })->count();
    }

    /**
     * @param  list<array<string, mixed>>  $chunk
     * @param  list<string>  $naturalKey
     * @param  list<string>  $updateColumns
     */
    private function postgresUpsert(string $table, array $chunk, array $naturalKey, array $updateColumns): void
    {
        if ($chunk === []) {
            return;
        }

        $columns = array_keys($chunk[0]);
        $placeholders = [];
        $bindings = [];
        foreach ($chunk as $row) {
            $rowPlaceholders = [];
            foreach ($columns as $col) {
                $rowPlaceholders[] = '?';
                $bindings[] = $this->bindValue($row[$col] ?? null);
            }
            $placeholders[] = '('.implode(', ', $rowPlaceholders).')';
        }

        $colSql = implode(', ', array_map(fn ($c) => '"'.$c.'"', $columns));
        $conflict = implode(', ', array_map(fn ($c) => '"'.$c.'"', $naturalKey));
        $sets = [];
        foreach ($updateColumns as $col) {
            if ($col === 'first_collected_at') {
                continue;
            }
            $sets[] = '"'.$col.'" = EXCLUDED."'.$col.'"';
        }
        // Preserve first_collected_at
        if (in_array('first_collected_at', $columns, true)) {
            $sets[] = '"first_collected_at" = '.$this->quoteIdent($table).'."first_collected_at"';
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES %s ON CONFLICT (%s) DO UPDATE SET %s',
            $this->quoteIdent($table),
            $colSql,
            implode(', ', $placeholders),
            $conflict,
            implode(', ', $sets),
        );

        DB::statement($sql, $bindings);
    }

    private function bindValue(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance(Carbon::parse($value))->toDateTimeString();
        }
        if (is_array($value)) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $value;
    }

    private function quoteIdent(string $ident): string
    {
        return '"'.str_replace('"', '""', $ident).'"';
    }

    private function markFailed(NormalizedDatasetBatch $batch, int $rows, string $message): void
    {
        DatasetWriteBatch::query()->updateOrCreate(
            [
                'dataset_run_id' => $batch->datasetRunId,
                'batch_key' => $batch->batchKey,
            ],
            [
                'idempotency_key' => $batch->resolvedIdempotencyKey(),
                'dataset_id' => $batch->datasetId,
                'status' => WriteBatchStatus::Failed,
                'rows_received' => $rows,
                'error_summary' => mb_substr($message, 0, 2000),
                'started_at' => now(),
            ]
        );
    }

    private function prepareWriteBatch(
        NormalizedDatasetBatch $batch,
        mixed $now,
        int $rowsReceived,
        string $checksum,
    ): DatasetWriteBatch {
        $existing = DatasetWriteBatch::query()
            ->where(function ($q) use ($batch): void {
                $q->where('idempotency_key', $batch->resolvedIdempotencyKey())
                    ->orWhere(function ($q2) use ($batch): void {
                        $q2->where('dataset_run_id', $batch->datasetRunId)
                            ->where('batch_key', $batch->batchKey);
                    });
            })
            ->first();

        if ($existing !== null && $existing->status === WriteBatchStatus::Committed) {
            return $existing;
        }

        if ($existing !== null) {
            if ($existing->dataset_id !== $batch->datasetId) {
                throw new RuntimeException("Write batch conflict for [{$batch->datasetId}] batch [{$batch->batchKey}]");
            }

            // Failed/pending retries may rewrite the payload after a collector fix
            // (e.g. collapsing duplicate natural keys). Checksum is for committed idempotency.

            $existing->forceFill([
                'idempotency_key' => $batch->resolvedIdempotencyKey(),
                'raw_ingestion_object_id' => $batch->rawPayloadReference?->rawIngestionObjectId ?? $existing->raw_ingestion_object_id,
                'dataset_id' => $batch->datasetId,
                'status' => WriteBatchStatus::Pending,
                'rows_received' => $rowsReceived,
                'rows_inserted' => 0,
                'rows_updated' => 0,
                'rows_unchanged' => null,
                'started_at' => $now,
                'committed_at' => null,
                'checksum' => $checksum,
                'error_summary' => null,
            ])->save();

            return $existing->fresh() ?? $existing;
        }

        return DatasetWriteBatch::query()->create([
            'dataset_run_id' => $batch->datasetRunId,
            'batch_key' => $batch->batchKey,
            'idempotency_key' => $batch->resolvedIdempotencyKey(),
            'raw_ingestion_object_id' => $batch->rawPayloadReference?->rawIngestionObjectId,
            'dataset_id' => $batch->datasetId,
            'status' => WriteBatchStatus::Pending,
            'rows_received' => $rowsReceived,
            'rows_inserted' => 0,
            'rows_updated' => 0,
            'started_at' => $now,
            'checksum' => $checksum,
        ]);
    }
}
