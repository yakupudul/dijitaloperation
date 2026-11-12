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

    /** A yearly row (alan adı, sunucu) is opened this many days before its day, so it can be asked for in time. */
    public const int YEARLY_AHEAD_DAYS = 35;

    /** Ortaklık: no fee of our own (a revenue share, All for Clinics); the brand is served, nothing is billed monthly. */
    public const array KINDS = ['monthly' => 'Aylık', 'term' => 'Dönemlik', 'one_time' => 'Tek seferlik', 'yearly' => 'Yıllık', 'partnership' => 'Ortaklık'];

    /** auto: a row per month (or year) from the fee. schedule: the rows are the dates and amounts the operator typed (taksit). */
    public const array PLANS = ['auto' => 'Her dönem kendiliğinden', 'schedule' => 'Taksitli (tarihleri ben girerim)'];

    public const array STATUSES = ['active' => 'Sürüyor', 'paused' => 'Durduruldu', 'ended' => 'Bitti'];

    protected function casts(): array
    {
        return ['fee' => 'integer', 'term_months' => 'integer', 'due_day' => 'integer', 'starts_on' => 'date', 'ends_on' => 'date', 'contract_on' => 'date', 'services' => 'array', 'total' => 'integer', 'fee_since' => 'date'];
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

    public function documents(): HasMany
    {
        return $this->hasMany(FinanceDocument::class)->orderByDesc('doc_date');
    }

    /** Who pays: the brand, or the name typed for a job outside the panel. */
    public function payer(): string
    {
        return (string) ($this->brand?->name ?? $this->party ?? 'İsimsiz iş');
    }

    /** "Avrupadent", "Avrupadent · Web sitesi", "Ahmet Bey · Web sitesi". */
    public function name(): string
    {
        return $this->payer().(filled($this->title) && $this->title !== $this->payer() ? ' · '.$this->title : '');
    }

    public function isSchedule(): bool
    {
        return $this->plan === 'schedule';
    }

    /** The whole price of the job when it has one: a schedule's total, a term's months × fee, a one-time fee. */
    public function wholePrice(): ?int
    {
        if ($this->isSchedule()) {
            return (int) ($this->total ?: $this->payments->sum('amount'));
        }

        return match ($this->kind) {
            'term' => (int) $this->fee * (int) $this->term_months,
            'one_time' => (int) $this->fee,
            default => null,
        };
    }

    /** What came in so far, over every row. */
    public function received(): int
    {
        return (int) $this->payments->sum(fn (AgreementPayment $p): int => $p->received());
    }

    /** What is still to come of the whole price (null for an open-ended monthly / yearly job). */
    public function remaining(): ?int
    {
        $whole = $this->wholePrice();

        return $whole === null ? null : max(0, $whole - $this->received());
    }

    /** The day the present fee began (the start when it never changed). */
    public function feeSince(): ?CarbonImmutable
    {
        $day = $this->fee_since ?? $this->starts_on;

        return $day !== null ? CarbonImmutable::parse($day->toDateString()) : null;
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
            'yearly' => $n.'/yıl',
            'term' => $n.'/ay · '.(int) $this->term_months.' ay'.($this->ends_on !== null ? ' ('.$this->starts_on?->locale('tr')->isoFormat('MMM YYYY').' – '.$this->ends_on->locale('tr')->isoFormat('MMM YYYY').')' : ''),
            default => ($this->isSchedule() && $this->total ? number_format((int) $this->total, 0, ',', '.').' ₺' : $n).' tek seferlik',
        };
    }

    /** The day of the month the fee is due, as a date in the given month (28 caps a short month). */
    public function dueIn(CarbonImmutable $month): CarbonImmutable
    {
        if ($this->kind === 'yearly' && $this->starts_on !== null) {
            return $month->startOfMonth()->setDay(min((int) $this->starts_on->day, $month->daysInMonth));
        }

        return $month->startOfMonth()->setDay(min((int) $this->due_day ?: 1, $month->daysInMonth));
    }

    /** The periods (YYYY-MM) this agreement bills, from its start to $until (inclusive) and never past ends_on. */
    public function periodsUntil(CarbonImmutable $until): array
    {
        if ($this->starts_on === null || $this->isSchedule()) {
            return []; // a schedule's rows are typed, never made from the fee
        }
        if ($this->kind === 'yearly') {
            // One row a year, on the start's month; the coming one appears YEARLY_AHEAD_DAYS before its day.
            $out = [];
            $ahead = $until->addDays(self::YEARLY_AHEAD_DAYS);
            for ($day = CarbonImmutable::instance($this->starts_on), $guard = 0; $day->lte($ahead) && $guard < 40; $day = $day->addYear(), $guard++) {
                if ($this->ends_on === null || $day->lte(CarbonImmutable::instance($this->ends_on))) {
                    $out[] = $day->format('Y-m');
                }
            }

            return $out;
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
