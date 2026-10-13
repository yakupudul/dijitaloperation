<?php

namespace App\Jobs\Integrations;

use App\Services\Integrations\Bing\BingWebmasterSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * "Şimdi çek" on the Bing page: one Bing call per matched site (30 s each) ran inside the click and could pass nginx's
 * 60 s page limit with a handful of sites. The page now only matches the sites and reads their searches here.
 */
final class CollectBingQueriesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public int $uniqueFor = 900;

    public function handle(BingWebmasterSync $sync): void
    {
        $sync->collect();
    }
}
