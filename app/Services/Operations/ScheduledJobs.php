<?php

namespace App\Services\Operations;

use App\Models\AgencySetting;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Zamanlanmış işler (Bağlantı Düzeni 2.4): every job of the real schedule (routes/console.php) with how often it
 * runs, when it last ran, how much time it took in the last 30 days (UsageLedger) and a switch. A job switched off
 * here is skipped by the scheduler (routes/console.php ends with the skip hook); the jobs that keep the system itself
 * alive (backup, queue, deploy, alerts) cannot be switched off.
 */
final class ScheduledJobs
{
    private const string CACHE = 'scheduled-jobs.off';

    /** Jobs the panel must not stop: without them data is lost or the system goes blind. */
    private const string VITAL = '/backup|horizon|deploy|queue|watchdog|heartbeat|ops:|usage|prune|async:|schedule|health|redispatch|external-writes/i';

    /**
     * @return list<array{key: string, name: string, what: string, cadence: string, next: ?CarbonImmutable, last: ?CarbonImmutable, runs: int, minutes: float, failures: int, off: bool, vital: bool}>
     */
    public function all(): array
    {
        $usage = $this->usage();
        $off = self::off();
        $descriptions = $this->descriptions();
        $rows = [];
        foreach ($this->events() as $event) {
            $key = UsageLedger::jobKey($event);
            if (isset($rows[$key])) {
                continue; // the same command on a second line of the schedule (a catch-up run) is one job
            }
            $command = explode(' ', $key)[0];
            $timezone = $event->timezone instanceof \DateTimeZone ? $event->timezone->getName() : (string) ($event->timezone ?: config('app.timezone'));
            try {
                $next = CarbonImmutable::instance((new CronExpression($event->expression))->getNextRunDate(now($timezone)))->timezone('Europe/Istanbul');
            } catch (Throwable) {
                $next = null;
            }
            $used = $usage[$key] ?? null;
            $rows[$key] = [
                'key' => $key,
                'name' => (string) ($event->description ?: $command),
                'what' => (string) ($descriptions[$command] ?? ''),
                'cadence' => self::cadence((string) $event->expression),
                'next' => in_array($key, $off, true) ? null : $next,
                'last' => $used !== null && $used->last_at !== null ? CarbonImmutable::parse($used->last_at)->timezone('Europe/Istanbul') : null,
                'runs' => (int) ($used->runs ?? 0),
                'minutes' => round(((int) ($used->ms ?? 0)) / 60000, 1),
                'failures' => (int) ($used->failures ?? 0),
                'off' => in_array($key, $off, true),
                'vital' => self::vital($key),
            ];
        }
        $rows = array_values($rows);
        usort($rows, fn (array $a, array $b): int => [$b['off'], $b['failures'] > 0, $b['minutes']] <=> [$a['off'], $a['failures'] > 0, $a['minutes']]);

        return $rows;
    }

    public static function vital(string $key): bool
    {
        return preg_match(self::VITAL, $key) === 1;
    }

    /** @return list<string> keys of the jobs switched off */
    public static function off(): array
    {
        try {
            return Cache::remember(self::CACHE, 60, function (): array {
                if (! SchemaState::hasColumn('agency_settings', 'jobs_off')) {
                    return [];
                }
                $stored = json_decode((string) (DB::table('agency_settings')->value('jobs_off') ?? '[]'), true);

                return is_array($stored) ? array_values(array_filter($stored, 'is_string')) : [];
            });
        } catch (Throwable) {
            return []; // a database hiccup never stops the schedule
        }
    }

    /** The scheduler's question before each run. */
    public static function isOff(Event $event): bool
    {
        $off = self::off();

        return $off !== [] && in_array(UsageLedger::jobKey($event), $off, true);
    }

    public function set(string $key, bool $on): void
    {
        if (! $on && self::vital($key)) {
            throw new InvalidArgumentException('Bu iş sistemin kendisini ayakta tutuyor; kapatılamaz.');
        }
        $known = array_column($this->all(), 'key');
        if (! in_array($key, $known, true)) {
            throw new InvalidArgumentException('Böyle bir zamanlanmış iş yok.');
        }
        $off = array_values(array_unique($on ? array_diff(self::off(), [$key]) : [...self::off(), $key]));
        $settings = AgencySetting::query()->first() ?? new AgencySetting;
        $settings->forceFill(['jobs_off' => json_encode($off)])->save();
        Cache::forget(self::CACHE);
    }

    /** "5 dakikada bir", "her gün 03:30", "pazartesi 09:00", "ayın 1'i 09:00"; anything else is shown as it is. */
    public static function cadence(string $cron): string
    {
        $parts = preg_split('/\s+/', trim($cron)) ?: [];
        if (count($parts) !== 5) {
            return $cron;
        }
        [$minute, $hour, $day, $month, $weekday] = $parts;
        $time = ctype_digit($minute) && ctype_digit($hour) ? sprintf('%02d:%02d', (int) $hour, (int) $minute) : null;
        $days = ['pazar', 'pazartesi', 'salı', 'çarşamba', 'perşembe', 'cuma', 'cumartesi', 'pazar'];

        return match (true) {
            $cron === '* * * * *' => 'dakikada bir',
            preg_match('#^\*/(\d+)$#', $minute, $m) === 1 && $hour === '*' && $day === '*' && $weekday === '*' => $m[1].' dakikada bir',
            ctype_digit($minute) && $hour === '*' && $day === '*' && $weekday === '*' => 'saatte bir',
            ctype_digit($minute) && preg_match('#^\*/(\d+)$#', $hour, $m) === 1 && $day === '*' && $weekday === '*' => $m[1].' saatte bir',
            $time !== null && $day === '*' && $month === '*' && $weekday === '*' => 'her gün '.$time,
            $time !== null && $day === '*' && $month === '*' && ctype_digit($weekday) => 'her '.$days[(int) $weekday].' '.$time,
            $time !== null && $day === '*' && $month === '*' && $weekday === '1-5' => 'hafta içi '.$time,
            $time !== null && ctype_digit($day) && $month === '*' && $weekday === '*' => 'ayın '.$day.'. günü '.$time,
            ctype_digit($minute) && str_contains($hour, ',') && $day === '*' && $weekday === '*' => 'günde '.count(explode(',', $hour)).' kez',
            default => $cron,
        };
    }

    /** @return list<Event> */
    private function events(): array
    {
        try {
            app(Kernel::class)->all(); // loads routes/console.php (the schedule) outside the console too
        } catch (Throwable) {
            // the schedule stays as registered
        }

        return array_values(app(Schedule::class)->events());
    }

    /** @return array<string, object> */
    private function usage(): array
    {
        if (! SchemaState::hasTable('usage_daily')) {
            return [];
        }

        return DB::table('usage_daily')->where('kind', UsageLedger::JOB)->where('day', '>=', now('Europe/Istanbul')->subDays(30)->toDateString())
            ->groupBy('key')->selectRaw('key, sum(count) as runs, sum(failures) as failures, sum(total_ms) as ms, max(last_at) as last_at')->get()->keyBy('key')->all();
    }

    /** @return array<string, string> artisan command name => its one-line description */
    private function descriptions(): array
    {
        try {
            return collect(Artisan::all())->map(fn ($command): string => (string) $command->getDescription())->all();
        } catch (Throwable) {
            return [];
        }
    }
}
