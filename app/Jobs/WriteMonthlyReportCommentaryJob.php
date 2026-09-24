<?php

namespace App\Jobs;

use App\Models\MonthlyReport;
use App\Services\MonthlyReport\MonthlyReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Writes one monthly report's AI commentary (operator clicked "AI yorumu yaz"). */
final class WriteMonthlyReportCommentaryJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public int $reportId) {}

    public function handle(MonthlyReportService $reports): void
    {
        $report = MonthlyReport::query()->find($this->reportId);
        if ($report !== null) {
            $reports->writeCommentary($report);
        }
    }
}
