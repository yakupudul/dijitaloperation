<?php

namespace App\Services\Qa;

use App\Models\BrandContextActivity;
use App\Models\QaReview;
use App\Models\User;
use Illuminate\Support\Carbon;

final class QaActivityRecorder
{
    public const string REQUESTED = 'QA_REQUESTED';

    public const string STARTED = 'QA_STARTED';

    public const string COMPLETED = 'QA_COMPLETED';

    public const string CANCELLED = 'QA_CANCELLED';

    /**
     * @param  array<string, mixed>  $extra
     */
    public function record(QaReview $review, string $event, ?User $actor = null, array $extra = []): ?BrandContextActivity
    {
        $allowed = [self::REQUESTED, self::STARTED, self::COMPLETED, self::CANCELLED];
        if (! in_array($event, $allowed, true)) {
            return null;
        }

        if ($review->brand_id === null) {
            return null;
        }

        return BrandContextActivity::query()->create([
            'brand_id' => $review->brand_id,
            'actor_user_id' => $actor?->id,
            'event' => $event,
            'subject_type' => QaReview::class,
            'subject_id' => $review->id,
            'payload' => array_merge([
                'qa_review_id' => $review->id,
                'task_id' => $review->task_id,
                'customer_id' => $review->customer_id,
                'status' => $review->status instanceof \BackedEnum ? $review->status->value : $review->status,
                'result' => $review->result instanceof \BackedEnum ? $review->result->value : $review->result,
                'reviewer_id' => $review->reviewer_id,
            ], $extra),
            'created_at' => Carbon::now(),
        ]);
    }
}
