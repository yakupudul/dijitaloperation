<?php

namespace App\Services\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Server-side checks for moxdop:audit: environment, pending migrations, queue backlog and failed jobs, the
 * Sistem Sağlığı read (scheduler, workers, integrations, accounts, backups, plugins, 2FA, watchdog), disk,
 * and the most frequent errors in the application log of the last 24 hours. Reads only; prints no secrets.
 *
 * Each check is [level, title, detail] with level ok / warn / fail / info.
 */
final class SystemAudit
{
    /** @return list<array{0: string, 1: string, 2: string}> */
    public function run(): array
    {
        $checks = [];
        foreach (['environment', 'migrations', 'queue', 'health', 'disk', 'log'] as $section) {
            try {
                array_push($checks, ...$this->{$section}());
            } catch (Throwable $exception) {
                $checks[] = ['fail', $section, 'Kontrol çalışmadı: '.mb_substr($exception->getMessage(), 0, 300)];
            }
        }

        return $checks;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function environment(): array
    {
        $sha = @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null');
        $out = [
            ['info', 'Sürüm', sprintf('commit %s · PHP %s · Laravel %s · env %s · %s', trim((string) $sha) ?: '?', PHP_VERSION, app()->version(), app()->environment(), config('app.url'))],
            [config('app.debug') && app()->environment('production') ? 'fail' : 'ok', 'APP_DEBUG', config('app.debug') ? 'açık' : 'kapalı'],
        ];
        $queue = (string) config('queue.default');
        $out[] = [in_array($queue, ['', 'sync'], true) ? 'fail' : ($queue === 'redis' ? 'ok' : 'warn'), 'Kuyruk bağlantısı', $queue ?: 'boş'];
        $out[] = ['info', 'Önbellek / oturum', sprintf('cache %s · session %s · config önbellekli: %s · route önbellekli: %s', config('cache.default'), config('session.driver'), app()->configurationIsCached() ? 'evet' : 'hayır', app()->routesAreCached() ? 'evet' : 'hayır')];
        DB::select('select 1');
        $size = null;
        if (DB::getDriverName() === 'pgsql') {
            $size = DB::selectOne('select pg_size_pretty(pg_database_size(current_database())) as s')->s ?? null;
        }
        $out[] = ['ok', 'Veritabanı', DB::getDriverName().($size ? ' · '.$size : '')];

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function migrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return [['fail', 'Migration', 'migrations tablosu yok']];
        }
        $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
        $pending = array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

        return [[$pending === [] ? 'ok' : 'fail', 'Bekleyen migration', $pending === [] ? 'yok' : count($pending).': '.implode(', ', array_slice($pending, 0, 5))]];
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function queue(): array
    {
        $out = [];
        if (Schema::hasTable('jobs')) {
            $pending = DB::table('jobs')->count();
            $oldest = DB::table('jobs')->min('created_at');
            $minutes = $oldest !== null ? (int) floor((time() - (int) $oldest) / 60) : 0;
            $out[] = [$minutes > 30 ? 'fail' : 'ok', 'Veritabanı kuyruğu', $pending.' bekleyen iş'.($pending > 0 ? ', en eskisi '.$minutes.' dk' : '')];
        }
        if (Schema::hasTable('failed_jobs')) {
            $since = now()->subDay();
            $failed = DB::table('failed_jobs')->where('failed_at', '>=', $since)->get(['payload', 'exception']);
            $groups = $failed->groupBy(fn ($row): string => (string) (json_decode((string) $row->payload, true)['displayName'] ?? '?').' — '.mb_substr((string) strtok((string) $row->exception, "\n"), 0, 160))
                ->map->count()->sortDesc()->take(8);
            $out[] = [$failed->isEmpty() ? 'ok' : 'warn', 'Başarısız işler (24 saat)', $failed->isEmpty() ? 'yok' : $failed->count().' iş'];
            foreach ($groups as $label => $count) {
                $out[] = ['warn', '  ×'.$count, (string) $label];
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function health(): array
    {
        $h = app(SystemHealthReader::class)->read();
        $out = [];
        $out[] = [$h['scheduler']['ok'] ? 'ok' : 'fail', 'Zamanlayıcı', $h['scheduler']['last_seen_at'] !== null ? $h['scheduler']['minutes'].' dk önce' : 'hiç çalışmamış'];
        $out[] = [($h['watchdog']['installed'] ?? false) ? 'ok' : 'warn', 'Watchdog cron', ($h['watchdog']['last_run_at'] ?? null) ?? 'hiç çalışmamış'];
        $bad = array_filter($h['workers'], fn (array $w): bool => ! $w['ok']);
        $out[] = [$h['workers'] === [] ? 'warn' : ($bad === [] ? 'ok' : 'fail'), 'İşçiler', count($h['workers']).' kayıtlı'.($bad !== [] ? ', sessiz: '.implode(', ', array_column($bad, 'name')) : '')];
        foreach ($h['alerts'] as $alert) {
            $out[] = [$alert['severity'] === 'critical' ? 'fail' : 'warn', 'Operasyon uyarısı', $alert['title'].($alert['summary'] ? ' — '.mb_substr((string) $alert['summary'], 0, 160) : '')];
        }
        foreach ($h['integrations'] as $i) {
            $expiring = $i['expires_in_days'] !== null && $i['expires_in_days'] <= 7;
            $broken = in_array($i['auth_status'], ['reconnect_required', 'revoked', 'error', 'expired'], true) || $i['last_error'] !== null;
            $out[] = [$broken ? 'fail' : ($expiring ? 'warn' : 'ok'), 'Entegrasyon '.$i['provider'], trim($i['status'].' '.$i['auth_status'].($i['expires_at'] ? ' · bitiş '.$i['expires_at'] : '').($i['last_error'] ? ' · '.$i['last_error'] : ''))];
        }
        $c = $h['account_counts'];
        $out[] = [$c['attention'] > 0 ? 'fail' : ($c['stale'] > 0 ? 'warn' : 'ok'), 'Hesap toplama', sprintf('%d hesap · %d dikkat · %d eski veri', $c['total'], $c['attention'], $c['stale'])];
        foreach (array_slice(array_filter($h['accounts'], fn (array $a): bool => $a['state'] === 'attention' || $a['stale']), 0, 25) as $a) {
            $out[] = [$a['state'] === 'attention' ? 'fail' : 'warn', '  '.$a['provider'].' '.$a['type'], $a['name'].' · '.$a['state'].' · veri '.($a['data_through'] ?? '—').($a['error'] ? ' · '.mb_substr((string) $a['error'], 0, 160) : '')];
        }
        foreach ($h['plugins'] as $p) {
            if ($p['outdated'] || $p['silent']) {
                $out[] = ['warn', 'WordPress eklentisi', $p['site'].' · '.($p['version'] ?? 'sürüm yok').($p['silent'] ? ' · 24 saattir veri yok' : '')];
            }
        }
        $b = $h['backup'];
        if ($b !== null) {
            $out[] = [$b['ok'] ? 'ok' : 'fail', 'Yedek', $b['last_success_at'] !== null ? $b['hours'].' saat önce'.($b['remote'] ? ', uzak kopya var' : ', uzak kopya yok') : 'hiç alınmamış'.($b['last_error'] ? ' · '.mb_substr((string) $b['last_error'], 0, 160) : '')];
        }
        $out[] = [$h['two_factor']['admins_without'] === [] ? 'ok' : 'warn', '2FA', $h['two_factor']['admins_without'] === [] ? 'tüm yöneticilerde açık' : count($h['two_factor']['admins_without']).' yöneticide kapalı'];

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function disk(): array
    {
        $free = @disk_free_space(base_path());
        $total = @disk_total_space(base_path());
        $share = $free !== false && $total ? $free / $total : null;
        $out = [[$share !== null && $share < 0.1 ? 'fail' : 'ok', 'Disk', $free !== false ? sprintf('%.1f GB boş (%%%d)', $free / 1e9, (int) round(($share ?? 0) * 100)) : '?']];
        $out[] = [is_writable(storage_path('logs')) && is_writable(storage_path('framework')) ? 'ok' : 'fail', 'storage yazılabilir', is_writable(storage_path('logs')) ? 'evet' : 'hayır'];

        return $out;
    }

    /** @return list<array{0: string, 1: string, 2: string}> */
    private function log(): array
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $since = CarbonImmutable::now()->subDay();
        $counts = [];
        $total = 0;
        foreach (array_slice($files, 0, 2) as $file) {
            $size = (int) filesize($file);
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            fseek($handle, max(0, $size - 8_000_000));
            while (($line = fgets($handle)) !== false) {
                if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $m) !== 1) {
                    continue;
                }
                try {
                    if (CarbonImmutable::parse($m[1])->lt($since)) {
                        continue;
                    }
                } catch (Throwable) {
                    continue;
                }
                $total++;
                $key = mb_substr((string) preg_replace(['/\{"(userId|exception)".*$/', '/\d{3,}/', '/\s+/'], ['', 'N', ' '], $m[3]), 0, 200);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
            fclose($handle);
        }
        arsort($counts);
        $out = [[$total === 0 ? 'ok' : 'warn', 'Uygulama log hataları (24 saat)', $files === [] ? 'log dosyası yok' : $total.' hata']];
        foreach (array_slice($counts, 0, 12, true) as $message => $count) {
            $out[] = ['warn', '  ×'.$count, $message];
        }

        return $out;
    }
}
