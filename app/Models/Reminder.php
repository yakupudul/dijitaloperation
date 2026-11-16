<?php

namespace App\Models;

use App\Jobs\Calendar\SyncReminderToGoogleJob;
use App\Services\Calendar\GoogleCalendarLink;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

/**
 * The operator's own calendar entry (Takvim, 2026-11-05): a task or a meeting. Pushed to the phone at remind_at (a
 * meeting 15 minutes before), shown on Takvim. Repeating tasks move to their next date when marked done. A meeting
 * that is over asks for its note; the note goes to the prospect's card when the meeting is with one.
 */
class Reminder extends Model
{
    public const array REPEATS = ['none' => 'Tek sefer', 'weekly' => 'Her hafta', 'monthly' => 'Her ay', 'yearly' => 'Her yıl'];

    public const array KINDS = ['task' => 'İş', 'meeting' => 'Toplantı'];

    /** A free note (toplantı notu, telefon notu, fikir): no date, never pushed, kept until deleted (2026-11-10). */
    public const string NOTE = 'note';

    /**
     * Onay (2026-11-16): a task that is Claude's own work waiting for the operator's yes. It shows Onayla / Reddet
     * instead of a tick; the answer closes it (done_at) and Onayla hands the job back to Claude as a Moxi işi.
     */
    public const string ASKED = 'asked';

    public const string APPROVED = 'approved';

    public const string REJECTED = 'rejected';

    protected $fillable = [
        'user_id', 'customer_id', 'brand_id', 'prospect_id', 'sales_lead_id', 'kind', 'title', 'notes', 'remind_at', 'ends_at', 'all_day',
        'place', 'outcome', 'repeat', 'notified_at', 'followup_notified_at', 'done_at', 'url', 'google_event_id', 'approval',
    ];

    /** Günlük Düzen 4.3: with Google Takvim linked, a saved or deleted entry follows there (queued, after commit). */
    protected static function booted(): void
    {
        static::saved(function (self $reminder): void {
            if ($reminder->wasRecentlyCreated || $reminder->wasChanged(['title', 'notes', 'place', 'remind_at', 'ends_at', 'all_day', 'repeat', 'done_at', 'kind'])) {
                DB::afterCommit(fn () => rescue(function () use ($reminder): void {
                    if (app(GoogleCalendarLink::class)->linked()) {
                        SyncReminderToGoogleJob::dispatch((int) $reminder->id);
                    }
                }, report: false));
            }
        });
        static::deleted(function (self $reminder): void {
            if ($reminder->google_event_id !== null) {
                $eventId = (string) $reminder->google_event_id;
                DB::afterCommit(fn () => rescue(fn () => SyncReminderToGoogleJob::dispatch((int) $reminder->id, $eventId), report: false));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'remind_at' => 'datetime', 'ends_at' => 'datetime', 'notified_at' => 'datetime', 'followup_notified_at' => 'datetime',
            'done_at' => 'datetime', 'all_day' => 'boolean',
        ];
    }

    /**
     * Yapılacaklar (2026-11-10): open own tasks that wait for a tick: the ones without a date, and dated ones whose day
     * has passed (they stay until done instead of disappearing into last week).
     *
     * @param  Builder<Reminder>  $query
     */
    public function scopeOpenTodos(Builder $query, ?int $userId = null): void
    {
        $query->when($userId !== null, fn (Builder $q) => $q->where('user_id', $userId))->where('kind', 'task')->whereNull('done_at')
            ->where(fn (Builder $q) => $q->whereNull('remind_at')
                ->orWhere(fn (Builder $d) => $d->where('repeat', 'none')->where('remind_at', '<', now()->timezone('Europe/Istanbul')->startOfDay()->utc())));
    }

    public function isNote(): bool
    {
        return $this->kind === self::NOTE;
    }

    /** An open question about Claude's own work (Onayla / Reddet), not a job for the operator's hands. */
    public function waitsForApproval(): bool
    {
        return $this->approval === self::ASKED && $this->done_at === null;
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
