<?php

namespace App\Services\Sales;

use App\Models\Customer;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Satış 4. hafta: the month in numbers (what came, how far it got, what was won and lost, how fast we answered) and,
 * across all closed cards, what the wins and losses have in common. These are counts, never rules: a pattern is not
 * read as a rule before RULES_AFTER_WINS wins (yakup's decision), and a rate is shown only for groups of MIN_GROUP+.
 */
final class SalesMonthReport
{
    public const string TZ = 'Europe/Istanbul';

    public const int RULES_AFTER_WINS = 30;

    public const int MIN_GROUP = 5;

    /** The funnel steps shown, each counted as "got at least this far". */
    public const array FUNNEL = ['contacted' => 'İlk mesaj', 'talking' => 'Konuşuldu', 'meeting' => 'Görüşme', 'priced' => 'Teklif', 'won' => 'Kazanıldı'];

    /**
     * @return array{month: string, opened: int, by_source: array<string, int>, by_heard: array<string, int>, funnel: array<string, int>,
     *     won: int, won_names: list<array{id: int, title: string, fee: ?float}>, revenue: float, days_to_win: ?float, lost: int,
     *     lost_reasons: array<string, int>, meetings: int, meetings_done: int, reply_hours: ?float, replied: int, open_now: int}
     */
    public function month(CarbonImmutable $month): array
    {
        $from = $month->setTimezone(self::TZ)->startOfMonth();
        $to = $from->endOfMonth();
        $utc = [$from->utc(), $to->utc()];

        $opened = SalesLead::query()->relevant()->whereBetween('created_at', $utc)->get(['id', 'source', 'heard_from', 'stage', 'peak_stage', 'created_at']);
        $funnel = [];
        foreach (array_keys(self::FUNNEL) as $step) {
            $funnel[$step] = $opened->filter(fn (SalesLead $lead): bool => self::reached($lead, $step))->count();
        }

        $won = SalesLead::query()->whereBetween('won_at', $utc)->get(['id', 'name', 'company', 'website_host', 'phone', 'customer_id', 'created_at', 'won_at']);
        $fees = Customer::query()->whereIn('id', $won->pluck('customer_id')->filter()->all())->pluck('monthly_fee', 'id');
        $lost = SalesLead::query()->relevant()->whereBetween('lost_at', $utc)->get(['id', 'lost_reason']);
        $meetings = Reminder::query()->where('kind', 'meeting')->whereNotNull('sales_lead_id')->whereBetween('remind_at', $utc)->get(['id', 'remind_at', 'outcome', 'done_at']);

        // How fast the first message went out after a card was opened (messages imported from before the card are skipped).
        $firstOut = SalesLeadEvent::query()->whereIn('lead_id', $opened->pluck('id')->all())->where('kind', 'message_out')
            ->selectRaw('lead_id, min(happened_at) as first_at')->groupBy('lead_id')->pluck('first_at', 'lead_id');
        $hours = [];
        foreach ($opened as $lead) {
            if (isset($firstOut[$lead->id])) {
                $at = CarbonImmutable::parse((string) $firstOut[$lead->id], 'UTC');
                if ($at->gte(CarbonImmutable::instance($lead->created_at)->subMinutes(1))) {
                    $hours[] = max(0, CarbonImmutable::instance($lead->created_at)->diffInMinutes($at)) / 60;
                }
            }
        }

        return [
            'month' => $from->format('Y-m'),
            'opened' => $opened->count(),
            'by_source' => self::countBy($opened, 'source'),
            'by_heard' => self::countBy($opened->filter(fn (SalesLead $l): bool => filled($l->heard_from)), 'heard_from'),
            'funnel' => $funnel,
            'won' => $won->count(),
            'won_names' => $won->map(fn (SalesLead $l): array => ['id' => (int) $l->id, 'title' => $l->title(), 'fee' => isset($fees[$l->customer_id]) ? (float) $fees[$l->customer_id] : null])->values()->all(),
            'revenue' => (float) $won->sum(fn (SalesLead $l): float => (float) ($fees[$l->customer_id] ?? 0)),
            'days_to_win' => self::median($won->map(fn (SalesLead $l): float => CarbonImmutable::instance($l->created_at)->diffInHours(CarbonImmutable::instance($l->won_at)) / 24)->all()),
            'lost' => $lost->count(),
            'lost_reasons' => self::countBy($lost->filter(fn (SalesLead $l): bool => filled($l->lost_reason)), 'lost_reason'),
            'meetings' => $meetings->count(),
            'meetings_done' => $meetings->filter(fn (Reminder $r): bool => $r->done_at !== null || filled($r->outcome))->count(),
            'reply_hours' => self::median($hours),
            'replied' => count($hours),
            'open_now' => SalesLead::query()->open()->count(),
        ];
    }

    /**
     * What the closed cards (won or lost) have in common, all time: by channel, by where they heard of us, by branch, and
     * how far the lost ones got. Rates only for groups of MIN_GROUP+.
     *
     * @return array{wins: int, losses: int, ready: bool, groups: array<string, list<array{key: string, label: string, won: int, lost: int, rate: ?int}>>, lost_at: array<string, int>, lost_reasons: array<string, int>}
     */
    public function patterns(): array
    {
        $closed = SalesLead::query()->relevant()->whereIn('stage', ['won', 'lost'])->get(['id', 'stage', 'source', 'heard_from', 'branch', 'peak_stage', 'lost_reason']);
        $wins = $closed->where('stage', 'won')->count();
        $group = function (string $field, array $labels) use ($closed): array {
            return $closed->groupBy(fn (SalesLead $l): string => filled($l->{$field}) ? (string) $l->{$field} : '')
                ->map(function (Collection $rows, string $key) use ($labels): array {
                    $won = $rows->where('stage', 'won')->count();
                    $total = $rows->count();

                    return ['key' => $key, 'label' => $key === '' ? 'Bilinmiyor' : ($labels[$key] ?? mb_convert_case($key, MB_CASE_TITLE)),
                        'won' => $won, 'lost' => $total - $won, 'rate' => $total >= self::MIN_GROUP ? (int) round($won * 100 / $total) : null];
                })->sortByDesc(fn (array $r): int => $r['won'] + $r['lost'])->take(8)->values()->all();
        };
        $lostRows = $closed->where('stage', 'lost');
        $lostAt = [];
        foreach (['new' => 'Hiç yazılmadan', 'contacted' => 'İlk mesajdan sonra', 'talking' => 'Konuşurken', 'meeting' => 'Görüşmeden sonra', 'priced' => 'Tekliften sonra', 'thinking' => 'Düşünürken'] as $stage => $label) {
            $n = $lostRows->filter(fn (SalesLead $l): bool => ($l->peak_stage ?? 'new') === $stage)->count();
            if ($n > 0) {
                $lostAt[$label] = $n;
            }
        }
        $reasons = [];
        foreach (self::countBy($lostRows->filter(fn (SalesLead $l): bool => filled($l->lost_reason)), 'lost_reason') as $key => $n) {
            $reasons[SalesLead::LOST_REASONS[$key] ?? $key] = $n;
        }

        return [
            'wins' => $wins,
            'losses' => $lostRows->count(),
            'ready' => $wins >= self::RULES_AFTER_WINS,
            'groups' => [
                'Kanal' => $group('source', SalesLead::SOURCES),
                'Bizi nereden duydu' => $group('heard_from', SalesLead::HEARD_FROM),
                'Branş' => $group('branch', []),
            ],
            'lost_at' => $lostAt,
            'lost_reasons' => $reasons,
        ];
    }

    /** A card got at least as far as $step (won counts as every step; a lost card as far as it got). */
    public static function reached(SalesLead $lead, string $step): bool
    {
        if ($lead->stage === 'won') {
            return true;
        }
        if ($step === 'won') {
            return false;
        }
        $want = array_search($step, SalesLead::FLOW, true);
        $positions = array_filter([array_search((string) $lead->stage, SalesLead::FLOW, true), array_search((string) $lead->peak_stage, SalesLead::FLOW, true)], fn ($p): bool => $p !== false);

        return $want !== false && $positions !== [] && max($positions) >= $want;
    }

    /** @return array<string, int> */
    private static function countBy(Collection $rows, string $field): array
    {
        return $rows->countBy(fn ($row): string => (string) $row->{$field})->sortDesc()->all();
    }

    /** @param  list<float>|array<int, float>  $values */
    private static function median(array $values): ?float
    {
        $values = array_values($values);
        if ($values === []) {
            return null;
        }
        sort($values);
        $mid = intdiv(count($values), 2);

        return count($values) % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
    }
}
