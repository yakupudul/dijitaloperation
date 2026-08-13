<?php

namespace App\Models\DataPool;

use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawIngestionObject extends Model
{
    protected $table = 'raw_ingestion_objects';

    protected $fillable = [
        'uuid',
        'collection_run_id',
        'resource_run_id',
        'dataset_run_id',
        'dataset_id',
        'request_family_id',
        'batch_key',
        'provider_or_source',
        'storage_disk',
        'object_key',
        'content_type',
        'compression',
        'byte_size',
        'sha256',
        'record_count',
        'provider_request_fingerprint',
        'captured_at',
        'retention_class',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'metadata' => 'array',
            'byte_size' => 'integer',
            'record_count' => 'integer',
        ];
    }

    public function collectionRun(): BelongsTo
    {
        return $this->belongsTo(CollectionRun::class, 'collection_run_id');
    }

    public function resourceRun(): BelongsTo
    {
        return $this->belongsTo(CollectionResourceRun::class, 'resource_run_id');
    }

    public function datasetRun(): BelongsTo
    {
        return $this->belongsTo(CollectionDatasetRun::class, 'dataset_run_id');
    }
}
