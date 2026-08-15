<?php

namespace App\Services\RecurringReviews;

use App\Enums\PlaybookStatus;
use App\Enums\RecurringReviewCadence;
use App\Enums\RecurringReviewScheduleStatus;
use App\Enums\RecurringReviewScopeKind;
use App\Exceptions\RecurringReviewValidationException;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Playbook;
use App\Models\RecurringReviewCheckDefinition;
use App\Models\RecurringReviewSchedule;
use App\Models\User;
use App\Services\Playbooks\PlaybookApplicabilityResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Authoritative Recurring Review Schedule writer.
 * Does not mutate CustomerServiceScope. Does not convert Playbook instructions into checks.
 * Does not materialize Runs or register a scheduler.
 */
final class RecurringReviewScheduleService
{
    public function __construct(
        private readonly RecurringReviewDueCalculator $dueCalculator,
        private readonly RecurringReviewActivityRecorder $activity,
        private readonly PlaybookApplicabilityResolver $applicability,
    ) {}

    /**
     * @param  array{
     *     customer_id: int,
     *     scope_kind: string,
     *     brand_id?: int|null,
     *     digital_asset_id?: int|null,
     *     playbook_id: int,
     *     cadence: string,
     *     timezone: string,
     *     starts_at: string|\DateTimeInterface,
     *     ends_at?: string|\DateTimeInterface|null,
     *     owner_user_id?: int|null,
     *     default_reviewer_user_id?: int|null,
     *     checks: list<array{
     *         title: string,
     *         description?: string|null,
     *         is_required?: bool|null,
     *         finding_rule_stable_id?: string|null,
     *         opportunity_rule_stable_id?: string|null,
     *     }>,
     * }  $input
     */
    public function create(array $input, ?User $actor = null, ?string $idempotencyKey = null): RecurringReviewSchedule
    {
        if ($idempotencyKey !== null) {
            $existing = RecurringReviewSchedule::query()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing instanceof RecurringReviewSchedule) {
                return $existing->load('checkDefinitions');
            }
        }

        if (! array_key_exists('cadence', $input) || $input['cadence'] === null || $input['cadence'] === '') {
            throw new RecurringReviewValidationException('CADENCE_REQUIRED', 'cadence is required (weekly|monthly|quarterly); no silent default.');
        }

