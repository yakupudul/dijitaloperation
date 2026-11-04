<?php

namespace App\Jobs\Sales;

use App\Models\SalesLead;
use App\Services\Sales\ProspectSiteCheck;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** One look at a prospect's home page for the Satış card (findings + the first draft). */
class CheckProspectSite implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 300;

    public int $timeout = 90;

    public int $tries = 1;

    public function __construct(public int $leadId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'prospect-check:'.$this->leadId;
    }

    public function handle(ProspectSiteCheck $check): void
    {
        $lead = SalesLead::query()->find($this->leadId);
        if ($lead !== null && $lead->website_host !== null) {
            $check->run($lead);
        }
    }

    public function failed(?Throwable $exception): void
    {
        SalesLead::query()->whereKey($this->leadId)->whereIn('findings_status', ['queued', 'running'])
            ->update(['findings_status' => 'failed', 'updated_at' => now()]);
    }
}
