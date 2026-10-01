<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResourceAutomation extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'collection_enabled' => 'boolean', 'query_enabled' => 'boolean',
            'service_ids' => 'array', 'interval_days' => 'integer', 'preferred_hour' => 'integer', 'revision' => 'integer',
            'next_collection_at' => 'immutable_datetime', 'collection_queued_at' => 'immutable_datetime',
            'last_collection_success_at' => 'immutable_datetime', 'last_query_success_at' => 'immutable_datetime',
            'query_checked_at' => 'immutable_datetime',
        ];
    }

    public function gbpRun(): BelongsTo
    {
        return $this->belongsTo(Run::class, 'gbp_run_id');
    }

    public function resource(): BelongsTo
    {
        return $this->belongsTo(CoreExternalResource::class, 'external_resource_id');
    }

    /**
     * Accounts bound (active binding) to a digital asset that belongs to a brand. Operator decision (2026-11-16): an
     * account not bound to a brand has no purpose in the product yet; it is listed only under Marka adayları, never
     * in integration lists, counts or alerts.
     *
     * @param  Builder<ResourceAutomation>  $query
     */
    public function scopeBrandBound(Builder $query): void
    {
        $query->whereHas('resource.bindings', fn ($q) => $q->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereHas('digitalAsset', fn ($a) => $a->whereNotNull('brand_id')));
    }
}
