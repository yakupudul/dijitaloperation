<?php

namespace App\Jobs\Reports;

use App\Services\ReportDelivery\ExecuteReportDeliveryOccurrenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteReportDeliveryOccurrenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $occurrenceId) {}

    public function handle(ExecuteReportDeliveryOccurrenceService $executor): void
    {
        $executor->execute($this->occurrenceId);
    }
}
