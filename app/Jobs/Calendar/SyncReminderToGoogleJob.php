<?php

namespace App\Jobs\Calendar;

use App\Models\Reminder;
use App\Services\Calendar\GoogleCalendarLink;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Günlük Düzen 4.3: one own calendar entry goes to Google Takvim after it was saved, or leaves it after a delete. */
final class SyncReminderToGoogleJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public readonly int $reminderId, public readonly ?string $removedEventId = null) {}

    public function handle(GoogleCalendarLink $link): void
    {
        if (! $link->linked()) {
            return;
        }
        if ($this->removedEventId !== null) {
            $link->remove($this->removedEventId);

            return;
        }
        $reminder = Reminder::query()->find($this->reminderId);
        if ($reminder !== null) {
            $link->push($reminder);
        }
    }
}
