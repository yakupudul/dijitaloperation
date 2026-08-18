<?php

namespace App\Models\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectionDatasetAttempt extends Model
{
    protected $table = 'collection_dataset_attempts';

    protected $fillable = [
        'collection_dataset_run_id',
        'attempt_number',
        'status',
        'started_at',
        'finished_at',
        'error_category',
        'error_code',
        'error_message',
        'retry_scheduled_at',
        'job_uuid',
        'metadata',
    ];

    /**
     * @return BelongsTo<CollectionDatasetRun, $this>
     */
    public function datasetRun(): BelongsTo
    {
        return $this->belongsTo(CollectionDatasetRun::class, 'collection_dataset_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CollectionRunStatus::class,
            'error_category' => CollectionErrorCategory::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'retry_scheduled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
