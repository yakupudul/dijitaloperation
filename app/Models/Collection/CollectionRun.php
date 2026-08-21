<?php

namespace App\Models\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use Database\Factories\Collection\CollectionRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CollectionRun extends Model
{
    /** @use HasFactory<CollectionRunFactory> */
    use HasFactory;

    protected $table = 'collection_runs';

    protected $fillable = [
        'uuid',
        'requested_by_user_id',
        'customer_id',
        'brand_id',
        'digital_asset_id',
        'trigger_type',
        'status',
        'contract_registry_id',
        'contract_registry_version',
        'contract_registry_checksum',
        'formula_registry_version',
        'idempotency_key',
        'started_at',
        'finished_at',
        'cancel_requested_at',
        'cancelled_at',
        'last_activity_at',
        'resources_total',
        'resources_completed',
        'datasets_total',
        'datasets_completed',
        'datasets_failed',
        'failure_summary',
        'request_context',
        'plan_snapshot',
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

    protected static function newFactory(): CollectionRunFactory
    {
        return CollectionRunFactory::new();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return HasMany<CollectionResourceRun, $this>
     */
    public function resourceRuns(): HasMany
    {
        return $this->hasMany(CollectionResourceRun::class);
    }

    /**
     * @return HasMany<CollectionDatasetRun, $this>
     */
    public function datasetRuns(): HasMany
    {
        return $this->hasMany(CollectionDatasetRun::class);
    }

    public function cancellationRequested(): bool
    {
        return $this->status === CollectionRunStatus::CancellationRequested
            || $this->cancel_requested_at !== null;
    }

    /**
     * Planner-authorized sibling-asset collection (Google/Meta initial backfill).
     */
    public function allowsMultiAssetBindings(): bool
    {
        $context = $this->request_context ?? [];

        return (bool) data_get($context, 'context.allow_multi_asset_bindings', false);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger_type' => CollectionTriggerType::class,
            'status' => CollectionRunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'request_context' => 'array',
            'plan_snapshot' => 'array',
            'metadata' => 'array',
        ];
    }
}
