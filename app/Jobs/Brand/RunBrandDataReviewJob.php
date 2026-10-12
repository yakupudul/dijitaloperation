<?php

namespace App\Jobs\Brand;

use App\Services\Brand\BrandDataReview;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** One Claude marka denetimi of one brand, on the background queue (nightly schedule or moxdop:brands:review). */
final class RunBrandDataReviewJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public int $brandId, public bool $force = false)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return (string) $this->brandId;
    }

    public function handle(BrandDataReview $review): void
    {
        $review->runSafely($this->brandId, $this->force);
    }
}
