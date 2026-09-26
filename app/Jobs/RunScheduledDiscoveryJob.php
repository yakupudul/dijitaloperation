<?php

namespace App\Jobs;

use App\Services\Integrations\Discovery\ScheduledDiscovery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Daily Google / Meta account discovery (new accounts, lost access). */
final class RunScheduledDiscoveryJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 870;

    public int $tries = 1;

    public function handle(ScheduledDiscovery $discovery): void
    {
        $discovery->run();
    }
}
