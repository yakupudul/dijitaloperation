<?php

namespace App\Services\RecurringReviews;

use App\Enums\PlaybookStatus;
use App\Enums\RecurringReviewOccurrenceKind;
use App\Enums\RecurringReviewRunItemState;
use App\Enums\RecurringReviewRunStatus;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Playbook;
use App\Models\RecurringReviewCheckDefinition;
use App\Models\RecurringReviewRun;
use App\Models\RecurringReviewRunItem;
use App\Models\RecurringReviewSchedule;
use App\Models\User;
use App\Services\Playbooks\PlaybookApplicabilityResolver;
use DateTimeInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Materializes a scheduled/manual Recurring Review Run from a Schedule occurrence.
 * Does not create Tasks/Findings/Opportunities/Evidence. Does not register a scheduler.
 */
final class MaterializeRecurringReviewOccurrence
{
    public function __construct(
        private readonly PlaybookApplicabilityResolver $applicability,
    ) {}

    public function __invoke(
        RecurringReviewSchedule $schedule,
        string $occurrenceKey,
        DateTimeInterface $dueAt,
        RecurringReviewOccurrenceKind $kind = RecurringReviewOccurrenceKind::Scheduled,
        ?User $actor = null,
    ): RecurringReviewRun {
        return $this->materialize($schedule, $occurrenceKey, $dueAt, $kind, $actor);
    }

    public function materialize(
        RecurringReviewSchedule $schedule,
        string $occurrenceKey,
        DateTimeInterface $dueAt,
        RecurringReviewOccurrenceKind $kind = RecurringReviewOccurrenceKind::Scheduled,
        ?User $actor = null,
    ): RecurringReviewRun {
        unset($actor);

        $existing = RecurringReviewRun::query()
            ->where('schedule_id', $schedule->id)
            ->where('occurrence_key', $occurrenceKey)
            ->first();
        if ($existing instanceof RecurringReviewRun) {
            return $existing->load('items');
        }

        $playbook = Playbook::query()
            ->with(['currentRevision.services', 'currentRevision.assetTypes', 'currentRevision.executionScopes'])
            ->find($schedule->playbook_id);

        if (! $playbook instanceof Playbook) {
            throw new RecurringReviewValidationException('PLAYBOOK_UNAVAILABLE', 'Playbook is unavailable.');
        }

        $status = $playbook->status instanceof PlaybookStatus
            ? $playbook->status
            : PlaybookStatus::tryFrom((string) $playbook->status);

        if ($status === PlaybookStatus::Archived || $playbook->current_revision_id === null || $playbook->currentRevision === null) {
            throw new RecurringReviewValidationException('PLAYBOOK_UNAVAILABLE', 'Playbook is archived or has no current revision.');
        }

        $customer = Customer::query()->find($schedule->customer_id);
        $brand = $schedule->brand_id !== null ? Brand::query()->find($schedule->brand_id) : null;
        $asset = $schedule->digital_asset_id !== null ? DigitalAsset::query()->find($schedule->digital_asset_id) : null;

        $resolution = $this->applicability->resolveForReviewScope(
            $playbook,
            $customer,
            $brand,
            $asset,
        );

        $serviceScopeContext = [
            'service_scope_context' => $resolution['service_scope_context'],
            'reasons' => $resolution['reasons'],
            'service_match' => $resolution['service_match'],
            'asset_type_compatible' => $resolution['asset_type_compatible'],
            'applicable' => $resolution['applicable'],
        ];

        $checks = RecurringReviewCheckDefinition::query()
            ->where('schedule_id', $schedule->id)
            ->where('is_active', true)
            ->orderBy('position')
            ->get();

        try {
            return DB::transaction(function () use ($schedule, $occurrenceKey, $dueAt, $kind, $playbook, $serviceScopeContext, $checks): RecurringReviewRun {
                $run = RecurringReviewRun::query()->create([
                    'schedule_id' => $schedule->id,
                    'occurrence_key' => $occurrenceKey,
                    'occurrence_kind' => $kind->value,
                    'due_at' => $dueAt,
                    'playbook_id' => $playbook->id,
                    'playbook_revision_id' => $playbook->current_revision_id,
                    'customer_id' => $schedule->customer_id,
                    'scope_kind' => $schedule->scope_kind instanceof \BackedEnum
                        ? $schedule->scope_kind->value
                        : (string) $schedule->scope_kind,
                    'brand_id' => $schedule->brand_id,
                    'digital_asset_id' => $schedule->digital_asset_id,
                    'service_scope_context_json' => $serviceScopeContext,
                    'reviewer_user_id' => null,
                    'status' => RecurringReviewRunStatus::Scheduled->value,
                    'started_at' => null,
                    'completed_at' => null,
                    'summary_json' => null,
                ]);

                foreach ($checks as $check) {
                    RecurringReviewRunItem::query()->create([
                        'run_id' => $run->id,
                        'check_definition_id' => $check->id,
                        'position' => $check->position,
                        'title_snapshot' => $check->title,
                        'description_snapshot' => $check->description,
                        'is_required_snapshot' => (bool) $check->is_required,
                        'finding_rule_stable_id_snapshot' => $check->finding_rule_stable_id,
                        'opportunity_rule_stable_id_snapshot' => $check->opportunity_rule_stable_id,
                        'state' => RecurringReviewRunItemState::Pending->value,
                        'outcome_kind' => null,
                    ]);
                }

                return $run->fresh(['items']) ?? $run;
            });
        } catch (UniqueConstraintViolationException) {
            $retry = RecurringReviewRun::query()
                ->where('schedule_id', $schedule->id)
                ->where('occurrence_key', $occurrenceKey)
                ->firstOrFail();

            return $retry->load('items');
        }
    }
}
