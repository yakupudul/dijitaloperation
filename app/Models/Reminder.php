<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The operator's own calendar entry (Takvim, 2026-11-05): a task or a meeting. Pushed to the phone at remind_at (a
 * meeting 15 minutes before), shown on Takvim. Repeating tasks move to their next date when marked done. A meeting
 * that is over asks for its note; the note goes to the prospect's card when the meeting is with one.
 */
class Reminder extends Model
{
    public const array REPEATS = ['none' => 'Tek sefer', 'weekly' => 'Her hafta', 'monthly' => 'Her ay', 'yearly' => 'Her yıl'];

    public const array KINDS = ['task' => 'İş', 'meeting' => 'Toplantı'];

    protected $fillable = [
        'user_id', 'customer_id', 'brand_id', 'prospect_id', 'sales_lead_id', 'kind', 'title', 'notes', 'remind_at', 'ends_at', 'all_day',
        'place', 'outcome', 'repeat', 'notified_at', 'followup_notified_at', 'done_at',
    ];

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime', 'ends_at' => 'datetime', 'notified_at' => 'datetime', 'followup_notified_at' => 'datetime',
            'done_at' => 'datetime', 'all_day' => 'boolean',
        ];
    }

    public function isMeeting(): bool
    {
        return $this->kind === 'meeting';
    }

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

    /** @return BelongsTo<SalesLead, $this> */
    public function salesLead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class);
    }
}
