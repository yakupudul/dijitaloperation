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
        foreach (['environment', 'migrations', 'queue', 'health', 'collection', 'disk', 'tables', 'log'] as $section) {
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
        // Many alerts share one rule ("Automatic account updates · <account>"): print each rule once with a count.
        foreach (collect($h['alerts'])->groupBy(fn (array $a): string => $a['severity'].'|'.trim(explode(' · ', $a['title'])[0])) as $key => $group) {
            [$severity, $title] = explode('|', $key, 2);
            $names = $group->map(fn (array $a): string => trim(explode(' · ', $a['title'], 2)[1] ?? ''))->filter()->take(5)->implode(', ');
            $out[] = [$severity === 'critical' ? 'fail' : 'warn', 'Operasyon uyarısı ×'.$group->count(), $title.' — '.mb_substr((string) $group->first()['summary'], 0, 160).($names !== '' ? ' ['.$names.($group->count() > 5 ? ', …' : '').']' : '')];
        }
        foreach ($h['integrations'] as $i) {
            $expiring = $i['expires_in_days'] !== null && $i['expires_in_days'] <= 7;
            $broken = in_array($i['auth_status'], ['reconnect_required', 'revoked', 'error', 'expired'], true) || $i['last_error'] !== null;
            $out[] = [$broken ? 'fail' : ($expiring ? 'warn' : 'ok'), 'Entegrasyon '.$i['provider'], trim($i['status'].' '.$i['auth_status'].($i['expires_at'] ? ' · bitiş '.$i['expires_at'] : '').($i['last_error'] ? ' · '.$i['last_error'] : ''))];
        }
        $c = $h['account_counts'];
        $out[] = [$c['attention'] > 0 ? 'fail' : ($c['stale'] > 0 ? 'warn' : 'ok'), 'Hesap toplama', sprintf('%d hesap · %d dikkat · %d eski veri', $c['total'], $c['attention'], $c['stale'])];
        // Grouped by account type and reason, with a few example names, instead of one line per account.
        foreach (collect($h['accounts'])->filter(fn (array $a): bool => $a['state'] === 'attention' || $a['stale'])
            ->groupBy(fn (array $a): string => $a['type'].' · '.($a['state'] === 'attention' ? ($a['error'] ?: 'attention') : 'eski veri'))
            ->sortByDesc(fn ($group) => $group->count()) as $key => $group) {
            $out[] = ['warn', '  ×'.$group->count().' '.$key, $group->pluck('name')->take(4)->implode(', ').($group->count() > 4 ? ', …' : '')];
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
        if (blank(config('moxdop-backup.remote_disk'))) {
            $out[] = ['warn', 'Yedek sunucu dışında değil', 'MOXDOP_BACKUP_REMOTE_DISK tanımlı değil: sunucu kaybında yedek de gider (S3/SFTP diski tanımlayın)'];
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
        $last = [];
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
                // Where it broke (first application frame), so a page error can be traced without the full log.
                if (preg_match('#(?<=/)(?:app|app-modules|resources/views|routes|vendor)/[^\s:"()]+:\d+#', $m[3], $where) === 1) {
                    $key .= ' @ '.$where[0];
                }
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $last[$key] = max($last[$key] ?? '', $m[1]);
            }
            fclose($handle);
        }
        arsort($counts);
        $out = [[$total === 0 ? 'ok' : 'warn', 'Uygulama log hataları (24 saat)', $files === [] ? 'log dosyası yok' : $total.' hata']];
        foreach (array_slice($counts, 0, 25, true) as $message => $count) {
            // The last occurrence tells whether an error is still happening or was fixed by a later deploy.
            $out[] = ['warn', '  ×'.$count.' (son '.$this->shortTime($last[$message] ?? null).')', $message];
        }

        return $out;
    }

    /**
     * Why collections fail: dataset run errors and Business Profile run errors of the last 7 days, grouped.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function collection(): array
    {
        $out = [];
        $since = now()->subDays(7);
        if (Schema::hasTable('collection_dataset_runs')) {
            $rows = DB::table('collection_dataset_runs')->where('updated_at', '>=', $since)->whereIn('status', ['failed', 'blocked', 'dead_lettered', 'exhausted'])
                ->selectRaw('provider_or_source, error_code, error_message, count(*) as n, max(updated_at) as last_at')->groupBy('provider_or_source', 'error_code', 'error_message')
                ->orderByDesc('n')->limit(300)->get();
            // Messages that differ only by a record number or id are one problem.
            $groups = [];
            foreach ($rows as $row) {
                $message = trim(($row->error_code ?? '').' — '.mb_substr((string) preg_replace('/\d+/', 'N', (string) $row->error_message), 0, 200), ' —');
                $key = $row->provider_or_source.'|'.$message;
                $groups[$key] ??= ['source' => (string) $row->provider_or_source, 'message' => $message, 'n' => 0, 'last' => ''];
                $groups[$key]['n'] += (int) $row->n;
                $groups[$key]['last'] = max($groups[$key]['last'], (string) $row->last_at);
            }
            usort($groups, fn (array $a, array $b): int => $b['n'] <=> $a['n']);
            $out[] = [$groups === [] ? 'ok' : 'warn', 'Veri seti hataları (7 gün)', $groups === [] ? 'yok' : array_sum(array_column($groups, 'n')).' hatalı veri seti çalışması'];
            foreach (array_slice($groups, 0, 15) as $group) {
                $out[] = ['warn', '  ×'.$group['n'].' '.$group['source'].' (son '.$this->shortTime($group['last']).')', $group['message']];
            }
        }
        if (Schema::hasTable('runs')) {
            $messages = DB::table('runs')->where('module_id', 'google-business-profile')->where('status', 'failed')->where('created_at', '>=', $since)
                ->orderByDesc('id')->limit(500)->pluck('metadata')
                ->map(function ($metadata): string {
                    $meta = is_array($metadata) ? $metadata : (json_decode((string) $metadata, true) ?: []);
                    foreach (['error', 'error_message', 'failure_reason', 'reason', 'message', 'last_error'] as $key) {
                        $value = data_get($meta, $key);
                        if (is_string($value) && $value !== '') {
                            return mb_substr($value, 0, 200);
                        }
                    }

                    return '(neden kaydedilmemiş) '.mb_substr(implode(',', array_keys($meta)), 0, 120);
                })->countBy()->sortDesc()->take(8);
            if ($messages->isNotEmpty()) {
                $out[] = ['warn', 'İşletme Profili hataları (7 gün)', (int) $messages->sum().' başarısız çalışma'];
                foreach ($messages as $message => $count) {
                    $out[] = ['warn', '  ×'.$count, (string) $message];
                }
            }
        }

        return $out;
    }

    private function shortTime(?string $value): string
    {
        if (blank($value)) {
            return '?';
        }
        try {
            return CarbonImmutable::parse($value)->format('d.m H:i');
        } catch (Throwable) {
            return '?';
        }
    }

    /**
     * Largest tables (partitions summed into their parent) and storage folders — where the disk goes.
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    private function tables(): array
    {
        $out = [];
        if (DB::getDriverName() === 'pgsql') {
            // Partitions are summed into their parent. Dead rows are old row versions left by updates/deletes:
            // a high share means the table holds far more than its live data.
            $rows = DB::select("select coalesce(parent.relname, c.relname) as name,
                    sum(pg_total_relation_size(c.oid)) as total, sum(pg_indexes_size(c.oid)) as indexes,
                    sum(coalesce(s.n_live_tup, 0)) as live, sum(coalesce(s.n_dead_tup, 0)) as dead,
                    max(greatest(s.last_autovacuum, s.last_vacuum)) as vacuumed
                from pg_class c join pg_namespace n on n.oid = c.relnamespace
                left join pg_inherits i on i.inhrelid = c.oid left join pg_class parent on parent.oid = i.inhparent
                left join pg_stat_user_tables s on s.relid = c.oid
                where n.nspname = current_schema() and c.relkind in ('r', 'p', 'm')
                group by 1 order by 2 desc limit 20");
            foreach ($rows as $row) {
                $live = (int) $row->live;
                $dead = (int) $row->dead;
                $out[] = [$dead > $live && $dead > 100000 ? 'warn' : 'info', '  tablo '.$row->name, sprintf('%.2f GB (indeks %.2f GB) · %s satır · %s ölü satır%s', (float) $row->total / 1e9, (float) $row->indexes / 1e9,
                    number_format($live, 0, ',', '.'), number_format($dead, 0, ',', '.'), $row->vacuumed ? ' · vacuum '.substr((string) $row->vacuumed, 0, 16) : ' · hiç vacuum yok')];
            }
            try {
                $wal = DB::selectOne('select coalesce(sum(size), 0) as bytes from pg_ls_waldir()');
                $out[] = ['info', '  WAL (pg_wal)', sprintf('%.2f GB', (float) $wal->bytes / 1e9)];
            } catch (Throwable) {
                // Needs pg_monitor; skipped when the app user lacks it.
            }
        }
        foreach (['app' => storage_path('app'), 'logs' => storage_path('logs')] as $label => $path) {
            $size = @shell_exec('timeout 30 du -sb '.escapeshellarg($path).' 2>/dev/null');
            if (is_string($size) && preg_match('/^(\d+)/', $size, $m) === 1) {
                $out[] = ['info', '  storage/'.$label, sprintf('%.2f GB', (int) $m[1] / 1e9)];
            }
        }

        return $out;
    }
}
