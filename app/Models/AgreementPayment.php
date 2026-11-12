<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One payment row of an agreement: a month, a year, or a taksit the operator typed. Due on a day, paid in full when
 * paid_on is set; paid_amount keeps what came in so far when it arrives in parts.
 */
class AgreementPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'due_on' => 'date', 'paid_on' => 'date', 'paid_amount' => 'integer', 'reminded_on' => 'date', 'late_reminded_on' => 'date'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
    }

    /** What came in for this row: all of it once ticked, else the parts so far. */
    public function received(): int
    {
        return $this->paid_on !== null ? max((int) $this->amount, (int) $this->paid_amount) : min((int) $this->amount, (int) $this->paid_amount);
    }

    public function open(): int
    {
        return $this->paid_on !== null ? 0 : max(0, (int) $this->amount - (int) $this->paid_amount);
    }

    public function isPartial(): bool
    {
        return $this->paid_on === null && (int) $this->paid_amount > 0;
    }

    /** "Kasım 2026", or the typed label of a taksit ("2. taksit"). */
    public function title(): string
    {
        return filled($this->label) ? (string) $this->label : $this->periodLabel();
    }

    public function isPaid(): bool
    {
        return $this->paid_on !== null;
    }

    /** Days are compared as dates (Türkiye's day), never as instants, so a time zone never adds or drops a day. */
    public function isOverdue(): bool
    {
        return $this->paid_on === null && $this->due_on !== null && $this->due_on->toDateString() < now('Europe/Istanbul')->toDateString();
    }

    /** Whole days past due (0 when paid or not yet due). */
    public function daysLate(): int
    {
        return $this->isOverdue() ? (int) CarbonImmutable::parse($this->due_on->toDateString())->diffInDays(CarbonImmutable::parse(now('Europe/Istanbul')->toDateString())) : 0;
    }

    public function periodLabel(): string
    {
        return CarbonImmutable::parse($this->period.'-01')->locale('tr')->isoFormat('MMMM YYYY');
    }
}
