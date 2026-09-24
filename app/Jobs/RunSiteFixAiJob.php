<?php

namespace App\Jobs;

use App\Services\SiteFixes\SiteFixAi;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** ADR-070: AI proposals for site fixes (values, internal links or one page text), started by a click. */
final class RunSiteFixAiJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public string $kind, public int $id) {}

    public function handle(SiteFixAi $ai): void
    {
        $ai->run($this->kind, $this->id);
    }
}
