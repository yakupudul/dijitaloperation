<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A personal reminder: pushed to the phone at remind_at, listed on Bugün and in the calendar feed.
 * Repeating reminders move to their next date when marked done.
 */
class Reminder extends Model
{
    public const array REPEATS = ['none' => 'Tek sefer', 'weekly' => 'Her hafta', 'monthly' => 'Her ay', 'yearly' => 'Her yıl'];

    protected $fillable = ['user_id', 'customer_id', 'brand_id', 'prospect_id', 'title', 'notes', 'remind_at', 'repeat', 'notified_at', 'done_at'];

    protected function casts(): array
    {
        return ['remind_at' => 'datetime', 'notified_at' => 'datetime', 'done_at' => 'datetime'];
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
}
