<?php

namespace App\Jobs\Sales;

use App\Models\SalesLead;
use App\Services\Sales\LeadResearcher;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Yeni aday araştırması in the background: web search, then the site check and the analysis follow by themselves. */
class ResearchSalesLead implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 900;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(public int $leadId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sales-research:'.$this->leadId;
    }

    public function handle(LeadResearcher $researcher): void
    {
        $researcher->run($this->leadId);
    }

    public function failed(?Throwable $exception): void
    {
        SalesLead::query()->whereKey($this->leadId)->whereIn('research_status', ['queued', 'running'])
            ->update(['research_status' => 'failed', 'research_note' => 'Araştırma yarıda kaldı.', 'updated_at' => now()]);
    }
}
