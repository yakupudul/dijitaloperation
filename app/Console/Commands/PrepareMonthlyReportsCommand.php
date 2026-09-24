<?php

namespace App\Console\Commands;

use App\Services\Assistant\PushNotifier;
use App\Services\MonthlyReport\MonthlyReportService;
use Illuminate\Console\Command;

/**
 * moxdop:reports:prepare-monthly — scheduled on day 1: last month's report is prepared for every active brand
 * and its AI commentary queued. The owner reviews, then publishes / e-mails from Raporlar › Aylık rapor.
 */
final class PrepareMonthlyReportsCommand extends Command
{
    protected $signature = 'moxdop:reports:prepare-monthly {--month= : YYYY-MM (varsayılan: geçen ay)}';

    protected $description = 'Geçen ayın aylık raporlarını tüm aktif markalar için hazırlar (yayımlamaz, göndermez).';

    public function handle(MonthlyReportService $reports, PushNotifier $push): int
    {
        $prepared = $reports->prepareAll($this->option('month') ?: null);
        $this->info(count($prepared).' rapor hazırlandı.');
        if ($prepared !== []) {
            $push->send('monthly-reports:'.($this->option('month') ?: now()->subMonthNoOverflow()->format('Y-m')), 'Aylık raporlar hazır',
                count($prepared).' markanın raporu taslak olarak hazırlandı; kontrol edip gönderin.', 'info', route('operator.reports.monthly'), 24);
        }

        return self::SUCCESS;
    }
}
