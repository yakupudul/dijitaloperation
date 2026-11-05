<?php

namespace App\Services\Calendar;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Satış 4. hafta: the operator's own calendar as a read-only iCalendar feed, so Google Takvim on the phone shows the
 * meetings, tasks and prospects to write to. Google refreshes a subscribed feed by itself (every few hours); nothing is
 * ever written back. The address carries a secret token (users.calendar_feed_token); renewing it cuts the old address
 * off.
 */
final class CalendarIcs
{
    public function __construct(private CalendarFeed $feed) {}

    /** The user's feed token, created on first ask; $renew replaces it (the old address stops working). */
    public static function token(User $user, bool $renew = false): string
    {
        $token = (string) ($user->calendar_feed_token ?? '');
        if ($token === '' || $renew) {
            $token = Str::random(48);
            $user->forceFill(['calendar_feed_token' => $token])->save();
        }

        return $token;
    }

    public static function userFor(string $token): ?User
    {
        if (strlen($token) < 32 || strlen($token) > 64) {
            return null;
        }
        $user = User::query()->where('calendar_feed_token', $token)->first();

        return $user !== null && $user->is_active !== false && hash_equals((string) $user->calendar_feed_token, $token) ? $user : null;
    }

    public function render(User $user): string
    {
        $now = CarbonImmutable::now(CalendarFeed::TZ);
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Moximu//MoxDOP Takvim//TR', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH',
            'X-WR-CALNAME:MoxDOP', 'X-WR-TIMEZONE:'.CalendarFeed::TZ, 'REFRESH-INTERVAL;VALUE=DURATION:PT1H', 'X-PUBLISHED-TTL:PT1H',
        ];
        $stamp = $now->utc()->format('Ymd\THis\Z');

        foreach ($this->feed->own($now->subDays(30)->startOfDay(), $now->addDays(180)->endOfDay(), (int) $user->id) as $item) {
            $title = (string) $item['title'];
            $who = $item['lead'] ?: $item['brand'];
            $title = ($item['kind'] === 'meeting' && ! preg_match('/görüşme|toplantı/iu', $title) ? 'Toplantı: ' : '').$title
                .($who && ! str_contains(mb_strtolower($title), mb_strtolower((string) $who)) ? ' · '.$who : '');
            $notes = trim(implode("\n", array_filter([
                $item['notes'], $item['outcome'] ? 'Not: '.$item['outcome'] : null,
                $item['lead_id'] ? route('operator.sales.lead', $item['lead_id']) : route('operator.calendar', ['gun' => $item['date']]),
            ])));
            $lines = [...$lines, ...$this->event('own-'.$item['id'].'-'.$item['date'], $stamp, $item['date'], $item['time'], $item['end'] ?? null,
                $item['kind'] === 'meeting' ? 30 : 15, ($item['done'] ? '✓ ' : '').$title, $notes, $item['place'])];
        }

        foreach ($this->feed->sales($now->startOfDay(), $now->addDays(60)->endOfDay()) as $item) {
            $lines = [...$lines, ...$this->event('lead-'.$item['id'], $stamp, $item['date'], $item['time'], null, 15,
                'Satış: '.$item['step'].' · '.$item['title'].($item['late'] ? ' (gecikti)' : ''), $item['url'], null)];
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines))."\r\n";
    }

    /** @return list<string> */
    private function event(string $uid, string $stamp, string $date, ?string $time, ?string $end, int $minutes, string $title, string $notes, ?string $place): array
    {
        $out = ['BEGIN:VEVENT', 'UID:'.$uid.'@moxdop', 'DTSTAMP:'.$stamp];
        if ($time === null) {
            $day = CarbonImmutable::parse($date);
            $out[] = 'DTSTART;VALUE=DATE:'.$day->format('Ymd');
            $out[] = 'DTEND;VALUE=DATE:'.$day->addDay()->format('Ymd');
        } else {
            $start = CarbonImmutable::parse($date.' '.$time, CalendarFeed::TZ);
            $finish = $end !== null ? CarbonImmutable::parse($date.' '.$end, CalendarFeed::TZ) : null;
            $finish = $finish !== null && $finish->gt($start) ? $finish : $start->addMinutes($minutes);
            $out[] = 'DTSTART:'.$start->utc()->format('Ymd\THis\Z');
            $out[] = 'DTEND:'.$finish->utc()->format('Ymd\THis\Z');
        }
        $out[] = 'SUMMARY:'.self::text($title);
        if ($notes !== '') {
            $out[] = 'DESCRIPTION:'.self::text($notes);
        }
        if (filled($place)) {
            $out[] = 'LOCATION:'.self::text((string) $place);
        }
        $out[] = 'END:VEVENT';

        return $out;
    }

    private static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", mb_substr($value, 0, 2000));

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /** Lines longer than 75 octets are folded (RFC 5545), never inside a UTF-8 character. */
    private static function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === [] ? 75 : 74)) {
                $out[] = $current;
                $current = '';
            }
            $current .= $char;
        }
        $out[] = $current;

        return implode("\r\n ", $out);
    }
}
