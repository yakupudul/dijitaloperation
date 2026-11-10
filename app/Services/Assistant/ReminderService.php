<?php

namespace App\Services\Assistant;

use App\Models\Reminder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

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

        $sent += $this->morningSummary();

        return $sent;
    }

    /**
     * Yapılacaklar (2026-11-10): once a day from 09:00, one push per user with what is still open: today's items, the
     * late ones and the ones without a date. Runs inside the minute check (no new scheduled job).
     */
    public function morningSummary(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now('Europe/Istanbul');
        if ($now->hour < 9 || $now->hour >= 12) {
            return 0;
        }
        $sent = 0;
        $userIds = Reminder::query()->where('kind', 'task')->whereNull('done_at')->where(fn ($q) => $q->whereNull('remind_at')
            ->orWhere('remind_at', '<', $now->endOfDay()->utc()))->distinct()->pluck('user_id');
        foreach ($userIds as $userId) {
            if (! Cache::add('todo-morning:'.$userId.':'.$now->toDateString(), 1, now()->addDay())) {
                continue;
            }
            $titles = Reminder::query()->where('user_id', $userId)->where('kind', 'task')->whereNull('done_at')
                ->where(fn ($q) => $q->whereNull('remind_at')->orWhere(fn ($d) => $d->where('repeat', 'none')->where('remind_at', '<', $now->endOfDay()->utc())))
                ->orderByRaw('remind_at is null')->orderBy('remind_at')->limit(50)->pluck('title');
            if ($titles->isEmpty()) {
                continue;
            }
            $body = $titles->take(4)->map(fn ($t): string => '• '.mb_strimwidth((string) $t, 0, 60, '…'))->implode("\n").($titles->count() > 4 ? "\n+".($titles->count() - 4).' daha' : '');
            $sent += $this->push->send('todo-morning:'.$userId.':'.$now->toDateString(), 'Yapılacaklar: '.$titles->count().' açık iş', $body, 'info',
                route('operator.calendar'), 24, true) > 0 ? 1 : 0;
        }

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
