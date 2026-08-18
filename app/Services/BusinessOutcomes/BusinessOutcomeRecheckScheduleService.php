<?php

namespace App\Services\BusinessOutcomes;

use App\Enums\BusinessOutcomeRecheckPeriodStrategy;
use App\Enums\BusinessOutcomeRecheckScheduleStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Models\Brand;
use App\Models\BusinessOutcomeRecheckSchedule;
use App\Models\BusinessOutcomeRecheckScheduleRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Business Outcome recheck schedule CRUD (Prompt 61).
 */
final class BusinessOutcomeRecheckScheduleService
{
    /**
     * @param  array{
     *     timezone?: string,
     *     frequency?: string,
     *     day_of_month?: int,
     *     weekdays?: list<int>,
     *     delivery_time?: string,
     *     period_strategy?: string,
     *     attention_on_no_data?: bool,
     *     attention_on_partial?: bool,
     *     attention_on_unknown?: bool,
     *     recipient_user_ids: list<int>
     * }  $input
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    public function create(
        Brand $brand,
        array $input,
        ?User $actor = null,
        array $authorizedCustomerIds = [],
        array $authorizedBrandIds = [],
    ): BusinessOutcomeRecheckSchedule {
        $this->assertAuthorized($brand, $authorizedCustomerIds, $authorizedBrandIds);

        $frequency = RecurringFrequency::tryFrom((string) ($input['frequency'] ?? 'monthly'))
            ?? RecurringFrequency::Monthly;
        if (! in_array($frequency, [RecurringFrequency::Weekly, RecurringFrequency::Monthly], true)) {
            throw ValidationException::withMessages(['frequency' => 'UNSUPPORTED_FREQUENCY']);
        }

        $recipients = array_values(array_unique(array_map('intval', $input['recipient_user_ids'] ?? [])));
        // Empty recipients allowed — recheck still runs, notifications skipped.

        return DB::transaction(function () use ($brand, $input, $actor, $frequency, $recipients): BusinessOutcomeRecheckSchedule {
            $schedule = BusinessOutcomeRecheckSchedule::query()->create([
                'customer_id' => (int) $brand->customer_id,
                'brand_id' => (int) $brand->id,
                'locale' => 'en',
                'timezone' => (string) ($input['timezone'] ?? 'Europe/Istanbul'),
                'frequency' => $frequency,
                'day_of_month' => (int) ($input['day_of_month'] ?? 5),
                'weekdays' => $input['weekdays'] ?? ($frequency === RecurringFrequency::Weekly ? [1] : null),
                'delivery_time' => (string) ($input['delivery_time'] ?? '09:00'),
                'period_strategy' => BusinessOutcomeRecheckPeriodStrategy::tryFrom((string) ($input['period_strategy'] ?? 'previous_calendar_month'))
                    ?? BusinessOutcomeRecheckPeriodStrategy::PreviousCalendarMonth,
                'misfire_policy' => RecurringMisfirePolicy::RunLatestMissed,
                'status' => BusinessOutcomeRecheckScheduleStatus::Active,
                'attention_on_no_data' => (bool) ($input['attention_on_no_data'] ?? true),
                'attention_on_partial' => (bool) ($input['attention_on_partial'] ?? true),
                'attention_on_unknown' => (bool) ($input['attention_on_unknown'] ?? true),
                'created_by' => $actor?->id,
            ]);

            foreach ($recipients as $userId) {
                if ($userId <= 0 || User::query()->whereKey($userId)->doesntExist()) {
                    continue;
                }
                BusinessOutcomeRecheckScheduleRecipient::query()->create([
                    'schedule_id' => (int) $schedule->id,
                    'user_id' => $userId,
                ]);
            }

            return $schedule->fresh(['recipients']);
        });
    }

    public function pause(BusinessOutcomeRecheckSchedule $schedule): void
    {
        $schedule->status = BusinessOutcomeRecheckScheduleStatus::Paused;
        $schedule->save();
    }

    public function resume(BusinessOutcomeRecheckSchedule $schedule): void
    {
        $schedule->status = BusinessOutcomeRecheckScheduleStatus::Active;
        $schedule->save();
    }

    public function archive(BusinessOutcomeRecheckSchedule $schedule): void
    {
        $schedule->status = BusinessOutcomeRecheckScheduleStatus::Archived;
        $schedule->save();
    }

    /**
     * @param  list<int>  $authorizedCustomerIds
     * @param  list<int>  $authorizedBrandIds
     */
    private function assertAuthorized(Brand $brand, array $authorizedCustomerIds, array $authorizedBrandIds): void
    {
        if ($authorizedBrandIds !== [] && ! in_array((int) $brand->id, array_map('intval', $authorizedBrandIds), true)) {
            throw ValidationException::withMessages(['brand' => 'UNAUTHORIZED_BRAND']);
        }
        if ($authorizedCustomerIds !== [] && ! in_array((int) $brand->customer_id, array_map('intval', $authorizedCustomerIds), true)) {
            throw ValidationException::withMessages(['customer' => 'UNAUTHORIZED_CUSTOMER']);
        }
    }
}
