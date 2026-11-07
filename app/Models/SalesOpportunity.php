<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fırsat: one thing we are trying to sell to a record — the first sale of a prospect, or an upsell to a customer
 * (Buşra 20.000 → 40.000, a one-time site for an existing brand). The record keeps the conversation and the stage;
 * the opportunity keeps the money: service, amount, how it is billed, open / won / lost. A won one becomes (or
 * updates) the brand's agreement.
 */
class SalesOpportunity extends Model
{
    protected $table = 'sales_opportunities';

    protected $guarded = ['id'];

    public const array STATUSES = ['open' => 'Açık', 'won' => 'Kazanıldı', 'lost' => 'Kaybedildi'];

    /** How the amount is paid; the same words as the agreement kinds. */
    public const array BILLINGS = ['monthly' => 'Aylık', 'term' => 'Dönemlik', 'one_time' => 'Tek seferlik'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'won_at' => 'datetime', 'lost_at' => 'datetime'];
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'lead_id');
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    public function serviceLabel(): string
    {
        return $this->service !== null ? (SalesLead::SERVICES[$this->service] ?? $this->service) : 'Hizmet seçilmedi';
    }

    public function billingLabel(): string
    {
        return self::BILLINGS[$this->billing] ?? $this->billing;
    }

    /** "14.000 ₺/ay", "30.000 ₺ tek seferlik", "—". */
    public function amountLabel(): string
    {
        if ($this->amount === null) {
            return '—';
        }
        $n = number_format($this->amount, 0, ',', '.').' ₺';

        return match ($this->billing) {
            'monthly', 'term' => $n.'/ay',
            default => $n.' tek seferlik',
        };
    }

    public function title(): string
    {
        return filled($this->title) ? (string) $this->title : $this->serviceLabel();
    }
}
