<?php

namespace App\Services\Calendar;

use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Assistant\ReminderService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Yapılacaklar + hızlı not (yakup 2026-11-10): one line typed anywhere (Takvim's box, the browser extension's "+",
 * the phone shortcut, Moxi) becomes a to-do, a reminder or a note. Plain rules, no AI:
 * "yarın 10'da Şaban Bey'i ara" → reminder tomorrow 10:00; "cuma teklif gönder" → Friday, all day;
 * "not: …" → a note; "toplantı: perşembe 14:00 Dentocare" → a meeting; anything else → a to-do without a date that
 * stays on Takvim until it is ticked. The first line is the title, the rest is kept as the note.
 */
final class QuickCapture
{
    private const array WEEKDAYS = [
        'pazartesi' => 1, 'salı' => 2, 'sali' => 2, 'çarşamba' => 3, 'carsamba' => 3, 'perşembe' => 4, 'persembe' => 4,
        'cumartesi' => 6, 'cuma' => 5, 'pazar' => 7,
    ];

    private const array MONTHS = [
        'ocak' => 1, 'şubat' => 2, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5, 'mayis' => 5, 'haziran' => 6,
        'temmuz' => 7, 'ağustos' => 8, 'agustos' => 8, 'eylül' => 9, 'eylul' => 9, 'ekim' => 10, 'kasım' => 11, 'kasim' => 11, 'aralık' => 12, 'aralik' => 12,
    ];

    /** A Turkish case suffix after a day word: yarına, cumaya, pazartesiye, cuma günü, kasımda. */
    private const string SUFFIX = "(?:['’]?(?:y?[ae]|[dt][ae]|n[ae])|\\s+günü|\\s+gunu)?";

    private const string END = '(?![\p{L}\d])';

    private const string START = '(?<![\p{L}\d])';

    public function __construct(private readonly ReminderService $reminders, private readonly CalendarFeed $feed) {}

    /**
     * @return array{kind: string, title: string, notes: ?string, at: ?CarbonImmutable, all_day: bool}
     */
    public static function parse(string $text, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now(CalendarFeed::TZ);
        $text = trim(str_replace("\r", '', $text));
        $lines = explode("\n", $text, 2);
        $line = trim($lines[0]);
        $rest = isset($lines[1]) ? trim($lines[1]) : '';

        if (preg_match('/^(?:not|toplantı notu|toplanti notu)\s*[:\-–]\s*/iu', $line, $m) === 1) {
            $body = trim(mb_substr($line, mb_strlen($m[0])));
            $title = $body !== '' ? $body : (string) Str::of($rest)->explode("\n")->first();

            return ['kind' => Reminder::NOTE, 'title' => self::title($title), 'notes' => trim($body."\n".$rest) ?: null, 'at' => null, 'all_day' => false];
        }

        $kind = 'task';
        if (preg_match('/^(?:toplantı|toplanti|görüşme|gorusme)\s*[:\-–]\s*/iu', $line, $m) === 1) {
            $kind = 'meeting';
            $line = trim(mb_substr($line, mb_strlen($m[0])));
        }

        [$line, $date] = self::takeDate($line, $now);
        [$line, $time] = self::takeTime($line);

        $at = null;
        $allDay = false;
        if ($date !== null || $time !== null) {
            $day = $date ?? $now->startOfDay();
            $at = $day->setTime($time[0] ?? 9, $time[1] ?? 0);
            $allDay = $time === null;
            if ($date === null && $at->lte($now)) {
                // "15:00'te ara" written at 16:00 means tomorrow.
                $at = $at->addDay();
            }
        }
        if ($kind === 'meeting' && ($at === null || $allDay)) {
            $kind = 'task';
        }

        $title = self::title($line);
        if ($title === '' && $rest !== '') {
            $title = self::title((string) Str::of($rest)->explode("\n")->first());
        }

        return ['kind' => $kind, 'title' => $title, 'notes' => $rest !== '' ? $rest : null, 'at' => $at, 'all_day' => $allDay];
    }

