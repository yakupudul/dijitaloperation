<?php

namespace App\Services\RecurringReviews;

use App\Enums\RecurringReviewOutcomeKind;
use App\Enums\RecurringReviewTaskLinkKind;
use App\Enums\TaskScopeKind;
use App\Enums\TaskSourceKind;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\RecurringReviewRunItem;
use App\Models\RecurringReviewRunItemTaskLink;
use App\Models\Task;
use App\Models\User;
use App\Services\Tasks\CreateTask;
use Illuminate\Support\Facades\DB;

/**
 * Create or link a Task from a review check. Never auto-assigns the reviewer.
 * Does not rewrite an existing Task's primary recurring_review_run_item_id when linking.
 */
final class CreateOrLinkTaskFromReviewCheck
{
    public function __construct(
        private readonly ResolveReviewTaskDisposition $disposition,
        private readonly CreateTask $createTask,
        private readonly RecurringReviewActivityRecorder $activity,
    ) {}

    /**
     * @param  array{title?: string, action?: string|null, forceCreateAnother?: bool}  $options
     * @return array{task: Task, relation_kind: string, created: bool}
     */
    public function __invoke(
        RecurringReviewRunItem $item,
        array $options = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): array {
        return $this->createOrLink($item, $options, $actor, $idempotencyKey);
    }

    /**
     * @param  array{title?: string, action?: string|null, forceCreateAnother?: bool}  $options
     * @return array{task: Task, relation_kind: string, created: bool}
     */
    public function createOrLink(
        RecurringReviewRunItem $item,
        array $options = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): array {
        $item->loadMissing('run');
        $run = $item->run;
        if ($run === null) {
            throw new RecurringReviewValidationException('RUN_MISSING', 'Run item has no parent run.');
        }

        $forceCreateAnother = (bool) ($options['forceCreateAnother'] ?? false);
        $title = trim((string) ($options['title'] ?? $item->title_snapshot));
        $action = isset($options['action']) ? (string) $options['action'] : $title;

        if ($idempotencyKey !== null) {
            $existingByKey = Task::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existingByKey instanceof Task) {
                $this->ensureLink($item, $existingByKey, RecurringReviewTaskLinkKind::Created);

                return [
                    'task' => $existingByKey,
                    'relation_kind' => RecurringReviewTaskLinkKind::Created->value,
                    'created' => false,
                ];
            }
        }

        return DB::transaction(function () use ($item, $run, $forceCreateAnother, $title, $action, $actor, $idempotencyKey): array {
            if (! $forceCreateAnother) {
                $open = $this->disposition->findOpenReviewOriginTask($item);
                if ($open instanceof Task) {
                    $this->ensureLink($item, $open, RecurringReviewTaskLinkKind::ExistingLinked);
                    $item->forceFill([
                        'task_id' => $open->id,
                        'outcome_kind' => RecurringReviewOutcomeKind::Task->value,
                    ])->save();

                    $this->activity->recordRun(
                        $run,
                        RecurringReviewActivityRecorder::EXISTING_TASK_LINKED,
                        $actor,
                        [
                            'run_item_id' => $item->id,
                            'task_id' => $open->id,
                        ],
                    );

                    return [
                        'task' => $open,
                        'relation_kind' => RecurringReviewTaskLinkKind::ExistingLinked->value,
                        'created' => false,
                    ];
                }
            }

            $scopeKind = $run->scope_kind instanceof TaskScopeKind
                ? $run->scope_kind
                : TaskScopeKind::from(
                    $run->scope_kind instanceof \BackedEnum
                        ? $run->scope_kind->value
                        : (string) $run->scope_kind
                );

            $task = $this->createTask->create([
                'title' => $title,
                'action' => $action,
                'customer_id' => (int) $run->customer_id,
                'brand_id' => $run->brand_id,
                'digital_asset_id' => $run->digital_asset_id,
                'scope_kind' => $scopeKind->value,
                'source_kind' => TaskSourceKind::RecurringReviewCheck->value,
                'recurring_review_run_item_id' => (int) $item->id,
                // No auto assignee from reviewer.
            ], $actor, $idempotencyKey);

            $this->ensureLink($item, $task, RecurringReviewTaskLinkKind::Created);
            $item->forceFill([
                'task_id' => $task->id,
                'outcome_kind' => RecurringReviewOutcomeKind::Task->value,
            ])->save();

            $this->activity->recordRun(
                $run,
                RecurringReviewActivityRecorder::TASK_CREATED,
                $actor,
                [
                    'run_item_id' => $item->id,
                    'task_id' => $task->id,
                ],
            );

            return [
                'task' => $task,
                'relation_kind' => RecurringReviewTaskLinkKind::Created->value,
                'created' => true,
            ];
        });
    }

    private function ensureLink(
        RecurringReviewRunItem $item,
        Task $task,
        RecurringReviewTaskLinkKind $kind,
    ): void {
        $existing = RecurringReviewRunItemTaskLink::query()
            ->where('run_item_id', $item->id)
            ->where('task_id', $task->id)
            ->first();

        if ($existing instanceof RecurringReviewRunItemTaskLink) {
            return;
        }

        RecurringReviewRunItemTaskLink::query()->create([
            'run_item_id' => $item->id,
            'task_id' => $task->id,
            'relation_kind' => $kind->value,
        ]);
    }
}
