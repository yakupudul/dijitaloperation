<?php

namespace App\Models\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\ProgressMode;
use App\Enums\Collection\RequirementLevel;
use Database\Factories\Collection\CollectionDatasetRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CollectionDatasetRun extends Model
{
    /** @use HasFactory<CollectionDatasetRunFactory> */
    use HasFactory;

    protected $table = 'collection_dataset_runs';

    protected $fillable = [
        'uuid',
        'collection_run_id',
        'collection_resource_run_id',
        'provider_or_source',
        'dataset_contract_id',
        'request_family_id',
        'requirement_level',
        'contract_registry_version',
        'status',
        'attempt_count',
        'max_attempts',
        'started_at',
        'finished_at',
        'last_activity_at',
        'retry_at',
        'progress_mode',
        'progress_current',
        'progress_total',
        'rows_received',
        'rows_written',
        'chunks_completed',
        'chunks_failed',
        'pages_completed',
        'stage',
        'checkpoint',
        'error_category',
        'error_code',
        'error_message',
        'depends_on_dataset_run_ids',
        'dispatch_lock_token',
        'dispatch_locked_at',
        'metadata',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if ($run->uuid === null || $run->uuid === '') {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    protected static function newFactory(): CollectionDatasetRunFactory
    {
        return CollectionDatasetRunFactory::new();
    }

    /**
     * @return BelongsTo<CollectionRun, $this>
     */
    public function collectionRun(): BelongsTo
    {
        return $this->belongsTo(CollectionRun::class);
    }

    /**
     * @return BelongsTo<CollectionResourceRun, $this>
     */
    public function resourceRun(): BelongsTo
    {
        return $this->belongsTo(CollectionResourceRun::class, 'collection_resource_run_id');
    }

    /**
     * @return HasMany<CollectionDatasetAttempt, $this>
     */
    public function attempts(): HasMany
    {
        return $this->hasMany(CollectionDatasetAttempt::class);
    }

    public function percentage(): ?float
    {
        if ($this->progress_mode !== ProgressMode::Counted) {
            return null;
        }

        if ($this->progress_total === null || $this->progress_total <= 0) {
            return null;
        }

        $current = min((int) ($this->progress_current ?? 0), (int) $this->progress_total);

        return round(($current / (int) $this->progress_total) * 100, 2);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CollectionRunStatus::class,
            'requirement_level' => RequirementLevel::class,
            'progress_mode' => ProgressMode::class,
            'error_category' => CollectionErrorCategory::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'retry_at' => 'datetime',
            'dispatch_locked_at' => 'datetime',
            'checkpoint' => 'array',
            'depends_on_dataset_run_ids' => 'array',
            'metadata' => 'array',
        ];
    }
}
