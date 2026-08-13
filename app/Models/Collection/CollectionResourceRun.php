<?php

namespace App\Models\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use Database\Factories\Collection\CollectionResourceRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CollectionResourceRun extends Model
{
    /** @use HasFactory<CollectionResourceRunFactory> */
    use HasFactory;

    protected $table = 'collection_resource_runs';

    protected $fillable = [
        'uuid',
        'collection_run_id',
        'provider_or_source',
        'resource_kind',
        'external_resource_id',
        'digital_asset_id',
        'core_asset_binding_id',
        'status',
        'started_at',
        'finished_at',
        'last_activity_at',
        'datasets_total',
        'datasets_completed',
        'datasets_failed',
        'error_summary',
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

    protected static function newFactory(): CollectionResourceRunFactory
    {
        return CollectionResourceRunFactory::new();
    }

    /**
     * @return BelongsTo<CollectionRun, $this>
     */
    public function collectionRun(): BelongsTo
    {
        return $this->belongsTo(CollectionRun::class);
    }

    /**
     * @return BelongsTo<CoreExternalResource, $this>
     */
    public function externalResource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class);
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<CoreAssetBinding, $this>
     */
    public function coreAssetBinding(): BelongsTo
    {
        return $this->belongsTo(CoreAssetBinding::class);
    }

    /**
     * @return HasMany<CollectionDatasetRun, $this>
     */
    public function datasetRuns(): HasMany
    {
        return $this->hasMany(CollectionDatasetRun::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CollectionRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'metadata' => 'array',
        ];
    }
}
