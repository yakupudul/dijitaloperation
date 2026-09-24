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
        $path = null;
        try {
            if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
                throw new \RuntimeException('Yedek klasörü oluşturulamadı: '.$dir);
            }
            $path = $dir.'/moxdop-'.now()->format('Ymd-His-u').'-'.$driver.'.'.($driver === 'sqlite' ? 'sqlite.gz' : 'sql.gz');
            $this->dump($driver, $cfg, $path);
            @chmod($path, 0600);
            $this->verify($driver, $path);
            $bytes = (int) filesize($path);
            if ($bytes < 20) {
                throw new \RuntimeException('Yedek dosyası boş.');
            }
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
            if ($path !== null && is_file($path)) {
                @unlink($path);
            }
            $error = mb_substr($exception->getMessage(), 0, 1000);
            DB::table('system_backups')->where('id', $id)->update(['status' => 'failed', 'error' => $error, 'finished_at' => now(), 'updated_at' => now()]);
            try {
                $this->push->send('backup-failed:'.now()->toDateString(), 'Sistem yedeği alınamadı', mb_substr($error, 0, 180), 'high', route('operator.settings.system-health'), 12);
            } catch (Throwable) {
            }

            return ['status' => 'failed', 'path' => null, 'bytes' => null, 'error' => $error];
        }
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

    /** @param array<string, mixed> $cfg */
    private function dump(string $driver, array $cfg, string $path): void
    {
        if ($driver === 'sqlite') {
            $database = (string) ($cfg['database'] ?? '');
            if ($database === ':memory:' || ! is_file($database)) {
                throw new \RuntimeException('SQLite dosyası bulunamadı.');
            }
            $in = fopen($database, 'rb');
            $out = gzopen($path, 'wb6');
            stream_copy_to_stream($in, $out);
            fclose($in);
            gzclose($out);

            return;
        }
        [$command, $env] = match ($driver) {
            'pgsql' => [[(string) config('moxdop-backup.pg_dump'), '--no-owner', '--no-privileges', '--clean', '--if-exists', '-h', (string) ($cfg['host'] ?? '127.0.0.1'), '-p', (string) ($cfg['port'] ?? 5432), '-U', (string) ($cfg['username'] ?? ''), (string) ($cfg['database'] ?? '')], ['PGPASSWORD' => (string) ($cfg['password'] ?? '')]],
            'mysql', 'mariadb' => [[(string) config('moxdop-backup.mysqldump'), '--single-transaction', '--quick', '-h', (string) ($cfg['host'] ?? '127.0.0.1'), '-P', (string) ($cfg['port'] ?? 3306), '-u', (string) ($cfg['username'] ?? ''), (string) ($cfg['database'] ?? '')], ['MYSQL_PWD' => (string) ($cfg['password'] ?? '')]],
            default => throw new \RuntimeException('Bu veritabanı sürücüsü için yedek desteklenmiyor: '.$driver),
        };
        $out = gzopen($path, 'wb6');
        $process = new Process($command, null, $env, null, 3600);
        $process->run(function (string $type, string $buffer) use ($out): void {
            if ($type === Process::OUT) {
                gzwrite($out, $buffer);
            }
        });
        gzclose($out);
        if (! $process->isSuccessful()) {
            throw new \RuntimeException('Döküm başarısız: '.mb_substr(trim($process->getErrorOutput()), 0, 500));
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