        try {
            $data = Validator::make($input, [
                'customer_id' => ['required', 'integer', Rule::exists('customers', 'id')],
                'scope_kind' => ['required', 'string', Rule::in(array_column(RecurringReviewScopeKind::cases(), 'value'))],
                'brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')],
                'digital_asset_id' => ['nullable', 'integer', Rule::exists('digital_assets', 'id')],
                'playbook_id' => ['required', 'integer', Rule::exists('playbooks', 'id')],
                'cadence' => ['required', 'string', Rule::in(array_column(RecurringReviewCadence::cases(), 'value'))],
                'timezone' => ['required', 'string', 'timezone'],
                'starts_at' => ['required', 'date'],
                'ends_at' => ['nullable', 'date', 'after:starts_at'],
                'owner_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
                'default_reviewer_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')],
                'checks' => ['required', 'array', 'min:1'],
                'checks.*.title' => ['required', 'string', 'max:255'],
                'checks.*.description' => ['nullable', 'string'],
                'checks.*.is_required' => ['nullable', 'boolean'],
                'checks.*.finding_rule_stable_id' => ['nullable', 'string', 'max:128'],
                'checks.*.opportunity_rule_stable_id' => ['nullable', 'string', 'max:128'],
            ])->validate();
        } catch (ValidationException $exception) {
            $code = isset($exception->errors()['cadence']) ? 'CADENCE_INVALID' : 'VALIDATION_FAILED';
            throw new RecurringReviewValidationException($code, $exception->getMessage());
        }

        $scopeKind = RecurringReviewScopeKind::from($data['scope_kind']);
        $customer = Customer::query()->findOrFail((int) $data['customer_id']);
        $brand = isset($data['brand_id']) ? Brand::query()->find((int) $data['brand_id']) : null;
        $asset = isset($data['digital_asset_id']) ? DigitalAsset::query()->find((int) $data['digital_asset_id']) : null;

        $this->assertScopeShape($scopeKind, $customer, $brand, $asset);

        $playbook = Playbook::query()
            ->with(['currentRevision.services', 'currentRevision.assetTypes', 'currentRevision.executionScopes'])
            ->findOrFail((int) $data['playbook_id']);

        $this->assertPlaybookApplicable($playbook, $customer, $brand, $asset);

        $attributes = [
            'customer_id' => $customer->id,
            'scope_kind' => $scopeKind->value,
            'brand_id' => $brand?->id,
            'digital_asset_id' => $asset?->id,
            'playbook_id' => $playbook->id,
            'cadence' => RecurringReviewCadence::from($data['cadence'])->value,
            'timezone' => (string) $data['timezone'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'] ?? null,
            'status' => RecurringReviewScheduleStatus::Active->value,
            'owner_user_id' => $data['owner_user_id'] ?? null,
            'default_reviewer_user_id' => $data['default_reviewer_user_id'] ?? null,
            'created_by' => $actor?->id,
            'idempotency_key' => $idempotencyKey,
        ];

        try {
            return DB::transaction(function () use ($attributes, $data, $actor): RecurringReviewSchedule {
                $schedule = RecurringReviewSchedule::query()->create($attributes);

                foreach (array_values($data['checks']) as $index => $check) {
                    RecurringReviewCheckDefinition::query()->create([
                        'schedule_id' => $schedule->id,
                        'position' => $index + 1,
                        'title' => trim((string) $check['title']),
                        'description' => isset($check['description']) ? (string) $check['description'] : null,
                        'is_required' => array_key_exists('is_required', $check) ? (bool) $check['is_required'] : true,
                        'is_active' => true,
                        'finding_rule_stable_id' => $check['finding_rule_stable_id'] ?? null,
                        'opportunity_rule_stable_id' => $check['opportunity_rule_stable_id'] ?? null,
                    ]);
                }

                $schedule->forceFill([
                    'next_due_at' => $this->dueCalculator->nextDueAfter($schedule),
                ])->save();

                $this->activity->recordSchedule(
                    $schedule,
                    RecurringReviewActivityRecorder::SCHEDULE_CREATED,
                    $actor,
                );

                return $schedule->fresh(['checkDefinitions']) ?? $schedule;
            });
        } catch (QueryException $exception) {
            if ($idempotencyKey !== null) {
                $existing = RecurringReviewSchedule::query()->where('idempotency_key', $idempotencyKey)->first();
                if ($existing instanceof RecurringReviewSchedule) {
                    return $existing->load('checkDefinitions');
                }
            }

            throw $exception;
        }
    }

    public function pause(RecurringReviewSchedule $schedule, ?User $actor = null): RecurringReviewSchedule
    {
        if ($schedule->status === RecurringReviewScheduleStatus::Ended) {
            throw new RecurringReviewValidationException('SCHEDULE_ENDED', 'Cannot pause an ended schedule.');
        }

        $schedule->forceFill([
            'status' => RecurringReviewScheduleStatus::Paused->value,
            'next_due_at' => null,
        ])->save();

        $this->activity->recordSchedule($schedule, RecurringReviewActivityRecorder::SCHEDULE_PAUSED, $actor);

        return $schedule->fresh(['checkDefinitions']) ?? $schedule;
    }

    public function resume(RecurringReviewSchedule $schedule, ?User $actor = null): RecurringReviewSchedule
    {
        if ($schedule->status === RecurringReviewScheduleStatus::Ended) {
            throw new RecurringReviewValidationException('SCHEDULE_ENDED', 'Cannot resume an ended schedule.');
        }

        $schedule->forceFill([
            'status' => RecurringReviewScheduleStatus::Active->value,
        ])->save();

        // Recalculate from now — no catch-up explosion of past occurrences.
        $schedule->forceFill([
            'next_due_at' => $this->dueCalculator->nextDueAfter($schedule, CarbonImmutable::now()),
        ])->save();

        $this->activity->recordSchedule($schedule, RecurringReviewActivityRecorder::SCHEDULE_RESUMED, $actor);

        return $schedule->fresh(['checkDefinitions']) ?? $schedule;
    }

    public function end(RecurringReviewSchedule $schedule, ?User $actor = null): RecurringReviewSchedule
    {
        $schedule->forceFill([
            'status' => RecurringReviewScheduleStatus::Ended->value,
            'next_due_at' => null,
        ])->save();

        $this->activity->recordSchedule($schedule, RecurringReviewActivityRecorder::SCHEDULE_ENDED, $actor);

        return $schedule->fresh(['checkDefinitions']) ?? $schedule;
    }

    /**
     * @param  array{cadence?: string, timezone?: string, starts_at?: mixed, ends_at?: mixed}  $input
     */
    public function updateCadence(RecurringReviewSchedule $schedule, array $input, ?User $actor = null): RecurringReviewSchedule
    {
        if ($schedule->status === RecurringReviewScheduleStatus::Ended) {
            throw new RecurringReviewValidationException('SCHEDULE_ENDED', 'Cannot update cadence on an ended schedule.');
        }

        if (array_key_exists('cadence', $input) && ($input['cadence'] === null || $input['cadence'] === '')) {
            throw new RecurringReviewValidationException('CADENCE_REQUIRED', 'cadence is required (weekly|monthly|quarterly); no silent default.');
        }

        try {
            $data = Validator::make($input, [
                'cadence' => ['sometimes', 'required', 'string', Rule::in(array_column(RecurringReviewCadence::cases(), 'value'))],
                'timezone' => ['sometimes', 'required', 'string', 'timezone'],
                'starts_at' => ['sometimes', 'required', 'date'],
                'ends_at' => ['sometimes', 'nullable', 'date'],
            ])->validate();
        } catch (ValidationException $exception) {
            throw new RecurringReviewValidationException('VALIDATION_FAILED', $exception->getMessage());
        }

        $schedule->fill($data);
        $schedule->save();

        if ($schedule->status === RecurringReviewScheduleStatus::Active) {
            $schedule->forceFill([
                'next_due_at' => $this->dueCalculator->nextDueAfter($schedule, CarbonImmutable::now()),
            ])->save();
        }

        $this->activity->recordSchedule($schedule, RecurringReviewActivityRecorder::SCHEDULE_UPDATED, $actor, [
            'fields' => array_keys($data),
        ]);

        return $schedule->fresh(['checkDefinitions']) ?? $schedule;
    }

    /**
     * Replace active check definitions going forward. Does not rewrite historical run items.
     *
     * @param  list<array{
     *     title: string,
     *     description?: string|null,
     *     is_required?: bool|null,
     *     finding_rule_stable_id?: string|null,
     *     opportunity_rule_stable_id?: string|null,
     * }>  $checks
     */
    public function updateChecks(RecurringReviewSchedule $schedule, array $checks, ?User $actor = null): RecurringReviewSchedule
    {
        if ($checks === []) {
            throw new RecurringReviewValidationException('CHECKS_REQUIRED', 'At least one check definition is required.');
        }

        try {
            Validator::make(['checks' => $checks], [
                'checks' => ['required', 'array', 'min:1'],
                'checks.*.title' => ['required', 'string', 'max:255'],
                'checks.*.description' => ['nullable', 'string'],
                'checks.*.is_required' => ['nullable', 'boolean'],
                'checks.*.finding_rule_stable_id' => ['nullable', 'string', 'max:128'],
                'checks.*.opportunity_rule_stable_id' => ['nullable', 'string', 'max:128'],
            ])->validate();
        } catch (ValidationException $exception) {
            throw new RecurringReviewValidationException('VALIDATION_FAILED', $exception->getMessage());
        }

        return DB::transaction(function () use ($schedule, $checks, $actor): RecurringReviewSchedule {
            // Soft-retire prior active defs so historical run items keep their FKs.
            RecurringReviewCheckDefinition::query()
                ->where('schedule_id', $schedule->id)
                ->where('is_active', true)
                ->update(['is_active' => false]);

            $maxPosition = (int) RecurringReviewCheckDefinition::query()
                ->where('schedule_id', $schedule->id)
                ->max('position');

            foreach (array_values($checks) as $index => $check) {
                RecurringReviewCheckDefinition::query()->create([
                    'schedule_id' => $schedule->id,
                    'position' => $maxPosition + $index + 1,
                    'title' => trim((string) $check['title']),
                    'description' => isset($check['description']) ? (string) $check['description'] : null,
                    'is_required' => array_key_exists('is_required', $check) ? (bool) $check['is_required'] : true,
                    'is_active' => true,
                    'finding_rule_stable_id' => $check['finding_rule_stable_id'] ?? null,
                    'opportunity_rule_stable_id' => $check['opportunity_rule_stable_id'] ?? null,
                ]);
            }

            $this->activity->recordSchedule($schedule, RecurringReviewActivityRecorder::SCHEDULE_UPDATED, $actor, [
                'fields' => ['checks'],
            ]);

            return $schedule->fresh(['checkDefinitions']) ?? $schedule;
        });
    }

    public function archiveCheckDefinition(RecurringReviewCheckDefinition $definition, ?User $actor = null): RecurringReviewCheckDefinition
    {
        $definition->forceFill(['is_active' => false])->save();

        $schedule = $definition->schedule;
        if ($schedule instanceof RecurringReviewSchedule) {
            $this->activity->recordSchedule($schedule, RecurringReviewActivityRecorder::SCHEDULE_UPDATED, $actor, [
                'archived_check_definition_id' => $definition->id,
            ]);
        }

        return $definition->fresh() ?? $definition;
    }

    private function assertScopeShape(
        RecurringReviewScopeKind $scopeKind,
        Customer $customer,
        ?Brand $brand,
        ?DigitalAsset $asset,
    ): void {
        match ($scopeKind) {
            RecurringReviewScopeKind::Customer => $this->assertCustomerScope($brand, $asset),
            RecurringReviewScopeKind::Brand => $this->assertBrandScope($customer, $brand, $asset),
            RecurringReviewScopeKind::DigitalAsset => $this->assertDigitalAssetScope($customer, $brand, $asset),
        };
    }

    private function assertCustomerScope(?Brand $brand, ?DigitalAsset $asset): void
    {
        if ($brand !== null || $asset !== null) {
            throw new RecurringReviewValidationException('SCOPE_SHAPE_INVALID', 'CUSTOMER scope requires brand_id and digital_asset_id to be null.');
        }
    }

    private function assertBrandScope(Customer $customer, ?Brand $brand, ?DigitalAsset $asset): void
    {
        if ($brand === null) {
            throw new RecurringReviewValidationException('SCOPE_SHAPE_INVALID', 'BRAND scope requires brand_id.');
        }
        if ($asset !== null) {
            throw new RecurringReviewValidationException('SCOPE_SHAPE_INVALID', 'BRAND scope requires digital_asset_id to be null.');
        }
        if ((int) $brand->customer_id !== (int) $customer->id) {
            throw new RecurringReviewValidationException('HIERARCHY_INVALID', 'Brand must belong to schedule Customer.');
        }
    }

    private function assertDigitalAssetScope(Customer $customer, ?Brand $brand, ?DigitalAsset $asset): void
    {
        if ($brand === null || $asset === null) {
            throw new RecurringReviewValidationException('SCOPE_SHAPE_INVALID', 'DIGITAL_ASSET scope requires brand_id and digital_asset_id.');
        }
        if ((int) $brand->customer_id !== (int) $customer->id) {
            throw new RecurringReviewValidationException('HIERARCHY_INVALID', 'Brand must belong to schedule Customer.');
        }
        if ((int) $asset->brand_id !== (int) $brand->id) {
            throw new RecurringReviewValidationException('HIERARCHY_INVALID', 'DigitalAsset must belong to schedule Brand.');
        }
    }

    private function assertPlaybookApplicable(
        Playbook $playbook,
        Customer $customer,
        ?Brand $brand,
        ?DigitalAsset $asset,
    ): void {
        $status = $playbook->status instanceof PlaybookStatus
            ? $playbook->status
            : PlaybookStatus::tryFrom((string) $playbook->status);

        if ($status !== PlaybookStatus::Active) {
            throw new RecurringReviewValidationException('PLAYBOOK_INACTIVE', 'Playbook must be active.');
        }

        if ($playbook->current_revision_id === null || $playbook->currentRevision === null) {
            throw new RecurringReviewValidationException('PLAYBOOK_NO_REVISION', 'Playbook must have a current revision.');
        }

        $resolution = $this->applicability->resolveForReviewScope($playbook, $customer, $brand, $asset);

        if ($resolution['service_match'] === false) {
            throw new RecurringReviewValidationException('PLAYBOOK_SERVICE_MATCH_FALSE', 'Playbook service applicability does not match.');
        }

        if ($resolution['asset_type_compatible'] === false) {
            throw new RecurringReviewValidationException('PLAYBOOK_ASSET_TYPE_INCOMPATIBLE', 'Playbook asset type applicability does not match.');
        }
    }
}
