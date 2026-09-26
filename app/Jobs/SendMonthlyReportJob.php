<?php

namespace App\Jobs;

use App\Models\MonthlyReport;
use App\Services\MonthlyReport\MonthlyReportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Rapor kuyruğu: publishes and e-mails one monthly report; the error stays on the report for the queue screen. */
final class SendMonthlyReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $reportId) {}

    public function handle(MonthlyReportService $reports): void
    {
        $report = MonthlyReport::query()->find($this->reportId);
        if ($report === null) {
            return;
        }
        try {
            $reports->email($report);
            $report->forceFill(['send_error' => null])->save();
        } catch (Throwable $error) {
            $message = method_exists($error, 'errors') ? (string) collect($error->errors())->flatten()->first() : $error->getMessage();
            $report->forceFill(['send_error' => mb_substr($message, 0, 300)])->save();
        }
    }
}
