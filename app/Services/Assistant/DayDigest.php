<?php

namespace App\Services\Assistant;

use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\User;
use App\Services\Operator\Agenda;
use App\Services\Sales\WeekReview;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sabah planı ve akşam kapanışı (Günlük Düzen 3.2): the two summaries of the day. The morning one says what today
 * holds in at most five lines (the agenda in one line, then what waited for the summary); the evening one says what
 * was finished, who answered, and the first job of tomorrow. Everything PushPolicy held is told here once.
 */
final class DayDigest
{
    public const string KEY = 'gunluk-ozet:';

    public const int LINES = 5;

    private const string TZ = 'Europe/Istanbul';

    public function __construct(private readonly PushNotifier $push, private readonly Agenda $agenda) {}

    public function morning(): int
    {
        $lines = [];
        try {
            $all = $this->agenda->today($this->operatorId());
            $kinds = collect($all['rows'])->countBy('kind');
            $people = collect($all['rows'])->firstWhere('kind', 'people');
            $tasks = (int) ($kinds['task'] ?? 0) + $all['hidden'];
            $parts = array_filter([
                ($kinds['meeting'] ?? 0) > 0 ? $kinds['meeting'].' toplantı' : null,
                $people !== null ? 'yazılacak '.trim(str_replace('Bugün', '', (string) $people['title'])) : null,
                ($kinds['brand'] ?? 0) > 0 ? $kinds['brand'].' marka onayı' : null,
                $tasks > 0 ? $tasks.' iş' : null,
            ]);
            $lines[] = $parts !== [] ? 'Bugün: '.implode(', ', $parts).'.' : 'Bugün gündem boş.';
        } catch (Throwable $error) {
            report($error);
        }

        return $this->tell('sabah', 'Sabah planı', $lines);
    }

    public function evening(): int
    {
        $now = CarbonImmutable::now(self::TZ);
        $since = $now->startOfDay()->utc();
        $lines = [];
        try {
            $done = Reminder::query()->where('user_id', $this->operatorId())->whereNotNull('done_at')->where('done_at', '>=', $since)->count();
            $lines[] = $done > 0 ? 'Bugün '.$done.' işini bitirdin.' : 'Bugün kapatılan iş yok.';
            $answered = SalesLead::query()->where('last_incoming_at', '>=', $since)->orderByDesc('last_incoming_at')->limit(20)->get();
            if ($answered->isNotEmpty()) {
                $lines[] = 'Yazanlar: '.$answered->take(3)->map(fn (SalesLead $lead): string => (string) $lead->title())->implode(', ')
                    .($answered->count() > 3 ? ' ve '.($answered->count() - 3).' kişi daha' : '').'.';
            }
            $first = Reminder::query()->where('user_id', $this->operatorId())->whereNull('done_at')->whereIn('kind', ['task', 'meeting'])
                ->whereBetween('remind_at', [$now->addDay()->startOfDay()->utc(), $now->addDay()->endOfDay()->utc()])->orderBy('remind_at')->first();
            // Haftalık 10 dakika (Satış Düzeni 4.2): on Friday the week's sales in one line; the card is on Satış › Rapor.
            if ($now->isFriday() && ($week = app(WeekReview::class)->week($now)['line']) !== '') {
                array_unshift($lines, $week);
            }
            if ($first !== null) {
                $lines[] = 'Yarın ilk iş: '.CarbonImmutable::instance($first->remind_at)->setTimezone(self::TZ)->format('H:i').' '.$first->title.'.';
            }
        } catch (Throwable $error) {
            report($error);
        }

        return $this->tell('aksam', 'Akşam kapanışı', $lines);
    }

    /** One push: the given lines, then what was held (newest first), five lines in all; the held rows are closed. */
    private function tell(string $part, string $title, array $lines): int
    {
        $held = DB::table('push_notifications')->where('status', 'held')->orderByDesc('id')->limit(200)->get(['id', 'title']);
        $room = max(0, self::LINES - count($lines) - ($held->count() > self::LINES - count($lines) ? 1 : 0));
        foreach ($held->take($room) as $row) {
            $lines[] = (string) $row->title;
        }
        if ($held->count() > $room) {
            $lines[] = 'Bekleyen '.($held->count() - $room).' bildirim daha panelde.';
        }
        if ($lines === []) {
            return 0;
        }
        $sent = $this->push->send(self::KEY.$part.':'.CarbonImmutable::now(self::TZ)->toDateString(), $title,
            implode("\n", array_map(fn (string $line): string => '• '.$line, $lines)), 'high', route('operator.dashboard'), 20, true);
        if ($held->isNotEmpty()) {
            DB::table('push_notifications')->whereIn('id', $held->pluck('id')->all())->update(['status' => 'told']);
        }

        return $sent;
    }

    private function operatorId(): int
    {
        return (int) (User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->value('id') ?? 0);
    }
}
