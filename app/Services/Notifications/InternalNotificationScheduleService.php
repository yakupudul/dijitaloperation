<?php

namespace App\Services\Notifications;

use App\Enums\InternalNotificationScheduleStatus;
use App\Enums\RecurringFrequency;
use App\Enums\RecurringMisfirePolicy;
use App\Models\InternalNotificationSchedule;
use App\Models\InternalNotificationScheduleRecipient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal scheduled notification configuration (Prompt 61).
 */
final class InternalNotificationScheduleService
{
    /**
     * @param  array{
     *     customer_id?: int|null,
     *     brand_id?: int|null,
     *     timezone?: string,
     *     frequency?: string,
     *     interval?: int,
     *     local_time?: string,
     *     day_of_month?: int|null,
     *     weekdays?: list<int>|null,
     *     title: string,
     *     message: string,
     *     safe_route_name?: string|null,
     *     recipient_user_ids: list<int>
     * }  $input
     */
    public function create(array $input, ?User $actor = null): InternalNotificationSchedule
    {
        $recipients = array_values(array_unique(array_map('intval', $input['recipient_user_ids'] ?? [])));
        if ($recipients === []) {
            throw ValidationException::withMessages(['recipient_user_ids' => 'RECIPIENTS_REQUIRED']);
        }

        $title = trim(strip_tags((string) ($input['title'] ?? '')));
        $message = trim(strip_tags((string) ($input['message'] ?? '')));
        if ($title === '' || $message === '') {
            throw ValidationException::withMessages(['message' => 'CONTENT_REQUIRED']);
        }

        $frequency = RecurringFrequency::tryFrom((string) ($input['frequency'] ?? 'daily'))
            ?? RecurringFrequency::Daily;
        if (! in_array($frequency, [
            RecurringFrequency::Daily,
            RecurringFrequency::Weekly,
            RecurringFrequency::Monthly,
        ], true)) {
            throw ValidationException::withMessages(['frequency' => 'UNSUPPORTED_FREQUENCY']);
        }

        return DB::transaction(function () use ($input, $actor, $recipients, $title, $message, $frequency): InternalNotificationSchedule {
            $schedule = InternalNotificationSchedule::query()->create([
                'customer_id' => isset($input['customer_id']) ? (int) $input['customer_id'] : null,
                'brand_id' => isset($input['brand_id']) ? (int) $input['brand_id'] : null,
                'timezone' => (string) ($input['timezone'] ?? 'Europe/Istanbul'),
                'frequency' => $frequency,
                'interval' => max(1, (int) ($input['interval'] ?? 1)),
                'local_time' => (string) ($input['local_time'] ?? '09:00'),
                'day_of_month' => isset($input['day_of_month']) ? (int) $input['day_of_month'] : ($frequency === RecurringFrequency::Monthly ? 1 : null),
                'weekdays' => $input['weekdays'] ?? ($frequency === RecurringFrequency::Weekly ? [1] : null),
                'title' => mb_substr($title, 0, 160),
                'message' => mb_substr($message, 0, 2000),
                'safe_route_name' => $input['safe_route_name'] ?? null,
                'misfire_policy' => RecurringMisfirePolicy::SkipMissed,
                'status' => InternalNotificationScheduleStatus::Active,
                'created_by' => $actor?->id,
            ]);

            foreach ($recipients as $userId) {
                if ($userId <= 0 || User::query()->whereKey($userId)->doesntExist()) {
                    continue;
                }
                InternalNotificationScheduleRecipient::query()->create([
                    'schedule_id' => (int) $schedule->id,
                    'user_id' => $userId,
                ]);
            }

            if ($schedule->recipients()->count() === 0) {
                throw ValidationException::withMessages(['recipient_user_ids' => 'RECIPIENTS_REQUIRED']);
            }

            return $schedule->fresh(['recipients']);
        });
    }

    public function pause(InternalNotificationSchedule $schedule): void
    {
        $schedule->status = InternalNotificationScheduleStatus::Paused;
        $schedule->save();
    }

    public function resume(InternalNotificationSchedule $schedule): void
    {
        $schedule->status = InternalNotificationScheduleStatus::Active;
        $schedule->save();
    }

    public function archive(InternalNotificationSchedule $schedule): void
    {
        $schedule->status = InternalNotificationScheduleStatus::Archived;
        $schedule->save();
    }
}
