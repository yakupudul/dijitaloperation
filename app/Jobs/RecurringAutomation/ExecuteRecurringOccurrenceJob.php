<?php

namespace App\Jobs\RecurringAutomation;

use App\Services\RecurringAutomation\ExecuteRecurringOccurrenceService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ExecuteRecurringOccurrenceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $occurrenceId) {}

    public function handle(ExecuteRecurringOccurrenceService $executor): void
    {
        $executor->execute($this->occurrenceId);
    }
}
