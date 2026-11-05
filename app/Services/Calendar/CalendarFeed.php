<?php

namespace App\Services\Calendar;

use App\Models\Brand;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\Suggestion;
use App\Services\Operator\KomutaReader;
use App\Services\Site\SiteSuggestionTypes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Takvim (yakup 2026-11-05): one calendar that reads everything where it already lives. Only the operator's own tasks
 * and meetings are stored for it (reminders); the brands' work (articles, page fixes, Business Profile posts, review
 * replies, profile and ads changes) and the prospects' next steps are read at view time, so the calendar can never
 * disagree with the screen that did the work. A system item is green only when it was applied, red when it failed;
 * nobody ticks it by hand. Komuta's decisions are not copied: today shows one "Komuta: N karar" line.
 */
final class CalendarFeed
{
    public const string TZ = 'Europe/Istanbul';

    /** What the brands' work is counted as, in the calendar and the month report. */
    public const array KINDS = [
        'yazi' => 'yazı', 'guncelleme' => 'site güncellemesi', 'harita' => 'harita gönderisi', 'yorum' => 'yorum yanıtı',
        'profil' => 'profil güncellemesi', 'reklam' => 'reklam değişikliği',
    ];

    /** Writes counted, by kind. Drafts of page text (content_draft) precede content_apply and are not counted twice. */
    private const array ACTION_KIND = [
        ExternalWriteAction::ACTION_ARTICLE_DRAFTS => 'yazi', ExternalWriteAction::ACTION_DRAFT_CREATE => 'yazi',
        ExternalWriteAction::ACTION_SITE_FIX => 'guncelleme', ExternalWriteAction::ACTION_CONTENT_APPLY => 'guncelleme',
        ExternalWriteAction::ACTION_UPDATE_APPLY => 'guncelleme',
        ExternalWriteAction::ACTION_LOCAL_POST => 'harita', ExternalWriteAction::ACTION_REVIEW_REPLY => 'yorum',
        ExternalWriteAction::ACTION_PROFILE_UPDATE => 'profil', ExternalWriteAction::ACTION_PROFILE_FIELDS => 'profil',
        ExternalWriteAction::ACTION_MEDIA_UPLOAD => 'profil',
        ExternalWriteAction::ACTION_NEGATIVE_LIST_ADD => 'reklam', ExternalWriteAction::ACTION_ADS_CHANGE => 'reklam',
    ];

    private const array DONE = ['succeeded', 'partial'];

    /**
     * Every day of the range, ready for the month grid: own items (tasks and meetings), prospects' next steps, and the
     * brands' work as counts.
     *
     * @return array<string, array{own: list<array<string, mixed>>, sales: list<array<string, mixed>>, work: array{done: int, planned: int, failed: int, brands: int}}>
     */
    public function range(CarbonImmutable $from, CarbonImmutable $to, int $userId): array
    {
        $days = [];
        for ($d = $from->startOfDay(); $d->lte($to); $d = $d->addDay()) {
            $days[$d->toDateString()] = ['own' => [], 'sales' => [], 'work' => ['done' => 0, 'planned' => 0, 'failed' => 0, 'brands' => 0]];
        }
        foreach ($this->own($from, $to, $userId) as $item) {
            if (isset($days[$item['date']])) {
                $days[$item['date']]['own'][] = $item;
            }
        }
        foreach ($this->sales($from, $to) as $item) {
            if (isset($days[$item['date']])) {
                $days[$item['date']]['sales'][] = $item;
            }
        }
        foreach ($this->work($from, $to)->groupBy('date') as $date => $items) {
            if (! isset($days[$date])) {
                continue;
            }
            foreach (['done', 'planned', 'failed'] as $state) {
                $days[$date]['work'][$state] = (int) $items->where('state', $state)->sum('count');
            }
            $days[$date]['work']['brands'] = $items->pluck('brand_id')->unique()->count();
        }

        return $days;
    }

