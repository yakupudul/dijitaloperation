<?php

namespace App\Console\Commands;

use App\Services\Operations\SystemBackup;
use Illuminate\Console\Command;

/**
 * moxdop:backup — compressed database backup (nightly), optional remote copy, last N kept.
 */
final class SystemBackupCommand extends Command
{
    protected $signature = 'moxdop:backup';

    protected $description = 'Back up the database into storage/app/backups (and the remote disk when configured).';

    public function handle(SystemBackup $backup): int
    {
        if (! (bool) config('moxdop-backup.enabled', true)) {
            $this->info('Yedek kapalı (MOXDOP_BACKUP_ENABLED).');

            return self::SUCCESS;
        }
        $result = $backup->run();
        if ($result['status'] !== 'succeeded') {
            $this->error('Yedek alınamadı: '.$result['error']);

            return self::FAILURE;
        }
        $this->info(sprintf('Yedek alındı: %s (%s KB).', $result['path'], number_format((int) $result['bytes'] / 1024, 0, ',', '.')));

        return self::SUCCESS;
    }
}
