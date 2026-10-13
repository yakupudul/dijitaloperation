<?php

namespace App\Services\Operations;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Sunucu ve yazılım sağlığı (yakup, 2026-10-12: "sistemimizin yazılım sağlığı da gösterilsin, sunucudaki bellek
 * durumu da görünsün, kaç GB dolu boş"): memory, swap, disks, processor load and uptime of the server the app runs
 * on, and the software's own state (release, automatic deploy, errors and failed jobs of the last 24 hours, pending
 * migrations, scheduler and workers, debug mode, caches, database size). Reads only; cached for a minute.
 *
 * Each software line carries a tone: ok (green), look (amber), critical (red) or info (gray).
 */
final class ServerStatusReader
{
    /** Shares of used memory / disk where a line turns amber and red. */
    private const float LOOK_SHARE = 0.80;

    private const float CRITICAL_SHARE = 0.90;

    public function __construct(private readonly StorageGuard $storage) {}

    /**
     * @return array{server: array<string, mixed>, software: list<array{label: string, value: string, tone: string}>}
     */
    public function read(): array
    {
        return Cache::remember('server-status:read', 60, fn (): array => ['server' => $this->server(), 'software' => $this->software()]);
    }

    /** @return array<string, mixed> */
    public function server(): array
    {
        $memory = self::parseMeminfo($this->file('/proc/meminfo'));
        $cores = max(1, (int) preg_match_all('/^processor\s*:/m', $this->file('/proc/cpuinfo')));
        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: null) : null;
        $uptime = (float) strtok($this->file('/proc/uptime') ?: '0', ' ');

