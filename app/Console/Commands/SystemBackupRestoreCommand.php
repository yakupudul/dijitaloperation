<?php

namespace App\Console\Commands;

use App\Services\Operations\SystemBackup;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:backup:restore — lists backups, or restores one (verified first; a safety backup of the current state is
 * taken before). Put the app in maintenance mode (`php artisan down`) before restoring on a live server.
 */
final class SystemBackupRestoreCommand extends Command
{
    protected $signature = 'moxdop:backup:restore {file? : Backup file (path or name); omit to list} {--latest : Restore the newest backup} {--force : Do not ask for confirmation}';

    protected $description = 'List database backups or restore one into the current database (safety backup first).';

    public function handle(SystemBackup $backup): int
    {
        $files = $backup->files();
        $file = $this->option('latest') ? ($files[0] ?? null) : $this->argument('file');
        if ($file === null) {
            if ($files === []) {
                $this->warn('Yedek yok.');

                return self::SUCCESS;
            }
            $this->table(['Dosya', 'Boyut (KB)'], array_map(fn (string $f): array => [basename($f), number_format((int) filesize($f) / 1024, 0, ',', '.')], $files));
            $this->line('Geri yüklemek için: php artisan moxdop:backup:restore <dosya> (ya da --latest)');

            return self::SUCCESS;
        }
        $path = is_file((string) $file) ? (string) $file : rtrim((string) config('moxdop-backup.directory'), '/').'/'.basename((string) $file);
        if (! $this->option('force') && ! $this->confirm('Mevcut veritabanı '.basename($path).' ile DEĞİŞTİRİLECEK (önce güvenlik yedeği alınır). Devam edilsin mi?')) {
            $this->info('Vazgeçildi.');

            return self::SUCCESS;
        }
        try {
            $result = $backup->restore($path);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $this->info('Geri yüklendi: '.basename($result['restored']).'. Güvenlik yedeği: '.basename((string) $result['safety_backup']));

        return self::SUCCESS;
    }
}