    /** Stores one capture for the user; the same text twice within 10 seconds (a double Enter) is stored once. */
    public function capture(User $user, string $text, ?string $url = null): Reminder
    {
        $parsed = self::parse($text);
        if ($parsed['title'] === '') {
            throw new \InvalidArgumentException('Boş not.');
        }
        $url = self::cleanUrl($url);
        $recent = Reminder::query()->where('user_id', $user->id)->where('title', $parsed['title'])->where('created_at', '>=', now()->subSeconds(10))->first();
        if ($recent !== null) {
            return $recent;
        }
        $brand = self::brandFrom($parsed['title']);
        $at = $parsed['at'];

        return Reminder::query()->create([
            'user_id' => $user->id, 'kind' => $parsed['kind'], 'title' => $parsed['title'], 'notes' => $parsed['notes'],
            'remind_at' => $at?->utc(), 'ends_at' => $parsed['kind'] === 'meeting' && $at !== null ? $at->addMinutes(30)->utc() : null,
            'all_day' => $parsed['all_day'], 'repeat' => 'none', 'url' => $url,
            'brand_id' => $brand?->id, 'customer_id' => $brand?->customer_id,
        ]);
    }

    /** One line saying where the capture went, for the extension and Takvim's notice. */
    public static function said(Reminder $row): string
    {
        if ($row->isNote()) {
            return 'Not kaydedildi.';
        }
        if ($row->remind_at === null) {
            return 'Yapılacaklar\'a eklendi; bitirince tikle.';
        }
        $at = CarbonImmutable::instance($row->remind_at)->setTimezone(CalendarFeed::TZ);
        $date = $at->toDateString();
        $now = CarbonImmutable::now(CalendarFeed::TZ);
        $day = match ($date) {
            $now->toDateString() => 'Bugün',
            $now->addDay()->toDateString() => 'Yarın',
            default => $at->locale('tr')->translatedFormat('j F l'),
        };

        return $row->isMeeting()
            ? 'Toplantı: '.$day.' '.$at->format('H:i').'; 15 dakika önce hatırlatılır.'
            : ($row->all_day ? $day.' için eklendi; sabah 09:00\'da hatırlatılır.' : $day.' '.$at->format('H:i').'\'da hatırlatılır.');
    }

    /**
     * Yapılacaklar for one user: today's own items, the late ones, the ones without a date, and the latest notes.
     *
     * @return array{today: list<array<string, mixed>>, late: list<array<string, mixed>>, open: list<array<string, mixed>>, notes: list<array<string, mixed>>, count: int}
     */
    public function board(int $userId, int $notes = 12): array
    {
        $now = CarbonImmutable::now(CalendarFeed::TZ);
        $today = [];
        foreach ($this->feed->own($now->startOfDay(), $now->endOfDay(), $userId) as $item) {
            $today[] = ['id' => $item['id'], 'kind' => $item['kind'], 'title' => $item['title'], 'time' => $item['time'], 'done' => $item['done'],
                'brand' => $item['brand'], 'notes' => $item['notes'], 'url' => null, 'late' => null];
        }
        $urls = Reminder::query()->whereIn('id', array_column($today, 'id'))->pluck('url', 'id');
        foreach ($today as $i => $item) {
            $today[$i]['url'] = $urls[$item['id']] ?? null;
        }

        $late = $open = [];
        Reminder::query()->with('brand:id,name')->openTodos($userId)->orderByRaw('remind_at is null')->orderBy('remind_at')->orderBy('id')->limit(200)->get()
            ->each(function (Reminder $row) use (&$late, &$open, $now): void {
                $item = self::row($row);
                if ($row->remind_at !== null) {
                    $days = (int) CarbonImmutable::instance($row->remind_at)->setTimezone(CalendarFeed::TZ)->startOfDay()->diffInDays($now->startOfDay());
                    $item['late'] = $days <= 1 ? 'dünden kaldı' : $days.' gün gecikti';
                    $late[] = $item;
                } else {
                    $open[] = $item;
                }
            });

        $noteRows = Reminder::query()->with('brand:id,name')->where('user_id', $userId)->where('kind', Reminder::NOTE)
            ->orderByDesc('id')->limit($notes)->get()->map(fn (Reminder $r): array => self::row($r) + ['created' => $r->created_at?->timezone(CalendarFeed::TZ)->locale('tr')->translatedFormat('j M H:i')])->all();

        $openToday = count(array_filter($today, fn (array $t): bool => ! $t['done']));

        return ['today' => $today, 'late' => $late, 'open' => $open, 'notes' => $noteRows, 'count' => $openToday + count($late) + count($open)];
    }

