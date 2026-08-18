<?php

namespace App\Services\RecurringReviews;

use App\Enums\RecurringReviewRunStatus;
use App\Enums\RecurringReviewScheduleStatus;
use App\Models\RecurringReviewRun;
use App\Models\RecurringReviewSchedule;
use App\Support\Work\WorkUrl;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Production Recurring Review reads. DB only — never materializes Runs on read.
 */
final class RecurringReviewReadService
{
    /**
     * @param  array{
     *     customer_id?: int|null,
     *     brand_id?: int|null,
     *     digital_asset_id?: int|null,
     *     status?: string|null,
     *     playbook_id?: int|null,
     * }  $filters
     * @return list<array<string, mixed>>
     */
    public function scheduleList(array $filters = [], int $limit = 200): array
    {
        $query = RecurringReviewSchedule::query()->with([
            'customer:id,name',
            'brand:id,name',
            'digitalAsset:id,name,type',
            'playbook:id,stable_key,status,current_revision_id',
            'playbook.currentRevision:id,title,cadence',
            'owner:id,name',
            'defaultReviewer:id,name',
            'checkDefinitions',
        ]);

        $this->applyScheduleFilters($query, $filters);

        return $query
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (RecurringReviewSchedule $schedule): array => $this->schedulePresentation($schedule))
            ->all();
    }

    /**
     * Active schedules whose next_due_at is due. Does NOT materialize runs.
     *
     * @return list<array<string, mixed>>
     */
    public function dueSchedules(?\DateTimeInterface $now = null, int $limit = 200): array
    {
        $now = $now ?? now();

        return RecurringReviewSchedule::query()
            ->with([
                'customer:id,name',
                'brand:id,name',
                'digitalAsset:id,name,type',
                'playbook:id,stable_key,status,current_revision_id',
                'playbook.currentRevision:id,title,cadence',
                'owner:id,name',
            ])
            ->where('status', RecurringReviewScheduleStatus::Active->value)
            ->whereNotNull('next_due_at')
            ->where('next_due_at', '<=', $now)
            ->orderBy('next_due_at')
            ->limit($limit)
            ->get()
            ->map(fn (RecurringReviewSchedule $schedule): array => $this->schedulePresentation($schedule))
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function runDetail(int $runId): ?array
    {
        $run = RecurringReviewRun::query()
            ->with([
                'schedule',
                'playbook:id,stable_key,status',
                'playbookRevision:id,title,revision_number',
                'customer:id,name',
                'brand:id,name',
                'digitalAsset:id,name,type',
                'reviewer:id,name',
                'items.checkDefinition',
                'items.finding:id,title,status',
                'items.opportunity:id,title,status',
                'items.task:id,title,status',
            ])
            ->whereKey($runId)
            ->first();

        if (! $run instanceof RecurringReviewRun) {
            return null;
        }

        return $this->runPresentation($run);
    }

    /**
     * Work aggregate rows for scheduled / in_progress / due runs. Passive only; no materialization.
     *
     * @return list<array<string, mixed>>
     */
    public function forWorkItemPresentation(int $limit = 200): array
    {
        $now = now();

        $runs = RecurringReviewRun::query()
            ->with([
                'customer:id,name',
                'brand:id,name',
                'digitalAsset:id,name,type',
                'reviewer:id,name',
                'playbook:id,stable_key',
                'playbookRevision:id,title',
                'schedule:id,owner_user_id,default_reviewer_user_id,playbook_id',
                'schedule.owner:id,name',
            ])
            ->whereIn('status', [
                RecurringReviewRunStatus::Scheduled->value,
                RecurringReviewRunStatus::InProgress->value,
            ])
            ->orderBy('due_at')
            ->limit($limit)
            ->get();

        return $runs
            ->map(fn (RecurringReviewRun $run): array => $this->toWorkItem($run, $now))
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function forBrandPresentation(int $brandId, int $limit = 100): array
    {
        $now = now();

        return RecurringReviewRun::query()
            ->with([
                'customer:id,name',
                'brand:id,name',
                'digitalAsset:id,name,type',
                'reviewer:id,name',
                'playbook:id,stable_key',
                'playbookRevision:id,title',
                'schedule:id,owner_user_id,default_reviewer_user_id,playbook_id',
                'schedule.owner:id,name',
            ])
            ->where('brand_id', $brandId)
            ->whereIn('status', [
                RecurringReviewRunStatus::Scheduled->value,
                RecurringReviewRunStatus::InProgress->value,
                RecurringReviewRunStatus::Completed->value,
            ])
            ->orderByDesc('due_at')
            ->limit($limit)
            ->get()
            ->map(fn (RecurringReviewRun $run): array => $this->toWorkItem($run, $now))
            ->all();
    }

    /**
     * @param  Builder<RecurringReviewSchedule>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyScheduleFilters(Builder $query, array $filters): void
    {
        if (! empty($filters['customer_id'])) {
            $query->where('customer_id', (int) $filters['customer_id']);
        }
        if (! empty($filters['brand_id'])) {
            $query->where('brand_id', (int) $filters['brand_id']);
        }
        if (! empty($filters['digital_asset_id'])) {
            $query->where('digital_asset_id', (int) $filters['digital_asset_id']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['playbook_id'])) {
            $query->where('playbook_id', (int) $filters['playbook_id']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function schedulePresentation(RecurringReviewSchedule $schedule): array
    {
        $activeChecks = $schedule->relationLoaded('checkDefinitions')
            ? $schedule->checkDefinitions->where('is_active', true)->values()
            : Collection::make();

        return [
            'id' => $schedule->id,
            'customer_id' => $schedule->customer_id,
            'customer' => $schedule->customer?->name,
            'brand_id' => $schedule->brand_id,
            'brand' => $schedule->brand?->name,
            'digital_asset_id' => $schedule->digital_asset_id,
            'asset' => $schedule->digitalAsset?->name,
            'asset_type' => $schedule->digitalAsset?->type,
            'scope_kind' => $schedule->scope_kind instanceof \BackedEnum
                ? $schedule->scope_kind->value
                : $schedule->scope_kind,
            'playbook_id' => $schedule->playbook_id,
            'playbook_title' => $schedule->playbook?->currentRevision?->title,
            'cadence' => $schedule->cadence instanceof \BackedEnum
                ? $schedule->cadence->value
                : $schedule->cadence,
            'timezone' => $schedule->timezone,
            'starts_at' => $schedule->starts_at?->toIso8601String(),
            'ends_at' => $schedule->ends_at?->toIso8601String(),
            'status' => $schedule->status instanceof \BackedEnum
                ? $schedule->status->value
                : $schedule->status,
            'next_due_at' => $schedule->next_due_at?->toIso8601String(),
            'owner' => $schedule->owner?->name,
            'owner_user_id' => $schedule->owner_user_id,
            'default_reviewer_user_id' => $schedule->default_reviewer_user_id,
            'checks_active_count' => $activeChecks->count(),
            'source_state' => 'REAL',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function runPresentation(RecurringReviewRun $run): array
    {
        $now = now();
        $work = $this->toWorkItem($run, $now);

        return [
            'id' => $run->id,
            'title' => $work['title'],
            'schedule_id' => $run->schedule_id,
            'occurrence_key' => $run->occurrence_key,
            'occurrence_kind' => $run->occurrence_kind instanceof \BackedEnum
                ? $run->occurrence_kind->value
                : $run->occurrence_kind,
            'due_at' => $run->due_at?->toIso8601String(),
            'due' => $work['due'],
            'due_key' => $work['due_key'],
            'status' => $work['status'],
            'run_status' => $run->status instanceof \BackedEnum ? $run->status->value : $run->status,
            'playbook_id' => $run->playbook_id,
            'playbook_stable_key' => $run->playbook?->stable_key,
            'playbook_revision_id' => $run->playbook_revision_id,
            'playbook_title' => $run->playbookRevision?->title,
            'playbook_name' => $run->playbookRevision?->title,
            'customer_id' => $run->customer_id,
            'customer' => $run->customer?->name,
            'brand_id' => $run->brand_id,
            'brand' => $run->brand?->name,
            'digital_asset_id' => $run->digital_asset_id,
            'asset' => $run->digitalAsset?->name,
            'scope_kind' => $run->scope_kind instanceof \BackedEnum
                ? $run->scope_kind->value
                : $run->scope_kind,
            'service_scope_context' => $run->service_scope_context_json,
            'owner' => $work['owner'],
            'reviewer' => $run->reviewer?->name,
            'reviewer_user_id' => $run->reviewer_user_id,
            'started_at' => $run->started_at?->toIso8601String(),
            'completed_at' => $run->completed_at?->toIso8601String(),
            'summary' => $run->summary_json,
            'items' => $run->items->map(fn ($item): array => [
                'id' => $item->id,
                'check_definition_id' => $item->check_definition_id,
                'position' => $item->position,
                'title' => $item->title_snapshot,
                'description' => $item->description_snapshot,
                'is_required' => (bool) $item->is_required_snapshot,
                'state' => $item->state instanceof \BackedEnum ? $item->state->value : $item->state,
                'outcome_kind' => $item->outcome_kind instanceof \BackedEnum
                    ? $item->outcome_kind->value
                    : $item->outcome_kind,
                'finding_id' => $item->finding_id,
                'opportunity_id' => $item->opportunity_id,
                'task_id' => $item->task_id,
                'evidence_id' => $item->evidence_id,
            ])->all(),
            'source_state' => 'REAL',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toWorkItem(RecurringReviewRun $run, \DateTimeInterface $now): array
    {
        $status = $run->status instanceof RecurringReviewRunStatus
            ? $run->status->value
            : (string) $run->status;

        $due = $run->due_at;
        $workStatus = $status;
        if ($status === RecurringReviewRunStatus::Scheduled->value && $due !== null && $due->lte($now)) {
            $workStatus = $due->lt($now) ? 'overdue' : 'due';
        } elseif ($status === RecurringReviewRunStatus::InProgress->value) {
            $workStatus = 'in_progress';
        }

        $owner = $run->reviewer?->name
            ?? $run->schedule?->owner?->name
            ?? 'Unassigned';

        $title = $run->playbookRevision?->title
            ?? 'Recurring review #'.$run->id;

        return [
            'id' => (string) $run->id,
            'type' => 'recurring_review',
            'title' => $title,
            'customer' => $run->customer?->name ?? '',
            'customer_id' => $run->customer_id,
            'brand' => $run->brand?->name ?? '',
            'brand_id' => $run->brand_id,
            'asset' => $run->digitalAsset?->name,
            'asset_type' => $run->digitalAsset?->type,
            'digital_asset_id' => $run->digital_asset_id,
            'owner' => $owner,
            'owner_id' => $run->reviewer_user_id ?? $run->schedule?->owner_user_id,
            'due' => $due?->toDateString() ?? '—',
            'due_key' => $this->dueKey($due, $now),
            'status' => $workStatus,
            'waiting_on_client' => false,
            'qa_required' => false,
            'qa_status' => null,
            'priority' => in_array($workStatus, ['due', 'overdue'], true) ? 'high' : 'medium',
            'effort' => null,
            'service_label' => null,
            'goal_title' => null,
            'offering' => null,
            'source' => 'playbook',
            'source_label' => 'Playbook',
            'in_scope' => true,
            'playbook_id' => $run->playbook_id,
            'playbook_stable_key' => $run->playbook?->stable_key,
            'schedule_id' => $run->schedule_id,
            'route' => 'operator.work.show',
            'route_params' => WorkUrl::parameters(WorkUrl::TYPE_RECURRING_REVIEW, $run->id),
            'detail_url' => WorkUrl::show(WorkUrl::TYPE_RECURRING_REVIEW, $run->id),
            'source_state' => 'REAL',
        ];
    }

    private function dueKey(?\DateTimeInterface $due, \DateTimeInterface $now): string
    {
        if ($due === null) {
            return 'none';
        }

        $today = Carbon::instance(\DateTimeImmutable::createFromInterface($now))->startOfDay();
        $dueDay = Carbon::instance(\DateTimeImmutable::createFromInterface($due))->startOfDay();

        if ($dueDay->lt($today)) {
            return 'overdue';
        }
        if ($dueDay->equalTo($today)) {
            return 'today';
        }
        if ($dueDay->lte($today->copy()->addDays(3))) {
            return 'soon';
        }

        return 'later';
    }
}
