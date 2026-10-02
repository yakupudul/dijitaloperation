<?php

namespace App\Services\Operations;

use App\Services\Assistant\PushNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Sistem yedeği (Faz 10d): nightly compressed database dump (pg_dump / mysqldump / SQLite copy) into
 * storage/app/backups, optional copy to a remote disk, the last `keep` files kept, every run recorded; a failed
 * backup pushes a phone notification. Credentials go through the environment, never the command line. Faz 11d: every
 * file is read back before it counts as a success (complete gzip stream, SQLite header / dump footer).
 */
final class SystemBackup
{
    public function __construct(private readonly PushNotifier $push) {}

    /** @return array{status: string, path: ?string, bytes: ?int, error: ?string} */
    public function run(): array
    {
        $connection = (string) config('database.default');
        $cfg = (array) config('database.connections.'.$connection, []);
        $driver = (string) ($cfg['driver'] ?? $connection);
        $id = (int) DB::table('system_backups')->insertGetId(['status' => 'running', 'driver' => $driver, 'started_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $dir = (string) config('moxdop-backup.directory');
        $tempDir = $this->tempDirectory();
        $temp = null;
        $path = null;
        try {
            foreach ([$dir, $tempDir] as $folder) {
                if (! is_dir($folder) && ! @mkdir($folder, 0700, true) && ! is_dir($folder)) {
                    throw new \RuntimeException('Yedek klasörü oluşturulamadı: '.$folder);
                }
            }
            $this->cleanTemp($tempDir);
            $this->assertFreeSpace($driver, $cfg, $tempDir, $dir);
            $name = 'moxdop-'.now()->format('Ymd-His-u').'-'.$driver.'.'.($driver === 'sqlite' ? 'sqlite.gz' : 'sql.gz');
            // Written and verified in the temp folder first (BACKUP_TEMP_DIR, on the main disk by default), then
            // moved next to the other backups — a failed / partial dump never lands among the kept files.
            $temp = $tempDir.'/'.$name.'.part';
            $this->dump($driver, $cfg, $temp);
            @chmod($temp, 0600);
            $this->verify($driver, $temp);
            $bytes = (int) filesize($temp);
            if ($bytes < 20) {
                throw new \RuntimeException('Yedek dosyası boş.');
            }
            $path = $dir.'/'.$name;
            $this->moveInto($temp, $path);
            $temp = null;
            $remote = false;
            if (filled(config('moxdop-backup.remote_disk'))) {
                $stream = fopen($path, 'rb');
                $remote = Storage::disk((string) config('moxdop-backup.remote_disk'))->writeStream('moxdop-backups/'.basename($path), $stream);
                is_resource($stream) && fclose($stream);
            }
            $this->prune($dir);
            DB::table('system_backups')->where('id', $id)->update(['status' => 'succeeded', 'path' => $path, 'bytes' => $bytes, 'remote_copied' => (bool) $remote, 'finished_at' => now(), 'updated_at' => now()]);

            return ['status' => 'succeeded', 'path' => $path, 'bytes' => $bytes, 'error' => null];
        } catch (Throwable $exception) {
            foreach ([$temp, $path] as $leftover) {
                if ($leftover !== null && is_file($leftover)) {
                    @unlink($leftover);
                }
            }
            $error = mb_substr($this->explain($exception, $tempDir), 0, 1000);
            DB::table('system_backups')->where('id', $id)->update(['status' => 'failed', 'error' => $error, 'finished_at' => now(), 'updated_at' => now()]);
            try {
                $this->push->send('backup-failed:'.now()->toDateString(), 'Sistem yedeği alınamadı', mb_substr($error, 0, 180), 'high', route('operator.settings.system-health'), 12);
            } catch (Throwable) {
            }

            return ['status' => 'failed', 'path' => null, 'bytes' => null, 'error' => $error];
        }
    }

    public function tempDirectory(): string
    {
        $configured = trim((string) config('moxdop-backup.temp_directory', ''));

        return rtrim($configured !== '' ? $configured : storage_path('app/backup-tmp'), '/');
    }

    /**
     * Expected size of the compressed dump: database size × compression ratio, plus a safety margin.
     *
     * @param  array<string, mixed>  $cfg
     */
    public function estimatedBytes(string $driver, array $cfg): int
    {
        $raw = 0;
        try {
            $raw = match ($driver) {
                'pgsql' => (int) (DB::selectOne('select pg_database_size(current_database()) as b')->b ?? 0),
                'mysql', 'mariadb' => (int) (DB::selectOne('select coalesce(sum(data_length + index_length), 0) as b from information_schema.tables where table_schema = database()')->b ?? 0),
                'sqlite' => is_file((string) ($cfg['database'] ?? '')) ? (int) filesize((string) $cfg['database']) : 0,
                default => 0,
            };
        } catch (Throwable) {
            $raw = 0;
        }
        $ratio = (float) config('moxdop-backup.compression_ratio', 0.35);

        return (int) ceil($raw * $ratio) + (int) config('moxdop-backup.free_space_margin_mb', 200) * 1024 * 1024;
    }

    /**
     * Fails before writing anything when the temp folder (or the backup folder on another disk) cannot hold the
     * dump, with the required and the available space in the message.
     *
     * @param  array<string, mixed>  $cfg
     * @param  (callable(string): (float|false))|null  $freeSpace
     */
    public function assertFreeSpace(string $driver, array $cfg, string $tempDir, string $dir, ?callable $freeSpace = null): void
    {
        $freeSpace ??= static fn (string $folder): float|false => @disk_free_space($folder);
        $required = $this->estimatedBytes($driver, $cfg);
        $folders = [$tempDir];
        if ($this->device($tempDir) !== $this->device($dir)) {
            $folders[] = $dir;
        }
        foreach ($folders as $folder) {
            $free = $freeSpace($folder);
            if ($free !== false && $free < $required) {
                throw new \RuntimeException(sprintf(
                    'Yedek için yeterli disk alanı yok: %s klasöründe gereken ~%s, boş %s. BACKUP_TEMP_DIR / MOXDOP_BACKUP_DIR ile daha büyük bir diske yönlendirin veya eski yedekleri silin.',
                    $folder, $this->human($required), $this->human((int) $free)
                ));
            }
        }
    }

    /** Leftover partial dumps of earlier crashed runs. */
    private function cleanTemp(string $tempDir): void
    {
        foreach (glob($tempDir.'/moxdop-*.part') ?: [] as $file) {
            @unlink($file);
        }
    }

    private function moveInto(string $from, string $to): void
    {
        if ($this->device(dirname($from)) === $this->device(dirname($to)) && @rename($from, $to)) {
            return;
        }
        if (! @copy($from, $to) || filesize($to) !== filesize($from)) {
            @unlink($to);
            throw new \RuntimeException('Yedek dosyası hedef klasöre taşınamadı: '.dirname($to));
        }
        @unlink($from);
    }

    private function device(string $folder): ?int
    {
        $stat = @stat($folder);

        return is_array($stat) ? (int) $stat['dev'] : null;
    }

    /** A disk-full error names the folder and its free space in Turkish instead of the raw fwrite() warning. */
    public function explain(Throwable $exception, string $tempDir): string
    {
        $message = $exception->getMessage();
        if (preg_match('/errno=28|No space left on device|ENOSPC/i', $message) === 1) {
            $free = @disk_free_space($tempDir);

            return sprintf('Disk dolu: yedek yazılırken yer kalmadı (%s klasöründe boş %s). BACKUP_TEMP_DIR ile daha büyük bir diske yönlendirin. Ayrıntı: %s',
                $tempDir, $free === false ? 'bilinmiyor' : $this->human((int) $free), mb_substr($message, 0, 300));
        }

        return $message;
    }

    private function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? number_format($bytes / 1024 ** 3, 1, ',', '.').' GB' : number_format($bytes / 1024 ** 2, 0, ',', '.').' MB';
    }

    /** @return array{last_success_at: ?string, hours: ?int, ok: bool, bytes: ?int, last_error: ?string, remote: bool} */
    public function status(): array
    {
        $last = DB::table('system_backups')->where('status', 'succeeded')->orderByDesc('finished_at')->first();
        $failed = DB::table('system_backups')->where('status', 'failed')->orderByDesc('finished_at')->first();
        $hours = $last !== null ? (int) now()->diffInHours($last->finished_at, true) : null;

        return [
            'last_success_at' => $last?->finished_at,
            'hours' => $hours,
            'ok' => $hours !== null && $hours <= (int) config('moxdop-backup.stale_hours', 26),
            'bytes' => $last?->bytes !== null ? (int) $last->bytes : null,
            'last_error' => $failed !== null && ($last === null || $failed->finished_at > $last->finished_at) ? $failed->error : null,
            'remote' => (bool) ($last->remote_copied ?? false),
        ];
    }

    /**
     * Writes the gzip'd dump to `$path`.
     *
     * @param  array<string, mixed>  $cfg
     */
    public function dump(string $driver, array $cfg, string $path): void
    {
        if ($driver === 'sqlite') {
            $database = (string) ($cfg['database'] ?? '');
            if ($database === ':memory:' || ! is_file($database)) {
                throw new \RuntimeException('SQLite dosyası bulunamadı.');
            }
            $in = fopen($database, 'rb');
            $out = gzopen($path, 'wb6');
            try {
                while (! feof($in)) {
                    $this->gzWrite($out, (string) fread($in, 1 << 20));
                }
            } finally {
                fclose($in);
                gzclose($out);
            }

            return;
        }
        [$command, $env] = match ($driver) {
            'pgsql' => [[(string) config('moxdop-backup.pg_dump'), '--no-owner', '--no-privileges', '--clean', '--if-exists', '-h', (string) ($cfg['host'] ?? '127.0.0.1'), '-p', (string) ($cfg['port'] ?? 5432), '-U', (string) ($cfg['username'] ?? ''), (string) ($cfg['database'] ?? '')], ['PGPASSWORD' => (string) ($cfg['password'] ?? '')]],
            'mysql', 'mariadb' => [[(string) config('moxdop-backup.mysqldump'), '--single-transaction', '--quick', '-h', (string) ($cfg['host'] ?? '127.0.0.1'), '-P', (string) ($cfg['port'] ?? 3306), '-u', (string) ($cfg['username'] ?? ''), (string) ($cfg['database'] ?? '')], ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')]],
            default => throw new \RuntimeException('Bu veritabanı sürücüsü için yedek desteklenmiyor: '.$driver),
        };
        $out = gzopen($path, 'wb6');
        $process = new Process($command, null, $env, null, 3600);
        // Streamed: dump output is compressed chunk by chunk. Process output is disabled, otherwise Symfony keeps a
        // full copy of stdout in php://temp — which spills the whole uncompressed dump into the system temp folder
        // (/tmp) and fails with "No space left on device" although the backup folder has room.
        $process->disableOutput();
        $errors = '';
        try {
            $process->run(function (string $type, string $buffer) use ($out, $process, &$errors): void {
                if ($type !== Process::OUT) {
                    $errors = substr($errors.$buffer, -4000);

                    return;
                }
                try {
                    $this->gzWrite($out, $buffer);
                } catch (Throwable $error) {
                    $process->stop(0);

                    throw $error;
                }
            });
        } finally {
            gzclose($out);
        }
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Döküm başarısız: '.mb_substr(trim($errors), 0, 500));
        }
    }

    /** @param  resource  $out */
    private function gzWrite($out, string $buffer): void
    {
        if ($buffer === '') {
            return;
        }
        $written = @gzwrite($out, $buffer);
        if ($written === false || $written === 0) {
            $error = error_get_last()['message'] ?? '';
            throw new \RuntimeException('Yedek yazılamadı (No space left on device?): '.$error);
        }
    }

    /** Reads the whole gzip stream back and checks the dump is complete; throws when it is not. */
    public function verify(string $driver, string $path): void
    {
        $in = @gzopen($path, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Yedek dosyası açılamadı.');
        }
        $head = '';
        $tail = '';
        while (! gzeof($in)) {
            $chunk = gzread($in, 1 << 20);
            if ($chunk === false) {
                gzclose($in);
                throw new \RuntimeException('Yedek dosyası bozuk (gzip okunamadı).');
            }
            if (strlen($head) < 16) {
                $head .= substr($chunk, 0, 16 - strlen($head));
            }
            $tail = substr($tail.$chunk, -4096);
        }
        gzclose($in);
        $complete = match ($driver) {
            'sqlite' => $head === "SQLite format 3\0",
            'pgsql' => str_contains($tail, 'PostgreSQL database dump complete'),
            'mysql', 'mariadb' => str_contains($tail, '-- Dump completed'),
            default => false,
        };
        if (! $complete) {
            throw new \RuntimeException('Yedek eksik görünüyor (dosya sonu / başlığı doğrulanamadı).');
        }
    }

    /**
     * Faz 14: restore a backup file into the current database connection. The file is verified first and a
     * fresh safety backup of the current state is taken before anything is overwritten.
     *
     * @return array{restored: string, safety_backup: ?string}
     */
    public function restore(string $path): array
    {
        $connection = (string) config('database.default');
        $cfg = (array) config('database.connections.'.$connection, []);
        $driver = (string) ($cfg['driver'] ?? $connection);
        if (! is_file($path)) {
            throw new \RuntimeException('Yedek dosyası bulunamadı: '.$path);
        }
        // Backup names end in -{driver}.sqlite.gz / .sql.gz; MySQL and MariaDB dumps are interchangeable.
        $family = static fn (string $d): string => $d === 'mariadb' ? 'mysql' : $d;
        $fileDriver = preg_match('/-(sqlite|pgsql|mysql|mariadb)\.(sqlite|sql)\.gz$/', basename($path), $m) === 1 ? $m[1] : $driver;
        if ($family($fileDriver) !== $family($driver)) {
            throw new \RuntimeException(sprintf('Bu yedek %s için; mevcut veritabanı %s.', $fileDriver, $driver));
        }
        $this->verify($driver, $path);
        $safety = $this->run();
        if ($safety['status'] !== 'succeeded') {
            throw new \RuntimeException('Geri yüklemeden önce güvenlik yedeği alınamadı: '.$safety['error']);
        }

        if ($driver === 'sqlite') {
            $database = (string) ($cfg['database'] ?? '');
            if ($database === ':memory:' || $database === '') {
                throw new \RuntimeException('SQLite dosyası yok.');
            }
            // The file is replaced atomically (rename); the command runs in its own process, which ends afterwards.
            $in = gzopen($path, 'rb');
            $out = fopen($database.'.restoring', 'wb');
            while (! gzeof($in)) {
                fwrite($out, (string) gzread($in, 1 << 20));
            }
            gzclose($in);
            fclose($out);
            rename($database.'.restoring', $database);

            return ['restored' => $path, 'safety_backup' => $safety['path']];
        }

        [$command, $env] = match ($driver) {
            'pgsql' => [[(string) config('moxdop-backup.psql', 'psql'), '-v', 'ON_ERROR_STOP=1', '-q', '-h', (string) ($cfg['host'] ?? '127.0.0.1'), '-p', (string) ($cfg['port'] ?? 5432), '-U', (string) ($cfg['username'] ?? ''), '-d', (string) ($cfg['database'] ?? '')], ['PGPASSWORD' => (string) ($cfg['password'] ?? '')]],
            'mysql', 'mariadb' => [[(string) config('moxdop-backup.mysql', 'mysql'), '-h', (string) ($cfg['host'] ?? '127.0.0.1'), '-P', (string) ($cfg['port'] ?? 3306), '-u', (string) ($cfg['username'] ?? ''), (string) ($cfg['database'] ?? '')], ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')]],
            default => throw new \RuntimeException('Bu veritabanı sürücüsü için geri yükleme desteklenmiyor: '.$driver),
        };
        DB::disconnect($connection);
        $process = new Process($command, null, $env, null, 3600);
        $process->setInput((function () use ($path) {
            $in = gzopen($path, 'rb');
            while (! gzeof($in)) {
                yield (string) gzread($in, 1 << 20);
            }
            gzclose($in);
        })());
        $process->run();
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Geri yükleme başarısız: '.mb_substr(trim($process->getErrorOutput()), 0, 500).' (güvenlik yedeği: '.$safety['path'].')');
        }

        return ['restored' => $path, 'safety_backup' => $safety['path']];
    }

    /** @return list<string> backup files, newest first */
    public function files(): array
    {
        $files = glob((string) config('moxdop-backup.directory').'/moxdop-*.gz') ?: [];
        rsort($files);

        return $files;
    }

    private function prune(string $dir): void
    {
        $files = glob($dir.'/moxdop-*.gz') ?: [];
        rsort($files);
        foreach (array_slice($files, max(1, (int) config('moxdop-backup.keep', 14))) as $old) {
            @unlink($old);
        }
    }
}
