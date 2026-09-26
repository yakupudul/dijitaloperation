<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What was billed to a customer for a period and whether it was paid (internal record; no e-invoice integration). */
class Invoice extends Model
{
    public const array STATUSES = ['draft' => 'Taslak', 'issued' => 'Kesildi', 'paid' => 'Ödendi', 'cancelled' => 'İptal'];

    protected $table = 'agency_invoices';

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'issued_on' => 'date', 'due_on' => 'date', 'paid_on' => 'date'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function isOverdue(): bool
    {
        return $this->status === 'issued' && $this->due_on !== null && $this->due_on->isPast();
    }
}
