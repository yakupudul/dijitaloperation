<?php

namespace App\Models;

use App\Enums\AdvisorCategory;
use App\Enums\AdvisorItemStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One advisor recommendation (e.g. a negative keyword list). Rule-produced; AI only drafts copy on click.
 */
class AdvisorItem extends Model
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

    /** @param Builder<AdvisorItem> $query */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', AdvisorItemStatus::Open->value);
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

    public function severityColor(): string
    {
        return match ($this->severity) {
            'critical', 'high' => 'error',
            'medium' => 'warning',
            default => 'light',
        };
    }

    public function money(?float $amount): string
    {
        if ($amount === null) {
            return '—';
        }
        $symbol = match ($this->currency) {
            'TRY' => '₺',
            'USD' => '$',
            'EUR' => '€',
            default => ($this->currency ?? '').' ',
        };

        return $symbol.number_format($amount, 0, ',', '.');
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'category' => AdvisorCategory::class,
            'status' => AdvisorItemStatus::class,
            'priority_score' => 'float',
            'impact_amount' => 'float',
            'evidence' => 'array',
            'checklist' => 'array',
            'baseline' => 'array',
            'draft' => 'array',
            'resolved_at' => 'datetime',
            'outcome' => 'array',
            'measured_at' => 'datetime',
        ];
    }
}
