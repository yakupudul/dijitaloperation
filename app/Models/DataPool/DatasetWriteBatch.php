<?php

namespace App\Models\DataPool;

use App\Enums\DataPool\WriteBatchStatus;
use App\Models\Collection\CollectionDatasetRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DatasetWriteBatch extends Model
{
    protected $table = 'dataset_write_batches';

    protected $fillable = [
        'dataset_run_id',
        'batch_key',
        'idempotency_key',
        'raw_ingestion_object_id',
        'dataset_id',
        'status',
        'rows_received',
        'rows_inserted',
        'rows_updated',
        'rows_unchanged',
        'started_at',
        'committed_at',
        'checksum',
        'error_summary',
    ];

    protected function casts(): array
    {
        return [
            'status' => WriteBatchStatus::class,
            'started_at' => 'datetime',
            'committed_at' => 'datetime',
            'rows_received' => 'integer',
            'rows_inserted' => 'integer',
            'rows_updated' => 'integer',
            'rows_unchanged' => 'integer',
        ];
    }

    public function datasetRun(): BelongsTo
    {
        return $this->belongsTo(CollectionDatasetRun::class, 'dataset_run_id');
    }

    public function rawIngestionObject(): BelongsTo
    {
        return $this->belongsTo(RawIngestionObject::class, 'raw_ingestion_object_id');
    }
}
