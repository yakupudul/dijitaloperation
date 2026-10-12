<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A strategy Claude wrote for the operator (Stratejiler): its scope (brand, channel, service, target markets and
 * languages, budget), its status and its versions. The text lives in StrategyVersion; `current_version` points at the
 * one shown. Nothing here is sent to an ad account.
 */
class Strategy extends Model
{
    public const array STATUSES = ['draft' => 'Taslak', 'approved' => 'Onaylandı', 'active' => 'Uygulanıyor', 'archived' => 'Arşiv'];

    public const array CHANNELS = ['google_ads' => 'Google Ads', 'meta' => 'Meta reklamları', 'gbp' => 'İşletme profili', 'site' => 'Web sitesi', 'multi' => 'Çok kanallı'];

    public const array BUDGET_PERIODS = ['daily' => 'günlük', 'monthly' => 'aylık', 'total' => 'toplam'];

    /** @var list<string> */
    protected $fillable = ['brand_id', 'title', 'channel', 'service', 'markets', 'languages', 'budget_amount', 'budget_currency',
        'budget_period', 'status', 'summary', 'request', 'source_ref', 'current_version', 'status_changed_at', 'plan'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['markets' => 'array', 'languages' => 'array', 'plan' => 'array', 'budget_amount' => 'decimal:2', 'status_changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return HasMany<StrategyVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(StrategyVersion::class)->orderByDesc('version');
    }

    /** The Ads Editor file is offered once the operator approved the strategy. */
    public function isExportable(): bool
    {
        return $this->channel === 'google_ads' && ! empty($this->plan['campaigns'] ?? []) && in_array($this->status, ['approved', 'active'], true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    public function channelLabel(): string
    {
        return self::CHANNELS[$this->channel] ?? $this->channel;
    }

    /** "4.000 TRY günlük", or null without a budget. */
    public function budgetLabel(): ?string
    {
        if ($this->budget_amount === null) {
            return null;
        }
        $amount = number_format((float) $this->budget_amount, (float) $this->budget_amount == floor((float) $this->budget_amount) ? 0 : 2, ',', '.');

        return trim($amount.' '.$this->budget_currency.' '.(self::BUDGET_PERIODS[$this->budget_period] ?? ''));
    }

    /** @return array<string, mixed> how a strategy is listed to Claude */
    public function toTool(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'brand_id' => $this->brand_id,
            'brand' => $this->brand?->name,
            'channel' => $this->channel,
            'service' => $this->service,
            'markets' => $this->markets ?? [],
            'languages' => $this->languages ?? [],
            'budget' => $this->budget_amount === null ? null : ['amount' => (float) $this->budget_amount, 'currency' => $this->budget_currency, 'period' => $this->budget_period],
            'status' => $this->status,
            'summary' => $this->summary,
            'current_version' => $this->current_version,
            'has_plan' => ! empty($this->plan['campaigns'] ?? []),
            'source_ref' => $this->source_ref,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
