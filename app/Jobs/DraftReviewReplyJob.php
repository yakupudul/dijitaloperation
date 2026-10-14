<?php

namespace App\Jobs;

use App\Models\GbpReview;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Gbp\ReviewReplyDrafter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Drafts one Google review reply (operator clicked "Yanıt taslağı"). The draft can be delegated to Claude: the job then
 * runs again once Claude answered.
 */
final class DraftReviewReplyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $reviewId) {}

    public function handle(ReviewReplyDrafter $drafter, AiTaskQueue $tasks): void
    {
        $review = GbpReview::query()->find($this->reviewId);
        $brandId = $review !== null ? $drafter->assetOf($review)?->brand_id : null;
        $tasks->begin(new self($this->reviewId), $brandId !== null ? (int) $brandId : null,
            'Yorum yanıt taslağı · '.mb_substr((string) $review?->comment, 0, 80));
        try {
            $drafter->write($this->reviewId);
        } finally {
            $tasks->settle();
        }
    }
}