    /**
     * One day, for the day window: own tasks, meetings, prospects' next steps, and the brands' work grouped per brand
     * (each brand one line, its items counted by kind and state).
     *
     * @return array{tasks: list<array<string, mixed>>, meetings: list<array<string, mixed>>, sales: list<array<string, mixed>>, brands: list<array{brand_id: int, brand: string, done: int, planned: int, failed: int, items: list<array<string, mixed>>}>, komuta: ?int}
     */
    public function day(CarbonImmutable $day, int $userId): array
    {
        $from = $day->startOfDay();
        $to = $day->endOfDay();
        $own = collect($this->own($from, $to, $userId));
        $work = $this->work($from, $to);
        $names = Brand::query()->whereIn('id', $work->pluck('brand_id')->unique()->all())->pluck('name', 'id');
        $brands = $work->groupBy('brand_id')->map(fn (Collection $items, $brandId): array => [
            'brand_id' => (int) $brandId,
            'brand' => (string) ($names[$brandId] ?? 'Marka'),
            'done' => (int) $items->where('state', 'done')->sum('count'),
            'planned' => (int) $items->where('state', 'planned')->sum('count'),
            'failed' => (int) $items->where('state', 'failed')->sum('count'),
            'items' => $items->groupBy(fn (array $i): string => $i['kind'].'|'.$i['state'])->map(fn (Collection $g): array => [
                'kind' => $g->first()['kind'], 'label' => self::KINDS[$g->first()['kind']], 'state' => $g->first()['state'],
                'count' => (int) $g->sum('count'), 'titles' => $g->pluck('title')->filter()->unique()->take(5)->values()->all(),
                'error' => $g->pluck('error')->filter()->first(),
            ])->sortBy(fn (array $i): string => ['failed' => 0, 'done' => 1, 'planned' => 2][$i['state']].$i['kind'])->values()->all(),
        ])->sortBy(fn (array $b): string => ($b['failed'] > 0 ? '0' : '1').mb_strtolower($b['brand']))->values()->all();
        $today = CarbonImmutable::now(self::TZ)->toDateString() === $day->toDateString();

        return [
            'tasks' => $own->where('kind', 'task')->values()->all(),
            'meetings' => $own->where('kind', 'meeting')->values()->all(),
            'sales' => $this->sales($from, $to),
            'brands' => $brands,
            'komuta' => $today ? $this->komuta() : null,
        ];
    }

    /**
     * The month in numbers: per brand what was done (applied, never drafts or failures), plus own meetings and tasks and
     * the prospects that came, were won or lost.
     *
     * @return array{brands: list<array{brand_id: int, brand: string, counts: array<string, int>, total: int, failed: int}>, totals: array<string, int>, meetings: int, tasks: int, leads_new: int, leads_won: int, leads_lost: int}
     */
    public function month(CarbonImmutable $month, int $userId): array
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $work = $this->work($from, $to);
        $done = $work->where('state', 'done');
        $names = Brand::query()->whereIn('id', $work->pluck('brand_id')->unique()->all())->pluck('name', 'id');
        $brands = $work->groupBy('brand_id')->map(function (Collection $items, $brandId) use ($names): array {
            $counts = [];
            foreach (array_keys(self::KINDS) as $kind) {
                $counts[$kind] = (int) $items->where('state', 'done')->where('kind', $kind)->sum('count');
            }

            return ['brand_id' => (int) $brandId, 'brand' => (string) ($names[$brandId] ?? 'Marka'), 'counts' => $counts,
                'total' => array_sum($counts), 'failed' => (int) $items->where('state', 'failed')->sum('count')];
        })->filter(fn (array $b): bool => $b['total'] > 0 || $b['failed'] > 0)->sortByDesc('total')->values()->all();
        $totals = [];
        foreach (array_keys(self::KINDS) as $kind) {
            $totals[$kind] = (int) $done->where('kind', $kind)->sum('count');
        }
        $utc = [$from->utc(), $to->utc()];
        $own = Reminder::query()->where('user_id', $userId)->whereNotNull('done_at')->whereBetween('done_at', $utc);

