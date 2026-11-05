<?php

namespace App\Jobs\Sales;

use App\Models\SalesLead;
use App\Services\Sales\SalesAnalysisWriter;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Writes a prospect's full analysis in the background; the card polls until it is there. */
class WriteSalesAnalysis implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public int $timeout = 175;

    public int $tries = 1;

    public function __construct(public int $leadId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sales-analysis:'.$this->leadId;
    }

    public function handle(SalesAnalysisWriter $writer): void
    {
        $writer->write($this->leadId, 150);
    }

    public function failed(?Throwable $exception): void
    {
        SalesLead::query()->whereKey($this->leadId)->whereIn('analysis_status', ['queued', 'running'])
            ->update(['analysis_status' => 'failed', 'analysis_note' => 'Analiz yarıda kaldı; "Yeniden yaz" ile tekrar dene.']);
    }
}
