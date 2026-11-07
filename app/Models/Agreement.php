<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Anlaşma: what a brand pays and how (yakup 2026-11-07 "Markalara çalışma durumunu gireyim… aylık / 6 aylık / tek
 * seferlik"). Aylık: a fee every month until stopped. Dönemlik: a fee every month for term_months (the 6-month campaign
 * with the site as a gift). Tek seferlik: one fee once (a site build). One active agreement per brand at a time; a new
 * one ends the previous. The monthly tick lives in agreement_payments; MoxDOP keeps no invoices.
 */
class Agreement extends Model
{
    protected $guarded = ['id'];

    /** Ortaklık: no fee of our own (a revenue share, All for Clinics); the brand is served, nothing is billed monthly. */
    public const array KINDS = ['monthly' => 'Aylık', 'term' => 'Dönemlik', 'one_time' => 'Tek seferlik', 'partnership' => 'Ortaklık'];

    public const array STATUSES = ['active' => 'Sürüyor', 'paused' => 'Durduruldu', 'ended' => 'Bitti'];

    protected function casts(): array
    {
        return ['fee' => 'integer', 'term_months' => 'integer', 'due_day' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'services' => 'array'];
    }

    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'lead_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AgreementPayment::class)->orderBy('period');
    }

    /** @param  Builder<self>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }

    /** What the brand brings per month: the fee for aylık and dönemlik, nothing for tek seferlik. */
    public function monthlyValue(): int
    {
        return $this->isActive() && in_array($this->kind, ['monthly', 'term', 'partnership'], true) ? (int) $this->fee : 0;
    }

    /** "14.000 ₺/ay", "18.000 ₺/ay · 6 ay (Ocak–Haziran 2027)", "30.000 ₺ tek seferlik". */
    public function feeLabel(): string
    {
        $n = number_format((int) $this->fee, 0, ',', '.').' ₺';

        return match ($this->kind) {
            'partnership' => 'Ortaklık'.((int) $this->fee > 0 ? ' · '.$n.'/ay' : ' (gelir paylaşımı)'),
            'monthly' => $n.'/ay',
            'term' => $n.'/ay · '.(int) $this->term_months.' ay'.($this->ends_on !== null ? ' ('.$this->starts_on?->locale('tr')->isoFormat('MMM YYYY').' – '.$this->ends_on->locale('tr')->isoFormat('MMM YYYY').')' : ''),
            default => $n.' tek seferlik',
        };
    }

    /** The day of the month the fee is due, as a date in the given month (28 caps a short month). */
    public function dueIn(CarbonImmutable $month): CarbonImmutable
    {
        return $month->startOfMonth()->setDay(min((int) $this->due_day ?: 1, $month->daysInMonth));
    }

    /** The periods (YYYY-MM) this agreement bills, from its start to $until (inclusive) and never past ends_on. */
    public function periodsUntil(CarbonImmutable $until): array
    {
        if ($this->starts_on === null) {
            return [];
        }
        // Months are compared as "YYYY-MM" so the dates' time zones never shift a month (dates are stored as days).
        $start = CarbonImmutable::instance($this->starts_on)->format('Y-m');
        if ($this->kind === 'one_time') {
            return [$start];
        }
        if ($this->kind === 'partnership' && (int) $this->fee === 0) {
            return []; // nothing of ours to tick
        }
        $end = $until->format('Y-m');
        if ($this->ends_on !== null) {
            $end = min($end, CarbonImmutable::instance($this->ends_on)->format('Y-m'));
        }
        $out = [];
        for ($month = CarbonImmutable::parse($start.'-01'), $guard = 0; $month->format('Y-m') <= $end && $guard < 240; $month = $month->addMonth(), $guard++) {
            $out[] = $month->format('Y-m');
        }

        return $out;
    }
}
