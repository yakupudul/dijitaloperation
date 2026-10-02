<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time-sensitive signal on a digital asset (spend spike, conversions stopped, traffic drop, stale data,
 * low-rated unanswered review). Re-detected daily; resolved automatically when the condition is gone.
 */
class AssetAlert extends Model
{
    protected $fillable = [
        'digital_asset_id', 'brand_id', 'alert_key', 'kind', 'severity', 'title', 'message', 'data',
        'first_detected_at', 'last_detected_at', 'resolved_at', 'snoozed_until', 'snoozed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
            'snoozed_until' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /**
     * Open and not snoozed (Faz 11a): what the operator should see now.
     *
     * @param  Builder<self>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('resolved_at')->where(fn (Builder $q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()));
    }

    public function isSnoozed(): bool
    {
        return $this->snoozed_until !== null && $this->snoozed_until->isFuture();
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function severityColor(): string
    {
        return match ($this->severity) {
            'critical', 'high' => 'error',
            'medium' => 'warning',
            default => 'light',
        };
    }

    public function severityLabel(): string
    {
        return match ($this->severity) {
            'critical' => 'Kritik',
            'high' => 'Yüksek',
            'medium' => 'Orta',
            default => 'Düşük',
        };
    }
}