        return [
            'brands' => $brands,
            'totals' => $totals,
            'meetings' => (clone $own)->where('kind', 'meeting')->count(),
            'tasks' => (clone $own)->where('kind', '!=', 'meeting')->count(),
            'leads_new' => SalesLead::query()->whereBetween('created_at', $utc)->count(),
            'leads_won' => SalesLead::query()->whereBetween('won_at', $utc)->count(),
            'leads_lost' => SalesLead::query()->whereBetween('lost_at', $utc)->count(),
        ];
    }

    /**
     * Own tasks and meetings in the range (repeating tasks shown on each date they fall on).
     *
     * @return list<array{id: int, kind: string, date: string, time: ?string, end: ?string, title: string, notes: ?string, place: ?string, done: bool, brand: ?string, lead_id: ?int, lead: ?string, outcome: ?string, over: bool, repeat: string}>
     */
    public function own(CarbonImmutable $from, CarbonImmutable $to, int $userId): array
    {
        $rows = Reminder::query()->with(['brand:id,name', 'salesLead:id,name,clinic,website_host,phone'])->where('user_id', $userId)
            ->where(fn ($q) => $q->whereBetween('remind_at', [$from->utc(), $to->utc()])
                ->orWhere(fn ($r) => $r->where('repeat', '!=', 'none')->whereNull('done_at')->where('remind_at', '<=', $to->utc())))
            ->orderBy('remind_at')->get();
        $now = CarbonImmutable::now(self::TZ);
        $out = [];
        foreach ($rows as $row) {
            $at = CarbonImmutable::instance($row->remind_at)->setTimezone(self::TZ);
            $guard = 0;
            while ($at->lte($to) && $guard++ < 400) {
                if ($at->gte($from)) {
                    $end = $row->ends_at !== null ? CarbonImmutable::instance($row->ends_at)->setTimezone(self::TZ)->setDate($at->year, $at->month, $at->day) : null;
                    $out[] = [
                        'id' => (int) $row->id, 'kind' => $row->kind === 'meeting' ? 'meeting' : 'task', 'date' => $at->toDateString(),
                        'time' => $row->all_day ? null : $at->format('H:i'), 'end' => $end?->format('H:i'),
                        'title' => (string) $row->title, 'notes' => $row->notes, 'place' => $row->place,
                        'done' => $row->done_at !== null, 'brand' => $row->brand?->name, 'lead_id' => $row->sales_lead_id !== null ? (int) $row->sales_lead_id : null,
                        'lead' => $row->salesLead?->title(), 'outcome' => $row->outcome, 'over' => ($end ?? $at)->lt($now), 'repeat' => (string) $row->repeat,
                    ];
                }
                $at = match ($row->repeat) {
                    'weekly' => $at->addWeek(),
                    'monthly' => $at->addMonthNoOverflow(),
                    'yearly' => $at->addYear(),
                    default => $to->addDay(),
                };
            }
        }

        return $out;
    }

    /**
     * Prospects' next steps. A step left undone in the past shows on today, marked late, so it is never lost.
     *
     * @return list<array{id: int, date: string, time: ?string, title: string, step: string, late: bool, url: string}>
     */
    public function sales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $includesToday = $today->betweenIncluded($from->startOfDay(), $to);
        $query = SalesLead::query()->open()->whereNotNull('next_step_at')
            ->where('next_step_at', '<=', $to->utc())
            ->where('next_step_at', '>=', $includesToday ? CarbonImmutable::create(2000) : $from->utc())
            ->orderBy('next_step_at')->limit(300);

        return $query->get()->map(function (SalesLead $lead) use ($today, $includesToday): array {
            $at = CarbonImmutable::instance($lead->next_step_at)->setTimezone(self::TZ);
            $late = $at->lt($today) && $includesToday;

            return [
                'id' => (int) $lead->id, 'date' => $late ? $today->toDateString() : $at->toDateString(), 'time' => $at->format('H:i') !== '00:00' && ! $late ? $at->format('H:i') : null,
                'title' => $lead->title(), 'step' => (string) ($lead->next_step ?: 'Yaz'), 'late' => $late,
                'url' => route('operator.sales.lead', $lead->id),
            ];
        })->values()->all();
    }

    /**
     * The brands' work in the range, one row per write or planned item: date (Istanbul), brand, kind, state
     * (done | planned | failed), count.
     *
     * @return Collection<int, array{date: string, brand_id: int, kind: string, state: string, count: int, title: ?string, error: ?string}>
     */
    public function work(CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $rows = collect();
        $today = CarbonImmutable::now(self::TZ)->toDateString();
        $range = [$from->startOfDay(), $to];

        // Articles the content plan dated: the article's day is its publish day, whether it was sent ahead or not.
        $articleWrites = [];
        try {
            $planned = Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)
                ->whereNotNull('action->autopilot')->where('status', '!=', Suggestion::DISMISSED)
                ->get(['id', 'brand_id', 'title', 'action', 'status']);
            $writeIds = $planned->map(fn (Suggestion $s): int => (int) data_get($s->action, 'article_write_id', 0))->filter()->all();
            $writes = ExternalWriteAction::query()->whereIn('id', $writeIds)->get(['id', 'status', 'error'])->keyBy('id');
            foreach ($planned as $s) {
                $date = data_get($s->action, 'autopilot.date');
                if (! is_string($date) || $s->brand_id === null) {
                    continue;
                }
                $day = CarbonImmutable::parse(substr($date, 0, 10), self::TZ);
                if ($day->lt($range[0]->startOfDay()) || $day->gt($range[1])) {
                    continue;
                }
                $writeId = (int) data_get($s->action, 'article_write_id', 0);
                $write = $writeId > 0 ? $writes->get($writeId) : null;
                if ($writeId > 0) {
                    $articleWrites[] = $writeId;
                }
                $state = match (true) {
                    $write !== null && in_array($write->status, self::DONE, true) => $day->toDateString() <= $today ? 'done' : 'planned',
                    $write !== null && $write->status === 'failed' => 'failed',
                    $day->toDateString() < $today => 'failed',
                    default => 'planned',
                };
                $rows->push(['date' => $day->toDateString(), 'brand_id' => (int) $s->brand_id, 'kind' => 'yazi', 'state' => $state, 'count' => 1,
                    'title' => (string) $s->title, 'error' => $state === 'failed' ? ($write?->error ? (string) $write->error : 'Planlanan günde gönderilmedi') : null]);
            }
        } catch (Throwable $error) {
            report($error);
        }

        // Applied or failed writes (page fixes, posts, replies, profile and ads changes, articles sent by hand).
        ExternalWriteAction::query()->whereIn('action', array_keys(self::ACTION_KIND))->whereIn('status', [...self::DONE, 'failed'])
            ->whereNotNull('brand_id')->whereNotIn('id', $articleWrites)
            ->where(fn ($q) => $q->whereBetween('finished_at', [$range[0]->utc(), $range[1]->utc()])
                ->orWhere(fn ($r) => $r->whereNull('finished_at')->whereBetween('created_at', [$range[0]->utc(), $range[1]->utc()])))
            ->get(['id', 'brand_id', 'action', 'status', 'error', 'request_payload', 'finished_at', 'created_at'])
            ->each(function (ExternalWriteAction $w) use ($rows): void {
                $at = CarbonImmutable::instance($w->finished_at ?? $w->created_at)->setTimezone(self::TZ);
                $failed = $w->status === 'failed';
                $rows->push(['date' => $at->toDateString(), 'brand_id' => (int) $w->brand_id, 'kind' => self::ACTION_KIND[$w->action], 'state' => $failed ? 'failed' : 'done',
                    'count' => 1, 'title' => self::writeTitle($w), 'error' => $failed ? mb_substr((string) $w->error, 0, 200) : null]);
            });

        // Business Profile posts still to go out (published ones are the local_post writes above), and ones that never left.
        GbpQueuedPost::query()->whereNotNull('brand_id')->whereBetween('publish_on', [$range[0]->toDateString(), $range[1]->toDateString()])
            ->where(fn ($q) => $q->where(fn ($p) => $p->whereIn('status', [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED])->where('publish_on', '>=', $today))
                ->orWhere(fn ($f) => $f->where('status', GbpQueuedPost::FAILED)->whereNull('external_write_action_id')))
            ->get(['id', 'brand_id', 'publish_on', 'status', 'summary', 'note'])
            ->each(function (GbpQueuedPost $p) use ($rows): void {
                $failed = $p->status === GbpQueuedPost::FAILED;
                $rows->push(['date' => substr((string) $p->publish_on, 0, 10), 'brand_id' => (int) $p->brand_id, 'kind' => 'harita', 'state' => $failed ? 'failed' : 'planned',
                    'count' => 1, 'title' => mb_substr(trim((string) $p->summary), 0, 80), 'error' => $failed ? ((string) $p->note ?: 'Yayınlanamadı') : null]);
            });

        return $rows;
    }

    /** Komuta's open decisions for today's line; read at most every 5 minutes, failure-safe. */
    public function komuta(): ?int
    {
        try {
            return (int) Cache::remember('calendar.komuta', 300, fn (): int => (int) app(KomutaReader::class)->box()['decisions']);
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }

    private static function writeTitle(ExternalWriteAction $w): ?string
    {
        $label = data_get($w->request_payload, 'label') ?? data_get($w->request_payload, 'title') ?? data_get($w->request_payload, 'drafts.0.draft.title');

        return is_string($label) && $label !== '' ? mb_substr($label, 0, 90) : null;
    }
}
