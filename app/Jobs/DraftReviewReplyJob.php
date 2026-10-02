<?php

namespace App\Jobs;

use App\Services\Gbp\ReviewReplyDrafter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Drafts one Google review reply (operator clicked "Yanıt taslağı"). */
final class DraftReviewReplyJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(public int $reviewId) {}

    public function handle(ReviewReplyDrafter $drafter): void
    {
        $drafter->write($this->reviewId);
    }
}
