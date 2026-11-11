<?php

namespace App\Services\Operator;

use App\Models\BrandFinding;
use App\Models\BrandSession;
use App\Models\Reminder;
use App\Services\Calendar\CalendarFeed;
use App\Services\Calendar\PromiseBook;
use App\Services\Sales\ClosingSteps;
use App\Services\Sales\TodayFive;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tek gündem (Günlük Düzen 2.1). Komuta opens with the one list of the day, at most seven lines, in the order the day
 * is worked: the operator's meetings, today's people (one line), the brands that wait for his approval (one line per
 * brand), his own tasks. Everything else on Komuta stands behind "daha fazla". When the list is empty the day is
 * done, and the screen says so. Read only: each line opens the place where the work is done.
 */
final class Agenda
{
    public const int MAX = 7;

    private const string TZ = 'Europe/Istanbul';

    public function __construct(private readonly CalendarFeed $calendar, private readonly TodayFive $five, private readonly ClosingSteps $closing) {}

    /**
     * @return array{rows: list<array{key: string, kind: string, when: ?string, title: string, line: string, url: string, late: bool}>, hidden: int, total: int}
     */
    public function today(int $userId): array
    {
        $rows = [...$this->part(fn (): array => $this->meetings($userId)), ...$this->part(fn (): array => $this->people()),
            ...$this->part(fn (): array => $this->brands()), ...$this->part(fn (): array => $this->promises($userId)), ...$this->part(fn (): array => $this->tasks($userId))];

        return ['rows' => array_slice($rows, 0, self::MAX), 'hidden' => max(0, count($rows) - self::MAX), 'total' => count($rows)];
    }

