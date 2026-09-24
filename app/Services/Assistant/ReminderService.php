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
        Reminder::query()->with(['customer:id,name', 'brand:id,name'])->whereNull('done_at')->whereNull('notified_at')->where('remind_at', '<=', now())
            ->orderBy('remind_at')->limit(200)->get()
            ->each(function (Reminder $reminder) use (&$sent): void {
                $context = trim(implode(' · ', array_filter([$reminder->customer?->name, $reminder->brand?->name])));
                $sent += $this->push->send('reminder:'.$reminder->id.':'.$reminder->remind_at->format('YmdHi'), 'Hatırlatma: '.$reminder->title,
                    trim(($context !== '' ? $context.'. ' : '').(string) $reminder->notes) ?: $reminder->title, 'info', route('operator.dashboard'), 24, true) > 0 ? 1 : 0;
                // Marked even without a channel so it is not retried every minute; Bugün still shows it.
                $reminder->forceFill(['notified_at' => now()])->save();
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