    /** Ticks an item (a repeating one moves to its next date); a second tick opens it again. */
    public function toggle(int $userId, int $id): Reminder
    {
        $row = Reminder::query()->where('user_id', $userId)->where('kind', '!=', Reminder::NOTE)->findOrFail($id);
        if ($row->done_at !== null) {
            $row->forceFill(['done_at' => null])->save();
        } else {
            $this->reminders->complete($row);
        }

        return $row->refresh();
    }

    /** The user's capture key for the browser extension, created on first ask; $renew cuts the old one off. */
    public static function token(User $user, bool $renew = false): string
    {
        $token = (string) ($user->capture_token ?? '');
        if ($token === '' || $renew) {
            $token = Str::random(48);
            $user->forceFill(['capture_token' => $token])->save();
        }

        return $token;
    }

    public static function userFor(?string $token): ?User
    {
        $token = (string) $token;
        if (strlen($token) < 32 || strlen($token) > 64) {
            return null;
        }
        $user = User::query()->where('capture_token', $token)->first();

        return $user !== null && $user->is_active !== false && hash_equals((string) $user->capture_token, $token) ? $user : null;
    }

    /** @return array<string, mixed> */
    private static function row(Reminder $row): array
    {
        return ['id' => (int) $row->id, 'kind' => (string) $row->kind, 'title' => (string) $row->title, 'notes' => $row->notes, 'url' => $row->url,
            'brand' => $row->brand?->name, 'done' => $row->done_at !== null, 'time' => null, 'late' => null];
    }

    /** @return array{0: string, 1: ?CarbonImmutable} */
    private static function takeDate(string $line, CarbonImmutable $now): array
    {
        $today = $now->startOfDay();
        $s = self::START;
        $e = self::END;
        $suffix = self::SUFFIX;
        $rules = [
            // 15 kasım, 3 aralığa
            '/'.$s.'(\d{1,2})\s+('.implode('|', array_keys(self::MONTHS)).')(?:\s+(\d{4}))?'."(?:['’]?(?:[dt][ae]|y?[ae]|ına|ine|ina))?".$e.'/iu' => function (array $m) use ($today): ?CarbonImmutable {
                $month = self::MONTHS[mb_strtolower($m[2])] ?? null;
                $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $today->year;
                if ($month === null || ! checkdate($month, (int) $m[1], $year)) {
                    return null;
                }
                $d = CarbonImmutable::create($year, $month, (int) $m[1], 0, 0, 0, CalendarFeed::TZ);

                return isset($m[3]) && $m[3] !== '' ? $d : ($d->lt($today) ? $d->addYear() : $d);
            },
            // 15.11 / 15.11.2026 / 15/11 (a time is written with ":" or "saat")
            '/'.$s.'(\d{1,2})[.\/](\d{1,2})(?:[.\/](\d{4}))?(?![.:\d])'."(?!['’]?[dt][ae]".$e.')/u' => function (array $m) use ($today): ?CarbonImmutable {
                $year = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : $today->year;
                if ((int) $m[2] > 12 || ! checkdate((int) $m[2], (int) $m[1], $year)) {
                    return null;
                }
                $d = CarbonImmutable::create($year, (int) $m[2], (int) $m[1], 0, 0, 0, CalendarFeed::TZ);

                return isset($m[3]) && $m[3] !== '' ? $d : ($d->lt($today) ? $d->addYear() : $d);
            },
            '/'.$s.'(\d{1,2})\s+gün\s+sonra'.$e.'/iu' => fn (array $m) => $today->addDays((int) $m[1]),
            '/'.$s.'(\d{1,2})\s+hafta\s+sonra'.$e.'/iu' => fn (array $m) => $today->addWeeks((int) $m[1]),
            '/'.$s.'(?:haftaya|gelecek\s+hafta|önümüzdeki\s+hafta)\s+('.implode('|', array_keys(self::WEEKDAYS)).')'.$suffix.$e.'/iu' => fn (array $m) => $today->startOfWeek(CarbonImmutable::MONDAY)->addWeek()->addDays(self::WEEKDAYS[mb_strtolower($m[1])] - 1),
            '/'.$s.'('.implode('|', array_keys(self::WEEKDAYS)).')'.$suffix.$e.'/iu' => function (array $m) use ($today): CarbonImmutable {
                $target = self::WEEKDAYS[mb_strtolower($m[1])];
                $diff = ($target - $today->dayOfWeekIso + 7) % 7;

                return $today->addDays($diff);
            },
            '/'.$s.'(?:öbür\s+gün|öbürsü\s+gün|yarından\s+sonra)'.$suffix.$e.'/iu' => fn () => $today->addDays(2),
            '/'.$s.'yarın'.$suffix.$e.'/iu' => fn () => $today->addDay(),
            '/'.$s.'bugün'.$suffix.$e.'/iu' => fn () => $today,
            '/'.$s.'(?:haftaya|gelecek\s+hafta|önümüzdeki\s+hafta)'.$e.'/iu' => fn () => $today->addWeek(),
            '/'.$s.'(?:ay\s+sonu(?:nda)?)'.$e.'/iu' => fn () => $today->endOfMonth()->startOfDay(),
        ];
        foreach ($rules as $pattern => $make) {
            if (preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE) === 1) {
                $values = array_map(fn ($g) => $g[0], $m);
                $date = $make($values);
                if ($date instanceof CarbonImmutable) {
                    return [self::cut($line, $m[0][1], strlen($m[0][0])), $date];
                }
            }
        }

