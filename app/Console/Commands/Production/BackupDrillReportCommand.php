<?php

namespace App\Console\Commands\Production;

use App\Services\Assistant\PushNotifier;
use App\Services\Operations\SystemBackup;
use Illuminate\Console\Command;

/**
 * deploy/staging/restore-drill.sh calls this when the monthly restore drill fails (audit D5): the phone hears that the
 * newest backup could not be put back.
 */
final class BackupDrillReportCommand extends Command
{
    protected $signature = 'moxdop:backup:drill-report';

    protected $description = 'Aylık yedek geri yükleme provası başarısızsa telefona bildirir (deploy/staging/restore-drill.sh çağırır).';

    public function handle(PushNotifier $notifier): int
    {
        $drill = SystemBackup::drill();
        if ($drill === null || $drill['state'] !== 'failed') {
            return self::SUCCESS;
        }
        $notifier->send('backup-drill:'.$drill['checked_at'], 'Yedek geri yükleme provası başarısız', $drill['message'], 'high', route('operator.settings.system-health'), 24);
        $this->line($drill['message']);

        return self::SUCCESS;
    }
}
