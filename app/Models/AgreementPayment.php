<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One month of an agreement: due on a day, ticked when it came in. No invoices, no amounts split. */
class AgreementPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'due_on' => 'date', 'paid_on' => 'date'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(Agreement::class);
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