        return [$line, null];
    }

    /** @return array{0: string, 1: ?array{0: int, 1: int}} */
    private static function takeTime(string $line): array
    {
        $s = self::START;
        $e = self::END;
        $evening = preg_match('/'.$s.'(?:akşam|aksam|öğleden\s+sonra|ogleden\s+sonra|gece)'.$e.'/iu', $line) === 1;
        $morning = preg_match('/'.$s.'(?:sabah)'.$e.'/iu', $line) === 1;
        $rules = [
            '/'.$s.'(?:(?:sabah|akşam|aksam|öğleden\s+sonra|ogleden\s+sonra|öğlen|gece)\s+)?saat\s+(\d{1,2})(?:[:.](\d{2}))?'."(?:['’]?(?:[dt][ae]|y?[ae]))?".$e.'/iu',
            '/'.$s.'(?:(?:sabah|akşam|aksam|öğleden\s+sonra|ogleden\s+sonra|öğlen|gece)\s+)?(\d{1,2})[:.](\d{2})'."(?:['’]?(?:[dt][ae]|y?[ae]))?".$e.'/iu',
            '/'.$s.'(?:(?:sabah|akşam|aksam|öğleden\s+sonra|ogleden\s+sonra|öğlen|gece)\s+)?(\d{1,2})(?:\.(\d{2}))?'."['’]?[dt][ae]".$e.'/iu',
        ];
        foreach ($rules as $pattern) {
            if (preg_match($pattern, $line, $m, PREG_OFFSET_CAPTURE) === 1) {
                $hour = (int) $m[1][0];
                $minute = isset($m[2]) && $m[2][1] >= 0 ? (int) $m[2][0] : 0;
                if ($hour > 23 || $minute > 59) {
                    continue;
                }
                // "3'te ara" is 15:00 in a working day; "sabah 7'de" stays 07:00.
                if ($hour < 12 && ($evening || ($hour >= 1 && $hour <= 7 && ! $morning && ! str_contains($m[0][0], ':')))) {
                    $hour += 12;
                }

                return [self::cut($line, $m[0][1], strlen($m[0][0])), [$hour, $minute]];
            }
        }

        return [$line, null];
    }

    private static function cut(string $line, int $offset, int $length): string
    {
        return substr($line, 0, $offset).' '.substr($line, $offset + $length);
    }

    private static function title(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text, " \t,;.-–:");
        $text = preg_replace('/^(?:ve|de|da)\s+/iu', '', $text) ?? $text;

        return mb_substr(Str::ucfirst(trim($text)), 0, 255);
    }

    private static function cleanUrl(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '' || ! preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        // The panel's own pages are not worth keeping as "the page it was written on".
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($host && parse_url($url, PHP_URL_HOST) === $host) {
            return null;
        }

        return mb_substr($url, 0, 1000);
    }

    /** "Suada: ödeme hatırlat" links the brand when the part before the colon is a brand's name. */
    private static function brandFrom(string $title): ?Brand
    {
        if (preg_match('/^([^:]{2,60}):\s*\S/u', $title, $m) !== 1) {
            return null;
        }
        $name = mb_strtolower(trim($m[1]));

        return Brand::query()->get(['id', 'name', 'customer_id'])
            ->first(fn (Brand $b): bool => mb_strtolower((string) $b->name) === $name || str_starts_with(mb_strtolower((string) $b->name), $name.' '));
    }
}
