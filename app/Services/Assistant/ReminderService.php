<?php

namespace App\Services\Assistant;

use App\Models\Reminder;

/**
 * Reminders: due ones are pushed to the phone once (every minute check); marking a repeating reminder done
 * moves it to its next date instead of closing it.
 */
final class ReminderService
{
    public function __construct(private readonly PushNotifier $push) {}

    public function dispatchDue(): int
    {
        $sent = 0;
        // A meeting is announced 15 minutes ahead; a task at its time.
        Reminder::query()->with(['customer:id,name', 'brand:id,name'])->whereNull('done_at')->whereNull('notified_at')
            ->where(fn ($q) => $q->where(fn ($t) => $t->where('kind', '!=', 'meeting')->where('remind_at', '<=', now()))
                ->orWhere(fn ($m) => $m->where('kind', 'meeting')->where('remind_at', '<=', now()->addMinutes(15))))
            ->orderBy('remind_at')->limit(200)->get()
            ->each(function (Reminder $reminder) use (&$sent): void {
                $context = trim(implode(' · ', array_filter([$reminder->customer?->name, $reminder->brand?->name])));
                $meeting = $reminder->isMeeting();
                $title = $meeting ? 'Toplantı '.$reminder->remind_at->timezone('Europe/Istanbul')->format('H:i').': '.$reminder->title : 'Hatırlatma: '.$reminder->title;
                $body = trim(($context !== '' ? $context.'. ' : '').($meeting && filled($reminder->place) ? $reminder->place.' ' : '').(string) $reminder->notes) ?: $reminder->title;
                $sent += $this->push->send('reminder:'.$reminder->id.':'.$reminder->remind_at->format('YmdHi'), $title, $body, 'info',
                    route('operator.calendar', ['gun' => $reminder->remind_at->timezone('Europe/Istanbul')->toDateString()]), 24, true) > 0 ? 1 : 0;
                // Marked even without a channel so it is not retried every minute; Takvim still shows it.
                $reminder->forceFill(['notified_at' => now()])->save();
            });

        // A meeting that ended without a note asks for one once (the note goes to the prospect's card).
        Reminder::query()->where('kind', 'meeting')->whereNull('outcome')->whereNull('followup_notified_at')
            ->whereRaw('coalesce(ends_at, remind_at) <= ?', [now()->subMinutes(5)])->where('remind_at', '>=', now()->subDays(2))
            ->orderBy('remind_at')->limit(50)->get()
            ->each(function (Reminder $reminder) use (&$sent): void {
                $sent += $this->push->send('meeting-note:'.$reminder->id, 'Toplantı bitti: notu yaz', $reminder->title.'. İki satır yeter: ne konuşuldu, sıradaki adım ne?', 'info',
                    route('operator.calendar', ['gun' => $reminder->remind_at->timezone('Europe/Istanbul')->toDateString()]), 24, true) > 0 ? 1 : 0;
                $reminder->forceFill(['followup_notified_at' => now()])->save();
            });

        return $sent;
    }

    public function complete(Reminder $reminder): void
    {
        $next = match ($reminder->repeat) {
            'weekly' => $reminder->remind_at->copy()->addWeek(),
            'monthly' => $reminder->remind_at->copy()->addMonthNoOverflow(),
            'yearly' => $reminder->remind_at->copy()->addYear(),
            default => null,
        };
        $reminder->forceFill($next !== null ? ['remind_at' => $next, 'notified_at' => null] : ['done_at' => now()])->save();
    }
}