        return [
            'memory' => $memory['total'] > 0 ? $this->usage($memory['total'], $memory['available']) : null,
            'swap' => $memory['swap_total'] > 0 ? $this->usage($memory['swap_total'], $memory['swap_free']) : null,
            'disks' => $this->disks(),
            'cpu' => $load === null ? null : [
                'cores' => $cores,
                'load' => array_map(fn (float $l): float => round($l, 2), array_slice($load, 0, 3)),
                'tone' => $this->tone($load[1] / $cores, 1.0, 1.5),
            ],
            'uptime_days' => $uptime > 0 ? round($uptime / 86400, 1) : null,
        ];
    }

    /**
     * Total, used and free bytes of /proc/meminfo (used = total − available, as the kernel counts reclaimable cache free).
     *
     * @return array{total: int, available: int, swap_total: int, swap_free: int}
     */
    public static function parseMeminfo(string $meminfo): array
    {
        $values = [];
        foreach (preg_split('/\R/', $meminfo) ?: [] as $line) {
            if (preg_match('/^(\w+):\s+(\d+)\s*kB/', $line, $m) === 1) {
                $values[$m[1]] = (int) $m[2] * 1024;
            }
        }

        return [
            'total' => $values['MemTotal'] ?? 0,
            'available' => $values['MemAvailable'] ?? (($values['MemFree'] ?? 0) + ($values['Cached'] ?? 0) + ($values['Buffers'] ?? 0)),
            'swap_total' => $values['SwapTotal'] ?? 0,
            'swap_free' => $values['SwapFree'] ?? 0,
        ];
    }

    /** @return list<array{label: string, total: float, used: float, free: float, share: float, tone: string}> */
    private function disks(): array
    {
        $out = [];
        $app = ['free' => (float) (@disk_free_space(base_path()) ?: 0), 'total' => (float) (@disk_total_space(base_path()) ?: 0)];
        if ($app['total'] > 0) {
            $out[] = ['label' => 'Uygulama diski'] + $this->usage($app['total'], $app['free']);
        }
        $db = $this->storage->disk();
        // The database usually lives on the same disk: show it once.
        if ($db['total_bytes'] > 0 && ($app['total'] <= 0 || abs($db['total_bytes'] - $app['total']) > 1e6)) {
            $out[] = ['label' => 'Veritabanı diski'] + $this->usage($db['total_bytes'], $db['free_bytes']);
        }

        return $out;
    }

    /** @return array{total: float, used: float, free: float, share: float, tone: string} */
    private function usage(float $total, float $free): array
    {
        $used = max(0.0, $total - $free);
        $share = $total > 0 ? $used / $total : 0.0;

        return ['total' => $total, 'used' => $used, 'free' => $free, 'share' => $share, 'tone' => $this->tone($share, self::LOOK_SHARE, self::CRITICAL_SHARE)];
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    public function software(): array
    {
        $lines = [];
        foreach (['release', 'autoDeploy', 'errors', 'failedJobs', 'migrations', 'scheduler', 'runtime', 'database'] as $check) {
            try {
                array_push($lines, ...$this->{$check}());
            } catch (Throwable) {
                $lines[] = ['label' => $check, 'value' => 'okunamadı', 'tone' => 'look'];
            }
        }

        return $lines;
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    private function release(): array
    {
        $release = ReleaseInfo::current();
        $when = $release['deployed_at'] !== null ? ' · '.Carbon::parse($release['deployed_at'])->timezone('Europe/Istanbul')->format('d.m.Y H:i') : '';

        return [['label' => 'Canlı sürüm', 'value' => ($release['sha'] !== null ? substr($release['sha'], 0, 8) : 'bilinmiyor').$when, 'tone' => $release['sha'] !== null ? 'info' : 'look']];
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    private function autoDeploy(): array
    {
        $status = AutoDeployStatus::current();
        if ($status === null) {
            return [];
        }

        return [['label' => 'Otomatik deploy', 'value' => $status['label'].($status['sha'] !== '' ? ' · '.substr($status['sha'], 0, 8) : ''), 'tone' => $status['problem'] ? 'critical' : 'ok']];
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    private function errors(): array
    {
        if (! Schema::hasTable('app_error_groups')) {
            return [];
        }
        $groups = DB::table('app_error_groups')->where('last_seen_at', '>=', now()->subDay())->count();
        $release = ReleaseInfo::current()['sha'];
        $sinceRelease = $release !== null ? DB::table('app_error_groups')->where('last_seen_at', '>=', now()->subDay())->where('last_release', 'like', substr($release, 0, 12).'%')->count() : null;

        return [['label' => 'Uygulama hataları (24 saat)', 'value' => $groups === 0 ? 'yok' : $groups.' farklı hata'.($sinceRelease !== null ? ', '.$sinceRelease.' tanesi bu sürümde' : ''),
            'tone' => $groups === 0 ? 'ok' : ($sinceRelease ? 'critical' : 'look')]];
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return [['label' => 'Başarısız arka plan işleri (24 saat)', 'value' => $failed === 0 ? 'yok' : $failed.' iş', 'tone' => $failed === 0 ? 'ok' : ($failed > 20 ? 'critical' : 'look')]];
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    private function migrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return [['label' => 'Veritabanı şeması', 'value' => 'migration tablosu yok', 'tone' => 'critical']];
        }
        $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
        $pending = count(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

        return [['label' => 'Veritabanı şeması', 'value' => $pending === 0 ? 'güncel' : $pending.' migration bekliyor', 'tone' => $pending === 0 ? 'ok' : 'critical']];
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    private function scheduler(): array
    {
        $health = app(SystemHealthReader::class)->read();
        $silent = array_column(array_filter($health['workers'], fn (array $w): bool => ! $w['ok']), 'name');

        return [
            ['label' => 'Zamanlayıcı', 'value' => $health['scheduler']['last_seen_at'] !== null ? $health['scheduler']['minutes'].' dk önce çalıştı' : 'hiç çalışmamış', 'tone' => $health['scheduler']['ok'] ? 'ok' : 'critical'],
            ['label' => 'Arka plan işçileri', 'value' => $health['workers'] === [] ? 'kayıt yok' : ($silent === [] ? count($health['workers']).' işçi çalışıyor' : 'sessiz: '.implode(', ', $silent)),
                'tone' => $health['workers'] === [] ? 'look' : ($silent === [] ? 'ok' : 'critical')],
        ];
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    private function runtime(): array
    {
        $production = app()->environment('production', 'staging');
        $cached = app()->configurationIsCached() && app()->routesAreCached();

        return [
            ['label' => 'PHP · Laravel', 'value' => PHP_VERSION.' · '.app()->version(), 'tone' => 'info'],
            ['label' => 'Hata ayıklama modu', 'value' => config('app.debug') ? 'açık' : 'kapalı', 'tone' => config('app.debug') && $production ? 'critical' : 'ok'],
            ['label' => 'Ayar ve adres önbelleği', 'value' => $cached ? 'hazır' : 'yok', 'tone' => $cached || ! $production ? 'ok' : 'look'],
        ];
    }

    /**
     * The biggest tables of the PostgreSQL schema with their indexes and partitions counted under the parent (a compact
     * fact table is one row, not one per month), to see what fills the disk. Empty on other drivers.
     *
     * @return list<array{table: string, bytes: int, size: string}>
     */
    public function largestTables(int $limit = 12): array
    {
        if (DB::getDriverName() !== 'pgsql') {
            return [];
        }
        $rows = DB::select("select coalesce(p.relname, c.relname) as name, sum(pg_total_relation_size(c.oid))::bigint as bytes
            from pg_class c join pg_namespace n on n.oid = c.relnamespace
            left join pg_inherits i on i.inhrelid = c.oid left join pg_class p on p.oid = i.inhparent
            where n.nspname = current_schema() and c.relkind in ('r', 'm')
            group by 1 order by 2 desc limit ?", [$limit]);

        return array_map(fn (object $row): array => ['table' => (string) $row->name, 'bytes' => (int) $row->bytes, 'size' => self::gb((float) $row->bytes)], $rows);
    }

    /** @return list<array{label: string, value: string, tone: string}> */
    private function database(): array
    {
        $out = [];
        if (DB::getDriverName() === 'pgsql') {
            $bytes = (float) (DB::selectOne('select pg_database_size(current_database()) as b')->b ?? 0);
            $out[] = ['label' => 'Veritabanı boyutu', 'value' => self::gb($bytes), 'tone' => 'info'];
        }
        if (config('queue.default') === 'redis' || config('cache.default') === 'redis') {
            try {
                $info = Redis::connection()->info('memory');
                $used = (float) ($info['used_memory'] ?? $info['Memory']['used_memory'] ?? 0);
                $out[] = ['label' => 'Redis belleği', 'value' => self::gb($used), 'tone' => 'info'];
            } catch (Throwable) {
                $out[] = ['label' => 'Redis', 'value' => 'bağlanılamadı', 'tone' => 'critical'];
            }
        }

        return $out;
    }

    /** "12,4 GB" (below 1 GB in MB). */
    public static function gb(float $bytes): string
    {
        return $bytes >= 1e9 ? number_format($bytes / 1e9, 1, ',', '.').' GB' : number_format($bytes / 1e6, 0, ',', '.').' MB';
    }

    private function tone(float $value, float $look, float $critical): string
    {
        return $value >= $critical ? 'critical' : ($value >= $look ? 'look' : 'ok');
    }

    private function file(string $path): string
    {
        return is_readable($path) ? (string) @file_get_contents($path) : '';
    }
}
