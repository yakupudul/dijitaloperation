<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\AssetRenewal;
use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Read-only iCalendar feed (Google Calendar → "URL ile ekle"): the user's reminders, renewals of all brands
 * and the user's task due dates. Addressed by a secret per-user token; nothing is written to Google.
 */
final class CalendarFeedController extends Controller
{
    public function __invoke(string $token): Response
    {
        abort_unless(strlen($token) >= 32, 404);
        $user = User::query()->where('calendar_feed_token', $token)->where('is_active', true)->first();
        abort_if($user === null, 404);
        $until = now()->addDays((int) config('moxdop-assistant.reminders.calendar_days_ahead', 90));
        $events = [];

        foreach (Reminder::query()->with(['customer:id,name', 'brand:id,name'])->where('user_id', $user->id)->whereNull('done_at')
            ->whereBetween('remind_at', [now()->subDays(7), $until])->get() as $reminder) {
            $events[] = ['uid' => 'reminder-'.$reminder->id, 'start' => $reminder->remind_at, 'all_day' => false,
                'summary' => $reminder->title, 'description' => trim(implode(' · ', array_filter([$reminder->customer?->name, $reminder->brand?->name, $reminder->notes])))];
        }
        foreach (AssetRenewal::query()->with('brand:id,name')->whereNotNull('expires_on')->whereBetween('expires_on', [now()->subDays(30)->toDateString(), now()->addYear()->toDateString()])->get() as $renewal) {
            $events[] = ['uid' => 'renewal-'.$renewal->id, 'start' => $renewal->expires_on, 'all_day' => true,
                'summary' => (AssetRenewal::KINDS[$renewal->kind] ?? 'Yenileme').' bitiyor: '.$renewal->label,
                'description' => trim(($renewal->brand?->name ?? '').($renewal->charge_amount !== null ? ' · ücret '.$renewal->charge_amount.' '.$renewal->currency : ''))];
        }
        foreach (DB::table('tasks')->where('assignee_id', $user->id)->whereNotNull('due_date')->whereNotIn('status', ['done', 'completed', 'cancelled'])
            ->whereBetween('due_date', [now()->subDays(7)->toDateString(), $until->toDateString()])->get(['id', 'title', 'due_date']) as $task) {
            $events[] = ['uid' => 'task-'.$task->id, 'start' => CarbonImmutable::parse((string) $task->due_date), 'all_day' => true, 'summary' => 'Görev: '.$task->title, 'description' => ''];
        }

        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//MoxDOP//Asistan//TR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:MoxDOP', 'X-WR-TIMEZONE:Europe/Istanbul'];
        foreach ($events as $event) {
            /** @var CarbonInterface $start */
            $start = $event['start'];
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:'.$event['uid'].'@moxdop';
            $lines[] = 'DTSTAMP:'.now()->utc()->format('Ymd\THis\Z');
            if ($event['all_day']) {
                $lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
                $lines[] = 'DTEND;VALUE=DATE:'.$start->copy()->addDay()->format('Ymd');
            } else {
                $lines[] = 'DTSTART:'.$start->copy()->utc()->format('Ymd\THis\Z');
                $lines[] = 'DTEND:'.$start->copy()->utc()->addMinutes(30)->format('Ymd\THis\Z');
            }
            $lines[] = 'SUMMARY:'.self::escape($event['summary']);
            if ($event['description'] !== '') {
                $lines[] = 'DESCRIPTION:'.self::escape($event['description']);
            }
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';

        return response(implode("\r\n", array_map(self::fold(...), $lines))."\r\n", 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Cache-Control' => 'private, max-age=900',
        ]);
    }

    private static function escape(string $text): string
    {
        return str_replace(['\\', "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\;'], $text);
    }

    /** RFC 5545 line folding at 75 octets. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > 74) {
                $out .= $current."\r\n ";
                $current = '';
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
