<?php

namespace App\Models;

use App\Enums\SeoTaskStatus;
use App\Enums\SeoTaskType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A concrete SEO task produced by the rule engine (optionally enriched by the LLM).
 * Deliberately separate from Task / Finding / Recommendation.
 */
class SeoTask extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<BrandOffering, $this> */
    public function offering(): BelongsTo
    {
        return $this->belongsTo(BrandOffering::class, 'brand_offering_id');
    }

    /** @return BelongsTo<SeoPlan, $this> */
    public function firstSeenPlan(): BelongsTo
    {
        return $this->belongsTo(SeoPlan::class, 'first_seen_plan_id');
    }

    /** @return BelongsTo<SeoPlan, $this> */
    public function lastSeenPlan(): BelongsTo
    {
        return $this->belongsTo(SeoPlan::class, 'last_seen_plan_id');
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** @param Builder<SeoTask> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', SeoTaskStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === SeoTaskStatus::Open;
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'type' => SeoTaskType::class,
            'status' => SeoTaskStatus::class,
            'priority_score' => 'float',
            'estimated_extra_clicks' => 'float',
            'evidence' => 'array',
            'checklist' => 'array',
            'content_brief' => 'array',
            'llm_payload' => 'array',
            'is_new_page' => 'boolean',
            'resolved_at' => 'datetime',
        ];
    }
}