    /** One part failing never empties the day. */
    private function part(callable $read): array
    {
        try {
            return $read();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    /** Today's meetings that are not over yet, by hour. */
    private function meetings(int $userId): array
    {
        $day = CarbonImmutable::now(self::TZ);
        $out = [];
        foreach ($this->calendar->own($day->startOfDay(), $day->endOfDay(), $userId) as $row) {
            if ($row['kind'] !== 'meeting' || $row['done'] || ($row['over'] && filled($row['outcome']))) {
                continue;
            }
            $out[] = ['key' => 'meeting:'.$row['id'], 'kind' => 'meeting', 'when' => $row['time'], 'title' => $row['title'],
                'line' => $row['over'] ? 'Bitti, notu bekliyor' : trim(($row['lead'] ?? $row['brand'] ?? '').($row['place'] ? ' · '.$row['place'] : ''), ' ·'),
                'url' => route('operator.calendar'), 'late' => false];
        }

        return $out;
    }

    /** Today's people, one line. */
    private function people(): array
    {
        // Said yes, not set up yet (Satış Düzeni 3.1): nearest to money, so before the day's people.
        $closing = $this->closing->open()->take(2)->map(fn (array $row): array => ['key' => 'closing:'.$row['lead']->id, 'kind' => 'closing', 'when' => null,
            'title' => (string) $row['lead']->title(), 'line' => $row['line'], 'url' => route('operator.sales.lead', ['lead' => $row['lead']->id]), 'late' => false])->all();
        $deck = $this->five->deck();
        if ($deck->isEmpty()) {
            return $closing;
        }
        $names = $deck->take(3)->map(fn (array $row): string => (string) $row['lead']->title())->implode(', ');

        return [...$closing, ['key' => 'people', 'kind' => 'people', 'when' => null, 'title' => 'Bugün '.$deck->count().' kişi',
            'line' => $names.($deck->count() > 3 ? ' ve '.($deck->count() - 3).' kişi daha' : ''), 'url' => route('operator.sales'), 'late' => false]];
    }

    /** Brands that wait for the operator: a monthly session to approve, work waiting for his approval, findings to hand over. One line per brand. */
    private function brands(): array
    {
        if (! SchemaState::hasTable('brand_findings')) {
            return [];
        }
        $byBrand = [];
        BrandFinding::query()->with('brand:id,name')->where('kind', 'finding')->whereIn('status', ['open', 'waiting'])->orderBy('id')->get()
            ->each(function (BrandFinding $row) use (&$byBrand): void {
                $byBrand[$row->brand_id] ??= ['name' => (string) $row->brand?->name, 'open' => 0, 'waiting' => 0, 'session' => false];
                $byBrand[$row->brand_id][$row->status]++;
            });
        if (SchemaState::hasTable('brand_sessions')) {
            BrandSession::query()->with('brand:id,name')->where('status', 'draft')->orderBy('id')->get()->each(function (BrandSession $session) use (&$byBrand): void {
                $byBrand[$session->brand_id] ??= ['name' => (string) $session->brand?->name, 'open' => 0, 'waiting' => 0, 'session' => false];
                $byBrand[$session->brand_id]['session'] = true;
            });
        }
        $out = [];
        foreach ($byBrand as $brandId => $b) {
            $parts = array_filter([$b['session'] ? 'aylık oturum onayını bekliyor' : null, $b['waiting'] > 0 ? $b['waiting'].' iş onayını bekliyor' : null,
                $b['open'] > 0 ? $b['open'].' bulgu çözülmeyi bekliyor' : null]);
            $out[] = ['key' => 'brand:'.$brandId, 'kind' => 'brand', 'when' => null, 'title' => $b['name'], 'line' => ucfirst(implode(', ', $parts)),
                'url' => route('operator.brand', ['brand' => $brandId]), 'late' => false,
                'weight' => ($b['session'] ? 100000 : 0) + $b['waiting'] * 1000 + $b['open']];
        }
        usort($out, fn (array $a, array $b): int => [$b['weight'], $a['title']] <=> [$a['weight'], $b['title']]);

        return array_map(fn (array $row): array => array_diff_key($row, ['weight' => 0]), $out);
    }

    /** Söz defteri (Günlük Düzen 4.2): promises caught in his own messages that wait for "işe ekle?", one line. */
    private function promises(int $userId): array
    {
        $asked = app(PromiseBook::class)->asked($userId);
        if ($asked->isEmpty()) {
            return [];
        }
        $first = $asked->first();

        return [['key' => 'promises', 'kind' => 'promise', 'when' => null, 'title' => $asked->count().' söz yakalandı',
            'line' => Str::limit((filled($first->who) ? $first->who.': ' : '').$first->text, 90).($asked->count() > 1 ? ' ve '.($asked->count() - 1).' söz daha' : ''),
            'url' => route('operator.calendar').'#sozler', 'late' => false]];
    }

    /** The operator's own tasks: today's, then the ones left from earlier days and the ones without a date. */
    private function tasks(int $userId): array
    {
        $day = CarbonImmutable::now(self::TZ);
        $out = [];
        foreach ($this->calendar->own($day->startOfDay(), $day->endOfDay(), $userId) as $row) {
            if ($row['kind'] === 'task' && ! $row['done']) {
                $out['task:'.$row['id']] = ['key' => 'task:'.$row['id'], 'kind' => 'task', 'when' => $row['time'], 'title' => $row['title'],
                    'line' => (string) ($row['lead'] ?? $row['brand'] ?? ''), 'url' => route('operator.calendar'), 'late' => false];
            }
        }
        foreach (Reminder::query()->with(['brand:id,name'])->openTodos($userId)->orderByRaw('remind_at is null')->orderBy('remind_at')->orderBy('id')->limit(20)->get() as $todo) {
            $late = $todo->remind_at !== null;
            $out['task:'.$todo->id] ??= ['key' => 'task:'.$todo->id, 'kind' => 'task', 'when' => null, 'title' => (string) $todo->title,
                'line' => trim(($late ? CarbonImmutable::instance($todo->remind_at)->setTimezone(self::TZ)->locale('tr')->isoFormat('D MMMM').' gününden kaldı' : '')
                    .($todo->brand?->name ? ' · '.$todo->brand->name : ''), ' ·'), 'url' => route('operator.calendar'), 'late' => $late];
        }

        return array_values($out);
    }
}
