<?php

namespace App\Jobs\Brand;

use App\Services\Brand\BrandChief;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Şef's weekly plan, on the background queue (Monday schedule or "Planı yenile"). */
final class RunBrandChiefJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function __construct(public bool $notify = true)
    {
        $this->onQueue((string) config('queue.background_queue', 'default'));
    }

    public function handle(BrandChief $chief): void
    {
        $chief->run($this->notify);
    }
}
