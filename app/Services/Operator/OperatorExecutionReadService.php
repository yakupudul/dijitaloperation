<?php

namespace App\Services\Operator;

use App\Models\Recommendation;
use App\Services\Recommendations\RecommendationReadService;
use App\Services\Work\WorkReadService;
use App\Support\Operator\OperatorClock;
use Illuminate\Support\Collection;

/**
 * Canonical operator execution composition.
 *
 * Reads only production Work/Recommendation/User data. No Demo catalog or
 * fixture fallback is allowed here: an empty database produces an empty view.
 */
final class OperatorExecutionReadService
{
    public function __construct(
        private readonly WorkReadService $work,
        private readonly RecommendationReadService $recommendations,
        private readonly OperatorUserDirectory $users,
    ) {}

    /** @return array<string, mixed> */
    public function dashboard(string $mode = 'my_work'): array
    {
        $mode = in_array($mode, ['my_work', 'agency'], true) ? $mode : 'my_work';
        $items = collect($this->work->workItems());
        $open = $this->openItems($items);
        $recommendations = collect($this->recommendations->forListPresentation());
        $awaitingDecision = $recommendations->whereIn('status', [
            Recommendation::STATUS_OPEN,
            'pending',
            'awaiting_decision',
        ])->count();
        $mine = $open->filter(fn (array $row): bool => $this->isMine($row));
        $operatorNow = OperatorClock::now(auth()->user());

        return [
            'mode' => $mode,
            'greeting' => match (true) {
                (int) $operatorNow->format('G') < 12 => __('operator.greetings.morning'),
                (int) $operatorNow->format('G') < 18 => __('operator.greetings.afternoon'),
                default => __('operator.greetings.evening'),
            },
            'date_label' => $operatorNow->locale(app()->getLocale())->translatedFormat('l, j F'),
            'subtitle' => __('operator.dashboard_exec.subtitle'),
            'today' => [
                ['label' => __('operator.dashboard_exec.due_today'), 'value' => $open->where('due_key', 'today')->count(), 'route' => 'operator.tasks', 'route_params' => ['view' => 'due_today'], 'tone' => 'warning'],
                ['label' => __('operator.dashboard_exec.overdue'), 'value' => $open->where('due_key', 'overdue')->count(), 'route' => 'operator.tasks', 'route_params' => ['view' => 'overdue'], 'tone' => 'error'],
                ['label' => __('operator.dashboard_exec.awaiting_decision'), 'value' => $awaitingDecision, 'route' => 'operator.recommendations', 'route_params' => [], 'tone' => 'info'],
                ['label' => __('operator.dashboard_exec.waiting_on_client'), 'value' => $open->where('waiting_on_client', true)->count(), 'route' => 'operator.tasks', 'route_params' => ['view' => 'waiting_on_client'], 'tone' => 'info'],
            ],
            'needs_attention' => $this->attention($open, $mode),
            'my_work' => $mine
                ->sortBy(fn (array $row): int => match ($row['due_key'] ?? '') {
                    'overdue' => 0,
                    'today' => 1,
                    'soon' => 2,
                    default => 3,
                })
                ->take(8)
                ->values()
                ->all(),
            'team_capacity' => $this->teamCapacity($open->values()->all()),
            'recurring_reviews_due' => $open
                ->where('type', 'recurring_review')
                ->whereIn('status', ['due', 'overdue', 'upcoming', 'scheduled', 'in_progress'])
                ->take(5)
                ->values()
                ->all(),
            'portfolio_focus' => [],
            'system_exceptions' => [],
            'recent_outcomes' => [],
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $items
     * @return array<string, mixed>
     */
    public function teamCapacity(?array $items = null): array
    {
        $rows = collect($items ?? $this->work->workItems());
        $open = $this->openItems($rows);
        $active = $open->count();
        $dueToday = $open->where('due_key', 'today')->count();
        $overdue = $open->where('due_key', 'overdue')->count();
        $plannedHours = $open->sum(fn (array $row): float => $this->effortToHours($row['effort'] ?? null));

        $label = match (true) {
            $overdue > 3 || $active > 10 => 'Overloaded',
            $overdue >= 2 || $active >= 7 => 'Heavy',
            $active >= 4 || $overdue >= 1 => 'Balanced',
            default => 'Light',
        };

        $members = collect($this->users->presentationMembers())
            ->filter(fn (array $member): bool => (bool) ($member['is_active'] ?? false))
            ->map(function (array $member) use ($open): array {
                $mine = $open->filter(fn (array $row): bool => (string) ($row['owner_id'] ?? '') === (string) ($member['id'] ?? ''));
                $overdue = $mine->where('due_key', 'overdue')->count();

                return [
                    'id' => (string) ($member['id'] ?? ''),
                    'name' => (string) ($member['name'] ?? ''),
                    'role' => (string) ($member['role_label'] ?? $member['role'] ?? ''),
                    'active' => $mine->count(),
                    'due_today' => $mine->where('due_key', 'today')->count(),
                    'overdue' => $overdue,
                    'label' => match (true) {
                        $overdue > 1 || $mine->count() > 5 => 'Heavy',
                        $mine->count() >= 3 => 'Balanced',
                        default => 'Light',
                    },
                ];
            })
            ->values()
            ->all();

        return [
            'active_count' => $active,
            'due_today' => $dueToday,
            'overdue' => $overdue,
            'planned_hours' => round($plannedHours, 1),
            'label' => $label,
            'thresholds' => [
                'light' => 'active ≤ 3 and overdue = 0',
                'balanced' => 'active 4–6 or overdue = 1',
                'heavy' => 'active 7–10 or overdue 2–3',
                'overloaded' => 'active > 10 or overdue > 3',
            ],
            'members' => $members,
        ];
    }

    /** @param array<string, mixed> $row */
    public function isMine(array $row): bool
    {
        $user = auth()->user();
        if ($user === null) {
            return false;
        }

        $ownerId = $row['owner_id'] ?? $row['assignee_id'] ?? null;
        if ($ownerId !== null && (string) $ownerId === (string) $user->id) {
            return true;
        }

        $owner = trim((string) ($row['owner'] ?? ''));

        return $owner !== '' && strcasecmp($owner, (string) $user->name) === 0;
    }

    /** @param Collection<int, array<string, mixed>> $items */
    private function openItems(Collection $items): Collection
    {
        return $items
            ->reject(fn (array $row): bool => in_array($row['status'] ?? '', [
                'completed', 'done', 'declined', 'skipped', 'dismissed', 'resolved', 'cancelled',
            ], true))
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function attention(Collection $items, string $mode): array
    {
        $limit = $mode === 'agency' ? 3 : 5;

        return $items
            ->filter(fn (array $row): bool => in_array($row['due_key'] ?? '', ['overdue', 'today'], true)
                || (bool) ($row['waiting_on_client'] ?? false)
                || (bool) ($row['qa_required'] ?? false))
            ->take($limit)
            ->map(fn (array $row): array => [
                'severity' => ($row['due_key'] ?? '') === 'overdue' ? 'high' : 'medium',
                'title' => (string) ($row['title'] ?? ''),
                'body' => ucfirst(str_replace('_', ' ', (string) ($row['type'] ?? 'work'))).' · '.($row['brand'] ?? ''),
                'evidence' => trim((string) ($row['owner'] ?? '')).' · '.($row['due'] ?? '—'),
                'source' => ucfirst(str_replace('_', ' ', (string) ($row['type'] ?? 'work'))),
                'asset_type' => $row['asset_type'] ?? null,
                'route' => $row['route'] ?? 'operator.tasks',
                'route_params' => $row['route_params'] ?? [],
                'action_label' => __('operator.actions.open'),
            ])
            ->values()
            ->all();
    }

    private function effortToHours(?string $effort): float
    {
        if (! is_string($effort) || trim($effort) === '') {
            return 0.0;
        }

        $value = strtolower(trim($effort));
        if (str_ends_with($value, 'm') && is_numeric(substr($value, 0, -1))) {
            return ((float) substr($value, 0, -1)) / 60;
        }
        if (str_ends_with($value, 'h') && is_numeric(substr($value, 0, -1))) {
            return (float) substr($value, 0, -1);
        }

        return 0.0;
    }
}
