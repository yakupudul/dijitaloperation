<?php

namespace App\Jobs\Site;

use App\Services\Site\Backlinks\LinkSourceGate;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Kalite kapısı for one batch of new pool sources (can wait for the subscription Claude: then it runs again with the answer). */
class CheckLinkSourcesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public int $timeout = 300;

    public int $tries = 1;

    /** @param  list<int>  $ids */
    public function __construct(public array $ids)
    {
        $this->onQueue((string) config('queue.heavy_queue', 'default'));
    }

    public function uniqueId(): string
    {
        return 'link-gate:'.implode(',', $this->ids);
    }

    public function handle(LinkSourceGate $gate): void
    {
        $gate->check($this->ids, $this);
    }
}
