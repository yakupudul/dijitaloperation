<?php

namespace App\Panel\Services;

use App\Panel\Models\PanelEvent;
use App\Panel\Support\PanelSources;
use App\Panel\Support\PanelStages;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Performans › Satış: what became of the people who arrived in a period. Counted from the records, nothing estimated. */
final class PanelPerformance
{
    private const array RANK = [PanelStages::NEW => 0, PanelStages::REACHED => 1, PanelStages::APPOINTMENT => 2, PanelStages::CAME => 3, PanelStages::CUSTOMER => 4];

    /**
     * @param  Builder  $people  the people this user may see
     * @return array<string, mixed>
     */
    public function sales(Builder $people, CarbonInterface $from, CarbonInterface $to): array
    {
        $rows = $people->where('created_at', '>=', $from)->where('created_at', '<', $to)->where('stage', '!=', PanelStages::NOT_LEAD)
            ->orderByDesc('id')->limit(20000)->get(['id', 'stage', 'lost_reason', 'source', 'interest', 'assigned_user_id', 'created_at', 'import_id']);
        $rank = [];
        $firstAnswer = [];
        foreach ($rows->pluck('id')->chunk(1000) as $ids) {
            $events = PanelEvent::query()->whereIn('person_id', $ids)->whereIn('type', [PanelEvent::STAGE, PanelEvent::NOTE, PanelEvent::CALL])->whereNotNull('panel_user_id')
                ->orderBy('occurred_at')->get(['person_id', 'type', 'meta', 'occurred_at']);
            foreach ($events as $event) {
                $firstAnswer[$event->person_id] ??= $event->occurred_at;
                if ($event->type === PanelEvent::STAGE) {
                    foreach ([$event->meta['to'] ?? null, $event->meta['from'] ?? null] as $stage) {
                        $rank[$event->person_id] = max($rank[$event->person_id] ?? 0, self::RANK[$stage] ?? 0);
                    }
                }
            }
        }
        $reached = fn ($person): int => max($rank[$person->id] ?? 0, self::RANK[$person->stage] ?? 0);
        $count = fn (Collection $set, int $step): int => $set->filter(fn ($person): bool => $reached($person) >= $step)->count();
        $total = $rows->count();

        $funnel = [];
        foreach (['Aday', 'Ulaşıldı', 'Randevu verildi', 'Geldi', 'Müşteri oldu'] as $step => $label) {
            $value = $step === 0 ? $total : $count($rows, $step);
            $funnel[] = ['label' => $label, 'value' => $value, 'share' => $total > 0 ? (int) round($value * 100 / $total) : 0];
        }
        $breakdown = fn (Collection $groups): array => $groups->map(fn (Collection $set, string $label): array => [
            'label' => $label, 'leads' => $set->count(), 'appointments' => $count($set, 2), 'customers' => $count($set, 4),
            'rate' => $set->count() > 0 ? (int) round($count($set, 4) * 100 / $set->count()) : 0,
        ])->sortByDesc('leads')->values()->all();

        $minutes = $rows->filter(fn ($person): bool => $person->import_id === null && isset($firstAnswer[$person->id]))
            ->map(fn ($person): int => max(0, (int) $person->created_at->diffInMinutes($firstAnswer[$person->id])))->sort()->values();
        $median = fn (Collection $sorted): ?int => $sorted->isEmpty() ? null : (int) $sorted[intdiv($sorted->count() - 1, 2)];

        return [
            'total' => $total,
            'appointments' => $count($rows, 2),
            'customers' => $count($rows, 4),
            'response' => $median($minutes),
            'unanswered' => $rows->filter(fn ($person): bool => $person->stage === PanelStages::NEW && ! isset($firstAnswer[$person->id]))->count(),
            'funnel' => $funnel,
            'sources' => $breakdown($rows->groupBy(fn ($person): string => PanelSources::label($person->source))),
            'interests' => array_slice($breakdown($rows->groupBy(fn ($person): string => trim((string) $person->interest) !== '' ? (string) $person->interest : 'Belirtilmemiş')), 0, 12),
            'lost' => $rows->where('stage', PanelStages::LOST)->groupBy(fn ($person): string => isset(PanelStages::lostReasons()[$person->lost_reason]) ? (string) $person->lost_reason : 'none')
                ->map(fn (Collection $set, string $key): array => ['key' => $key, 'label' => PanelStages::lostReasons()[$key] ?? 'Neden yazılmamış', 'value' => $set->count()])->sortByDesc('value')->values()->all(),
            'lost_total' => $rows->where('stage', PanelStages::LOST)->count(),
            'team' => $rows->groupBy(fn ($person): int => (int) $person->assigned_user_id)->map(fn (Collection $set, int $userId): array => [
                'user' => $userId, 'leads' => $set->count(), 'appointments' => $count($set, 2), 'customers' => $count($set, 4),
                'waiting' => $set->where('stage', PanelStages::NEW)->count(),
                'response' => $median($set->filter(fn ($person): bool => $person->import_id === null && isset($firstAnswer[$person->id]))
                    ->map(fn ($person): int => max(0, (int) $person->created_at->diffInMinutes($firstAnswer[$person->id])))->sort()->values()),
            ])->sortByDesc('leads')->values()->all(),
        ];
    }

    public static function duration(?int $minutes): string
    {
        return match (true) {
            $minutes === null => '—',
            $minutes < 1 => '1 dk içinde',
            $minutes < 60 => $minutes.' dk',
            $minutes < 1440 => intdiv($minutes, 60).' sa'.($minutes % 60 >= 5 ? ' '.($minutes % 60).' dk' : ''),
            default => intdiv($minutes, 1440).' gün'.(intdiv($minutes % 1440, 60) > 0 ? ' '.intdiv($minutes % 1440, 60).' sa' : ''),
        };
    }
}
