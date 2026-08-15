<?php

namespace App\Services\RecurringReviews;

use App\Models\Brand;
use App\Models\BrandContextActivity;
use App\Models\RecurringReviewRun;
use App\Models\RecurringReviewSchedule;
use App\Models\User;
use App\Services\BrandIntelligence\BrandContextActivityRecorder;

final class RecurringReviewActivityRecorder
{
    public const string SCHEDULE_CREATED = 'RECURRING_REVIEW_SCHEDULE_CREATED';

    public const string SCHEDULE_UPDATED = 'RECURRING_REVIEW_SCHEDULE_UPDATED';

    public const string SCHEDULE_PAUSED = 'RECURRING_REVIEW_SCHEDULE_PAUSED';

    public const string SCHEDULE_RESUMED = 'RECURRING_REVIEW_SCHEDULE_RESUMED';

    public const string SCHEDULE_ENDED = 'RECURRING_REVIEW_SCHEDULE_ENDED';

    public const string REVIEW_STARTED = 'RECURRING_REVIEW_STARTED';

    public const string REVIEW_COMPLETED = 'RECURRING_REVIEW_COMPLETED';

    public const string REVIEW_SKIPPED = 'RECURRING_REVIEW_SKIPPED';

    public const string FINDING_RECORDED = 'REVIEW_FINDING_RECORDED';

    public const string OPPORTUNITY_RECORDED = 'REVIEW_OPPORTUNITY_RECORDED';

    public const string TASK_CREATED = 'REVIEW_TASK_CREATED';

    public const string EXISTING_TASK_LINKED = 'REVIEW_EXISTING_TASK_LINKED';

    public function __construct(
        private readonly BrandContextActivityRecorder $activities,
    ) {}

    /**
     * @param  array<string, mixed>  $extra
     */
    public function recordSchedule(
        RecurringReviewSchedule $schedule,
        string $event,
        ?User $actor = null,
        array $extra = [],
    ): ?BrandContextActivity {
        if ($schedule->brand_id === null) {
            return null;
        }

        $brand = Brand::query()->find($schedule->brand_id);
        if (! $brand instanceof Brand) {
            return null;
        }

        return $this->activities->record(
            $brand,
            $event,
            RecurringReviewSchedule::class,
            (int) $schedule->id,
            array_merge([
                'schedule_id' => $schedule->id,
                'playbook_id' => $schedule->playbook_id,
                'cadence' => $schedule->cadence?->value ?? $schedule->cadence,
                'status' => $schedule->status?->value ?? $schedule->status,
            ], $extra),
            $actor,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function recordRun(
        RecurringReviewRun $run,
        string $event,
        ?User $actor = null,
        array $extra = [],
    ): ?BrandContextActivity {
        if ($run->brand_id === null) {
            return null;
        }

        $brand = Brand::query()->find($run->brand_id);
        if (! $brand instanceof Brand) {
            return null;
        }

        return $this->activities->record(
            $brand,
            $event,
            RecurringReviewRun::class,
            (int) $run->id,
            array_merge([
                'run_id' => $run->id,
                'schedule_id' => $run->schedule_id,
                'status' => $run->status?->value ?? $run->status,
                'summary' => $run->summary_json,
            ], $extra),
            $actor,
        );
    }
}
