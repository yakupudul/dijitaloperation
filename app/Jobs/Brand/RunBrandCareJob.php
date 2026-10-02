<?php

namespace App\Jobs\Brand;

use App\Services\Brand\BrandCare;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** One Marka bakım ajanı review of one brand, on the background queue (weekly schedule or the tab's button). */
final class RunBrandCareJob implements ShouldBeUnique, ShouldQueue
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

    public function handle(BrandCare $care): void
    {
        $care->runSafely($this->brandId, $this->force);
    }

    public function failed(?Throwable $exception): void
    {
        BrandCare::markFailed($this->brandId, 'İnceleme tamamlanamadı; tekrar deneyin.');
    }
}
