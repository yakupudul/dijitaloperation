<?php

namespace App\Console\Commands\Production;

use App\Services\Operations\SystemBackup;
use Illuminate\Console\Command;

/**
 * Audit T3 (2026-10-22): deploy/staging/auto-deploy.sh calls this before a deploy migrates the database. With a
 * successful backup in the last --hours it passes; otherwise it takes one now, and only when that fails too does the
 * deploy wait (exit 1, the reason on the last line).
 */
final class DeployBackupGateCommand extends Command
{
    protected $signature = 'moxdop:deploy:backup-gate {--hours=30}';

    protected $description = 'Deploy öncesi yedek kontrolü: son N saatte başarılı yedek yoksa şimdi alır, alınamazsa deploy bekler.';

    public function handle(SystemBackup $backup): int
    {
        if (! (bool) config('moxdop-backup.enabled', true)) {
            $this->line('Yedek kapalı (MOXDOP_BACKUP_ENABLED); kontrol atlandı.');

            return self::SUCCESS;
        }
        $hours = max(1, (int) $this->option('hours'));
        $status = $backup->status();
        if ($status['hours'] !== null && $status['hours'] <= $hours) {
            $this->line(sprintf('Son başarılı yedek %d saat önce; deploy sürebilir.', $status['hours']));

            return self::SUCCESS;
        }
        $this->line(sprintf('Son %d saatte başarılı yedek yok; şimdi yedek alınıyor.', $hours));
        $result = $backup->run();
        if ($result['status'] === 'succeeded') {
            $this->line('Yedek alındı; deploy sürebilir.');

            return self::SUCCESS;
        }
        $this->line('Son '.$hours.' saatte başarılı yedek yok ve yeni yedek alınamadı ('.mb_substr((string) $result['error'], 0, 200).').');

        return self::FAILURE;
    }
}
