<?php

namespace App\Services\RecurringReviews;

use App\Enums\RecurringReviewOutcomeKind;
use App\Enums\RecurringReviewRunItemState;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\Finding;
use App\Models\Opportunity;
use App\Models\RecurringReviewRunItem;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Completes a single review check with one primary outcome XOR (or skip / not_applicable).
 * Finding/Opportunity outcomes do not create Tasks. No activity spam for no_issue.
 */
final class CompleteRecurringReviewCheck
{
    public function __construct(
        private readonly RecurringReviewEvidencePublisher $evidencePublisher,
        private readonly CreateFindingFromReviewCheck $createFinding,
        private readonly CreateOpportunityFromReviewCheck $createOpportunity,
        private readonly CreateOrLinkTaskFromReviewCheck $createOrLinkTask,
        private readonly RecurringReviewActivityRecorder $activity,
    ) {}

    /**
     * @param  array{
     *     title?: string,
     *     action?: string|null,
     *     forceCreateAnother?: bool,
     *     note?: string|null,
     * }  $options
     * @return array{
     *     item: RecurringReviewRunItem,
     *     finding: ?Finding,
     *     opportunity: ?Opportunity,
     *     task: ?Task,
     * }
     */
    public function complete(
        RecurringReviewRunItem $item,
        string $outcomeKind,
        array $options = [],
        ?User $actor = null,
        ?string $idempotencyKey = null,
    ): array {
        $normalized = strtolower(trim($outcomeKind));

        if ($idempotencyKey !== null) {
            $existing = RecurringReviewRunItem::query()
                ->where('outcome_idempotency_key', $idempotencyKey)
                ->first();
            if ($existing instanceof RecurringReviewRunItem) {
                return $this->present($existing);
            }
        }

        $state = $item->state instanceof RecurringReviewRunItemState
            ? $item->state
            : RecurringReviewRunItemState::tryFrom((string) $item->state);

        $isTerminal = in_array($state, [
            RecurringReviewRunItemState::Completed,
            RecurringReviewRunItemState::Skipped,
            RecurringReviewRunItemState::NotApplicable,
        ], true);

        if ($isTerminal) {
            $existingOutcome = $item->outcome_kind instanceof RecurringReviewOutcomeKind
                ? $item->outcome_kind->value
                : ($item->outcome_kind !== null ? (string) $item->outcome_kind : null);

            $requestedOutcome = match ($normalized) {
                'skipped', 'not_applicable' => null,
                default => $normalized,
            };

            $requestedState = match ($normalized) {
                'skipped' => RecurringReviewRunItemState::Skipped,
                'not_applicable' => RecurringReviewRunItemState::NotApplicable,
                default => RecurringReviewRunItemState::Completed,
            };

            if ($state === $requestedState && $existingOutcome === $requestedOutcome) {
                return $this->present($item);
            }

            throw new RecurringReviewValidationException('CONFLICT', 'Check already completed with a different outcome.');
        }

        return match ($normalized) {
            'no_issue' => $this->completeNoIssue($item, $options, $actor, $idempotencyKey),
            'finding' => $this->completeFinding($item, $options, $actor, $idempotencyKey),
            'opportunity' => $this->completeOpportunity($item, $options, $actor, $idempotencyKey),
            'task' => $this->completeTask($item, $options, $actor, $idempotencyKey),
            'skipped' => $this->completeStateOnly($item, RecurringReviewRunItemState::Skipped, $options, $actor, $idempotencyKey),
            'not_applicable' => $this->completeStateOnly($item, RecurringReviewRunItemState::NotApplicable, $options, $actor, $idempotencyKey),
            default => throw new RecurringReviewValidationException('OUTCOME_INVALID', 'Unsupported outcome kind: '.$outcomeKind),
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{item: RecurringReviewRunItem, finding: ?Finding, opportunity: ?Opportunity, task: ?Task}
     */
    private function completeNoIssue(
        RecurringReviewRunItem $item,
        array $options,
        ?User $actor,
        ?string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($item, $options, $actor, $idempotencyKey): array {
            $evidence = $this->evidencePublisher->publish($item, 'no_issue', $actor);

            $item->forceFill([
                'state' => RecurringReviewRunItemState::Completed->value,
                'outcome_kind' => RecurringReviewOutcomeKind::NoIssue->value,
                'evidence_id' => $evidence?->id,
                'finding_id' => null,
                'opportunity_id' => null,
                'task_id' => null,
                'note' => $options['note'] ?? $item->note,
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'outcome_idempotency_key' => $idempotencyKey,
            ])->save();

            // Intentionally no activity for no_issue.

            return $this->present($item->fresh() ?? $item);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{item: RecurringReviewRunItem, finding: ?Finding, opportunity: ?Opportunity, task: ?Task}
     */
    private function completeFinding(
        RecurringReviewRunItem $item,
        array $options,
        ?User $actor,
        ?string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($item, $options, $actor, $idempotencyKey): array {
            $created = $this->createFinding->create($item, $actor);
            $finding = $created['finding'];
            $evidence = $created['evidence'];

            $item->forceFill([
                'state' => RecurringReviewRunItemState::Completed->value,
                'outcome_kind' => RecurringReviewOutcomeKind::Finding->value,
                'evidence_id' => $evidence->id,
                'finding_id' => $finding->id,
                'opportunity_id' => null,
                'task_id' => null,
                'note' => $options['note'] ?? $item->note,
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'outcome_idempotency_key' => $idempotencyKey,
            ])->save();

            $item->loadMissing('run');
            if ($item->run !== null) {
                $this->activity->recordRun(
                    $item->run,
                    RecurringReviewActivityRecorder::FINDING_RECORDED,
                    $actor,
                    ['run_item_id' => $item->id, 'finding_id' => $finding->id],
                );
            }

            return $this->present($item->fresh(['finding', 'evidence']) ?? $item);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{item: RecurringReviewRunItem, finding: ?Finding, opportunity: ?Opportunity, task: ?Task}
     */
    private function completeOpportunity(
        RecurringReviewRunItem $item,
        array $options,
        ?User $actor,
        ?string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($item, $options, $actor, $idempotencyKey): array {
            $created = $this->createOpportunity->create($item, $actor);
            $opportunity = $created['opportunity'];
            $evidence = $created['evidence'];

            $item->forceFill([
                'state' => RecurringReviewRunItemState::Completed->value,
                'outcome_kind' => RecurringReviewOutcomeKind::Opportunity->value,
                'evidence_id' => $evidence->id,
                'finding_id' => null,
                'opportunity_id' => $opportunity->id,
                'task_id' => null,
                'note' => $options['note'] ?? $item->note,
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'outcome_idempotency_key' => $idempotencyKey,
            ])->save();

            $item->loadMissing('run');
            if ($item->run !== null) {
                $this->activity->recordRun(
                    $item->run,
                    RecurringReviewActivityRecorder::OPPORTUNITY_RECORDED,
                    $actor,
                    ['run_item_id' => $item->id, 'opportunity_id' => $opportunity->id],
                );
            }

            return $this->present($item->fresh(['opportunity', 'evidence']) ?? $item);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{item: RecurringReviewRunItem, finding: ?Finding, opportunity: ?Opportunity, task: ?Task}
     */
    private function completeTask(
        RecurringReviewRunItem $item,
        array $options,
        ?User $actor,
        ?string $idempotencyKey,
    ): array {
        return DB::transaction(function () use ($item, $options, $actor, $idempotencyKey): array {
            $result = $this->createOrLinkTask->createOrLink($item, $options, $actor, $idempotencyKey);

            $item->forceFill([
                'state' => RecurringReviewRunItemState::Completed->value,
                'outcome_kind' => RecurringReviewOutcomeKind::Task->value,
                'finding_id' => null,
                'opportunity_id' => null,
                'task_id' => $result['task']->id,
                'note' => $options['note'] ?? $item->note,
                'completed_at' => now(),
                'completed_by' => $actor?->id,
                'outcome_idempotency_key' => $idempotencyKey,
            ])->save();

            return $this->present($item->fresh(['task']) ?? $item);
        });
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array{item: RecurringReviewRunItem, finding: ?Finding, opportunity: ?Opportunity, task: ?Task}
     */
    private function completeStateOnly(
        RecurringReviewRunItem $item,
        RecurringReviewRunItemState $state,
        array $options,
        ?User $actor,
        ?string $idempotencyKey,
    ): array {
        $item->forceFill([
            'state' => $state->value,
            'outcome_kind' => null,
            'finding_id' => null,
            'opportunity_id' => null,
            'task_id' => null,
            'note' => $options['note'] ?? $item->note,
            'completed_at' => now(),
            'completed_by' => $actor?->id,
            'outcome_idempotency_key' => $idempotencyKey,
        ])->save();

        return $this->present($item->fresh() ?? $item);
    }

    /**
     * @return array{item: RecurringReviewRunItem, finding: ?Finding, opportunity: ?Opportunity, task: ?Task}
     */
    private function present(RecurringReviewRunItem $item): array
    {
        $item->loadMissing(['finding', 'opportunity', 'task', 'evidence']);

        return [
            'item' => $item,
            'finding' => $item->finding,
            'opportunity' => $item->opportunity,
            'task' => $item->task,
        ];
    }
}
