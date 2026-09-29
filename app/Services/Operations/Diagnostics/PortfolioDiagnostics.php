<?php

namespace App\Services\Operations\Diagnostics;

use App\Models\BrandDemandQuery;
use App\Models\DigitalAsset;
use App\Services\BrandSetup\BrandSetupMatcher;
use App\Services\Operations\OpsWatchdog;
use App\Services\Operations\ReleaseInfo;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiProviderCatalog;
use App\Support\Ai\AiRouteRegistry;
use App\Support\Integrations\ExternalResourceAssetCompatibility;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only portfolio diagnosis behind `moxdop:diagnose`: environment, ownership, integrations, data collection,
 * websites, search queries, advisors, AI and application errors, as short lines the operator pastes to the developer.
 *
 * Only SELECT queries (aggregates and limits), stored state only: no provider / API call, no job dispatch, no
 * cache or database write. Each section is independent: a missing table prints "yok", an exception prints its class
 * and message and the next section runs. Secrets and personal data are masked by DiagnosticMasker.
 */
final class PortfolioDiagnostics
{
    /** @var array<string, string> section key => Turkish title */
    public const array SECTIONS = [
        'environment' => '1. Ortam',
        'ownership' => '2. Sahiplik ve bağlamalar',
        'integrations' => '3. Entegrasyonlar',
        'collection' => '4. Veri toplama',
        'website' => '5. Web siteleri',
        'queries' => '6. Arama sorguları',
        'advisors' => '7. Danışmanlar, uyarılar, komuta merkezi',
        'ai' => '8. Yapay zekâ',
        'errors' => '9. Uygulama hataları',
    ];

    /**
     * Fact table with the latest reporting date per resource type, and the expected provider lag in days.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const array FACT_TABLES = [
        'ga4' => ['ga4_property_daily', 2],
        'search_console' => ['gsc_property_daily', 4],
        'google_ads' => ['google_ads_account_daily', 2],
        'meta_ads' => ['meta_account_daily', 2],
        'meta_ad_account' => ['meta_account_daily', 2],
        'google_business_profile' => ['gbp_performance_daily', 7],
    ];

    /** @var array<string, list<string>> detail fact tables checked for a --brand / --asset scope */
    private const array DETAIL_FACT_TABLES = [
        'ga4' => ['ga4_landing_page_daily'],
        'search_console' => ['gsc_query_daily', 'gsc_page_daily'],
        'google_ads' => ['google_ads_campaign_daily'],
        'meta_ads' => ['meta_campaign_daily'],
        'meta_ad_account' => ['meta_campaign_daily'],
    ];

    private const array BAD_AUTH = ['reconnect_required', 'revoked', 'error', 'expired', 'invalid'];

    private const array FAILED = ['failed', 'cancelled', 'error'];

    private const array SUCCEEDED = ['completed', 'succeeded', 'success', 'done'];

    /** @var array<string, array{title: string, lines: list<string>, problems: list<string>, data: array<string, mixed>, ms: int}> */
    private array $sections = [];

    private string $current = '';

    /** @var array<string, bool> */
    private array $tables = [];

    /** @var array<string, bool> */
    private array $columns = [];

    private int $days = 14;

    /** @var list<array{id: int, name: string, customer_id: ?int}> */
    private array $brands = [];

    private ?int $assetId = null;

    /** @var list<int>|null */
    private ?array $scopeAssetIds = null;

    private bool $scopeRequested = false;

    /**
     * @param  array{brand?: ?string, asset?: ?string, days?: int, sections?: list<string>}  $options
     * @return array{generated_at: string, scope: array<string, mixed>, sections: array<string, array<string, mixed>>, summary: list<string>, elapsed_ms: int}
     */
    public function run(array $options): array
    {
        $started = microtime(true);
        $this->days = max(1, min(90, (int) ($options['days'] ?? 14)));
        $this->protectConnection();
        $scope = $this->resolveScope($options['brand'] ?? null, $options['asset'] ?? null);

        $wanted = $options['sections'] ?? [];
        foreach (self::SECTIONS as $key => $title) {
            if ($wanted !== [] && ! in_array($key, $wanted, true)) {
                continue;
            }
            $this->current = $key;
            $this->sections[$key] = ['title' => $title, 'lines' => [], 'problems' => [], 'data' => [], 'ms' => 0];
            $sectionStarted = microtime(true);
            try {
                $this->{$key}();
            } catch (Throwable $error) {
                $this->problem('Bölüm okunamadı: '.class_basename($error).': '.DiagnosticMasker::firstLine($error->getMessage(), 300));
            }
            $this->sections[$this->current]['ms'] = (int) round((microtime(true) - $sectionStarted) * 1000);
        }

        $summary = [];
        foreach ($this->sections as $key => $section) {
            foreach ($section['problems'] as $problem) {
                $summary[] = '['.$key.'] '.$problem;
            }
        }

        return [
            'generated_at' => now()->toDateTimeString(),
            'scope' => $scope,
            'sections' => $this->sections,
            'summary' => $summary,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ];
    }

    // ------------------------------------------------------------------ scope

    /**
     * PostgreSQL: every later statement of this session is read-only and bounded in time, so a mistake cannot write
     * and a slow query cannot hold the server. Other drivers: nothing to set.
     */
    private function protectConnection(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        try {
            DB::unprepared('SET default_transaction_read_only = on');
            DB::unprepared("SET statement_timeout = '20s'");
        } catch (Throwable) {
            // best effort; every query below is a SELECT anyway
        }
    }

    /** @return array<string, mixed> */
    private function resolveScope(?string $brand, ?string $asset): array
    {
        $brand = trim((string) $brand);
        $asset = trim((string) $asset);
        $this->scopeRequested = $brand !== '' || $asset !== '';
        $scope = ['mode' => 'portfolio', 'days' => $this->days, 'brands' => [], 'asset_id' => null, 'note' => null];
        if (! $this->scopeRequested || ! $this->hasTable('brands')) {
            return $scope;
        }

        if ($asset !== '' && ctype_digit($asset) && $this->hasTable('digital_assets')) {
            $row = DB::table('digital_assets')->where('id', (int) $asset)->first(['id', 'brand_id']);
            if ($row !== null) {
                $this->assetId = (int) $row->id;
                $this->scopeAssetIds = [(int) $row->id];
                if ($row->brand_id !== null) {
                    $brand = $brand !== '' ? $brand : (string) $row->brand_id;
                }
            } else {
                $scope['note'] = 'varlık #'.$asset.' bulunamadı';
                $this->scopeAssetIds = [];
            }
        }

        if ($brand !== '') {
            $query = DB::table('brands')->whereNull('deleted_at');
            if (ctype_digit($brand)) {
                $query->where('id', (int) $brand);
            } else {
                $query->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($brand).'%']);
            }
            $this->brands = $query->orderBy('name')->limit(5)->get(['id', 'name', 'customer_id'])
                ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'customer_id' => $row->customer_id !== null ? (int) $row->customer_id : null])
                ->all();
            if ($this->brands === []) {
                $scope['note'] = trim(($scope['note'] ?? '').' marka bulunamadı: '.$brand);
            }
            if ($this->assetId === null) {
                $this->scopeAssetIds = $this->brands === [] ? [] : DB::table('digital_assets')->whereNull('deleted_at')
                    ->whereIn('brand_id', array_column($this->brands, 'id'))->orderBy('id')->limit(500)->pluck('id')->map(fn ($id): int => (int) $id)->all();
            }
        }

        $scope['mode'] = $this->assetId !== null ? 'asset' : 'brand';
        $scope['brands'] = $this->brands;
        $scope['asset_id'] = $this->assetId;
        $scope['asset_ids'] = $this->scopeAssetIds;

        return $scope;
    }

    private function deep(): bool
    {
        return $this->scopeRequested;
    }

    /** @return list<int> */
    private function brandIds(): array
    {
        return array_column($this->brands, 'id');
    }

    // ------------------------------------------------------------------ output helpers

    private function info(string $text): void
    {
        $this->sections[$this->current]['lines'][] = '   '.$text;
    }

    private function problem(string $text): void
    {
        $this->sections[$this->current]['lines'][] = '!! '.$text;
        $this->sections[$this->current]['problems'][] = $text;
    }

    private function data(string $key, mixed $value): void
    {
        $this->sections[$this->current]['data'][$key] = $value;
    }

    private function hasTable(string $table): bool
    {
        return $this->tables[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return $this->columns[$table.'.'.$column] ??= $this->hasTable($table) && Schema::hasColumn($table, $column);
    }

    /** Prints "<table>: yok" and returns false when a table is missing. */
    private function need(string ...$tables): bool
    {
        foreach ($tables as $table) {
            if (! $this->hasTable($table)) {
                $this->info($table.': yok');

                return false;
            }
        }

        return true;
    }

    private function ago(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'hiç';
        }
        try {
            $at = CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return (string) $value;
        }
        $minutes = (int) $at->diffInMinutes(now(), false);
        if ($minutes < 0) {
            return $at->format('Y-m-d H:i');
        }
        if ($minutes < 90) {
            return $minutes.' dk önce';
        }
        if ($minutes < 60 * 48) {
            return intdiv($minutes, 60).' sa önce';
        }

        return $at->format('Y-m-d').' ('.intdiv($minutes, 1440).' gün önce)';
    }

    private function daysSince(mixed $date): ?int
    {
        if ($date === null || $date === '') {
            return null;
        }
        try {
            return (int) CarbonImmutable::parse(substr((string) $date, 0, 10))->startOfDay()->diffInDays(now()->startOfDay(), false);
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>|Collection<string, mixed>  $counts */
    private function counts(array|Collection $counts): string
    {
        $parts = [];
        foreach ($counts as $key => $count) {
            $parts[] = ($key === '' || $key === null ? '—' : $key).'='.$count;
        }

        return $parts === [] ? '—' : implode(', ', $parts);
    }

    private function json(mixed $value, int $limit = 500): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
        }
        $text = is_string($value) ? $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return DiagnosticMasker::text($text, $limit);
    }

    /** @return array<string, mixed> */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A cache value without side effects: the database store deletes expired rows on get(), so its table is read
     * directly; in-memory / Redis stores are read through Cache.
     */
    private function cacheValue(string $key): mixed
    {
        $store = (string) config('cache.default');
        $driver = (string) config('cache.stores.'.$store.'.driver');
        if ($driver === 'database') {
            $table = (string) config('cache.stores.'.$store.'.table', 'cache');
            if (! $this->hasTable($table)) {
                return null;
            }
            $raw = DB::table($table)->where('key', config('cache.prefix', '').$key)->value('value');
            if ($raw === null) {
                return null;
            }
            $value = @unserialize((string) $raw, ['allowed_classes' => false]);
            if ($value === false && ($decoded = base64_decode((string) $raw, true)) !== false) {
                $value = @unserialize($decoded, ['allowed_classes' => false]);
            }

            return $value === false ? null : $value;
        }

        return in_array($driver, ['redis', 'array', 'memcached', 'dynamodb'], true) ? Cache::get($key) : null;
    }

    // ------------------------------------------------------------------ 1. environment

    private function environment(): void
    {
        $release = ReleaseInfo::current();
        $sha = $release['sha'] !== null ? substr($release['sha'], 0, 12) : $this->gitHead();
        $this->info(sprintf('Sürüm: commit %s%s · APP_ENV=%s · Laravel %s · PHP %s · DB %s',
            $sha ?? 'bilinmiyor',
            $release['deployed_at'] !== null ? ' (deploy '.$release['deployed_at'].')' : '',
            (string) config('app.env'), app()->version(), PHP_VERSION, DB::connection()->getDriverName()));
        $this->data('release', ['sha' => $sha, 'deployed_at' => $release['deployed_at'], 'env' => config('app.env'), 'laravel' => app()->version(), 'php' => PHP_VERSION]);
        if ((bool) config('app.debug') && app()->environment('production')) {
            $this->problem('APP_DEBUG açık (production)');
        }

        $queue = (string) config('queue.default');
        $queueDriver = (string) config('queue.connections.'.$queue.'.driver');
        $this->info(sprintf('Kuyruk: %s (%s) · cache: %s', $queue, $queueDriver, (string) config('cache.default')));
        $this->data('queue', ['connection' => $queue, 'driver' => $queueDriver]);
        if ($queueDriver === 'database' && $this->hasTable('jobs')) {
            $pending = DB::table('jobs')->selectRaw('queue, count(*) as n, min(available_at) as oldest')->groupBy('queue')->orderByDesc('n')->limit(10)->get();
            foreach ($pending as $row) {
                $waitMinutes = $row->oldest !== null ? (int) floor((time() - (int) $row->oldest) / 60) : 0;
                $line = sprintf('Bekleyen iş · %s: %d (en eski %d dk)', $row->queue, $row->n, max(0, $waitMinutes));
                $waitMinutes > 30 ? $this->problem($line) : $this->info($line);
            }
            $this->data('pending_jobs', $pending->map(fn (object $r): array => ['queue' => $r->queue, 'count' => (int) $r->n])->all());
        }

        $this->horizon($queueDriver);
        $this->heartbeats();

        $watchdog = $this->cacheValue(OpsWatchdog::LAST_RUN_KEY);
        $this->info('Watchdog son çalışma: '.(is_string($watchdog) ? $this->ago($watchdog) : 'kayıt yok / okunamadı'));

        if ($this->hasTable('system_backups')) {
            $backup = DB::table('system_backups')->orderByDesc('id')->first(['status', 'finished_at', 'error']);
            $lastOk = DB::table('system_backups')->whereIn('status', ['completed', 'success', 'succeeded', 'done'])->max('finished_at');
            $line = 'Yedek: son başarılı '.$this->ago($lastOk).($backup !== null ? ' · son deneme '.$backup->status.($backup->error ? ' · '.DiagnosticMasker::firstLine((string) $backup->error, 120) : '') : '');
            ($lastOk === null || ($this->daysSince($lastOk) ?? 99) > 2) ? $this->problem($line) : $this->info($line);
        }

        $this->failedJobs();
    }

    private function gitHead(): ?string
    {
        try {
            $head = base_path('.git/HEAD');
            if (! is_file($head)) {
                return null;
            }
            $content = trim((string) file_get_contents($head));
            if (str_starts_with($content, 'ref: ')) {
                $ref = base_path('.git/'.substr($content, 5));
                $content = is_file($ref) ? trim((string) file_get_contents($ref)) : '';
            }

            return preg_match('/^[0-9a-f]{7,40}$/', $content) === 1 ? substr($content, 0, 12) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function horizon(string $queueDriver): void
    {
        $repository = 'Laravel\\Horizon\\Contracts\\MasterSupervisorRepository';
        if ($queueDriver !== 'redis' || ! interface_exists($repository)) {
            $this->info('Horizon: kullanılmıyor (kuyruk sürücüsü '.$queueDriver.')');
            $this->data('horizon', 'not_used');

            return;
        }
        try {
            $masters = collect(app($repository)->all());
            $status = $masters->isEmpty() ? 'inactive' : ($masters->every(fn ($m): bool => ($m->status ?? '') === 'paused') ? 'paused' : 'running');
            $this->data('horizon', $status);
            $status === 'running' ? $this->info('Horizon: çalışıyor ('.$masters->count().' master)') : $this->problem('Horizon: '.$status);
        } catch (Throwable $error) {
            $this->info('Horizon: okunamadı ('.class_basename($error).')');
        }
    }

    private function heartbeats(): void
    {
        if ($this->hasTable('ops_dispatcher_heartbeats')) {
            $beats = DB::table('ops_dispatcher_heartbeats')->orderByDesc('last_seen_at')->limit(10)->get(['dispatcher_key', 'last_seen_at']);
            $last = $beats->first()?->last_seen_at;
            $minutes = $last !== null ? (int) CarbonImmutable::parse((string) $last)->diffInMinutes(now()) : null;
            $line = 'Zamanlayıcı (schedule) son kalp atışı: '.$this->ago($last).($beats->count() > 1 ? ' · '.$beats->map(fn (object $b): string => $b->dispatcher_key.' '.$this->ago($b->last_seen_at))->implode(', ') : '');
            ($minutes === null || $minutes > 15) ? $this->problem($line) : $this->info($line);
            $this->data('scheduler_last_seen_at', $last);
        } else {
            $this->info('ops_dispatcher_heartbeats: yok');
        }

        if ($this->hasTable('worker_heartbeats')) {
            $stale = max(3, (int) ceil((int) config('moxdop-observability.worker.heartbeat_stale_seconds', 180) / 60));
            $workers = DB::table('worker_heartbeats')->orderBy('worker_id')->limit(50)->get(['worker_id', 'last_seen_at']);
            $silent = $workers->filter(function (object $w) use ($stale): bool {
                $limit = str_starts_with((string) $w->worker_id, 'queue:') ? max($stale, 12) : $stale;

                return $w->last_seen_at === null || CarbonImmutable::parse((string) $w->last_seen_at)->diffInMinutes(now()) > $limit;
            });
            $line = sprintf('İşçiler: %d kayıtlı, %d sessiz%s', $workers->count(), $silent->count(),
                $silent->isNotEmpty() ? ' ('.$silent->take(8)->map(fn (object $w): string => $w->worker_id.' '.$this->ago($w->last_seen_at))->implode(', ').')' : '');
            ($workers->isEmpty() || $silent->isNotEmpty()) ? $this->problem($line) : $this->info($line);
            $this->data('workers', ['total' => $workers->count(), 'silent' => $silent->pluck('worker_id')->values()->all()]);
        }
    }

    private function failedJobs(): void
    {
        if (! $this->need('failed_jobs')) {
            return;
        }
        $day = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
        $week = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->count();
        $line = sprintf('Başarısız işler: son 24 saat %d · son 7 gün %d', $day, $week);
        $day > 0 ? $this->problem($line) : $this->info($line);
        $this->data('failed_jobs', ['24h' => $day, '7d' => $week]);
        if ($week === 0) {
            return;
        }

        // Only the head of payload / exception is read: the display name sits at the start of the payload JSON.
        $rows = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDays(7))->orderByDesc('id')->limit(5000)
            ->selectRaw('substr(payload, 1, 400) as head, substr(exception, 1, 600) as ex, failed_at')->get();
        $groups = [];
        foreach ($rows as $row) {
            $job = preg_match('/"displayName"\s*:\s*"((?:[^"\\\\]|\\\\.)*)"/', (string) $row->head, $m) === 1 ? stripslashes($m[1]) : '?';
            $key = $job.' — '.DiagnosticMasker::firstLine((string) $row->ex, 180);
            $groups[$key] ??= ['count' => 0, 'last' => (string) $row->failed_at, 'job' => $job];
            $groups[$key]['count']++;
        }
        uasort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $top = array_slice($groups, 0, 15, true);
        foreach ($top as $label => $group) {
            $this->problem(sprintf('  ×%d (son %s) %s', $group['count'], $this->ago($group['last']), $label));
        }
        $this->data('failed_job_groups', array_map(fn (string $label, array $g): array => ['label' => $label, 'count' => $g['count'], 'last' => $g['last']], array_keys($top), $top));
    }

    // ------------------------------------------------------------------ 2. ownership

    private function ownership(): void
    {
        if (! $this->need('customers', 'brands', 'digital_assets')) {
            return;
        }
        $customers = DB::table('customers')->whereNull('deleted_at')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->info('Müşteriler: '.$this->counts($customers));

        $brandRows = DB::table('brands as b')->leftJoin('customers as c', 'c.id', '=', 'b.customer_id')->whereNull('b.deleted_at')
            ->selectRaw("case when c.id is null or c.deleted_at is not null then 'musterisiz' when c.status = 'active' then 'isletimde' else 'pasif_musteri' end as state, count(*) as n")
            ->groupByRaw('1')->pluck('n', 'state');
        $this->info('Markalar: '.$this->counts($brandRows));
        $this->data('customers', $customers->all());
        $this->data('brands', $brandRows->all());
        if ((int) ($brandRows['musterisiz'] ?? 0) > 0) {
            $names = DB::table('brands as b')->leftJoin('customers as c', 'c.id', '=', 'b.customer_id')->whereNull('b.deleted_at')
                ->where(fn ($q) => $q->whereNull('c.id')->orWhereNotNull('c.deleted_at'))->limit(10)->get(['b.id', 'b.name']);
            $this->problem('Müşterisi olmayan marka: '.$names->map(fn (object $b): string => '#'.$b->id.' '.$b->name)->implode(', '));
        }

        $this->assetMatrix();
        $this->passiveCustomerAssets();
        $this->mergedAssets();
        $this->duplicateWebsites();
        $this->bindingIntegrity();

        $empty = DB::table('brands as b')->join('customers as c', 'c.id', '=', 'b.customer_id')->whereNull('b.deleted_at')->where('c.status', 'active')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('digital_assets as a')->whereColumn('a.brand_id', 'b.id')->whereNull('a.deleted_at'))
            ->when($this->deep(), fn ($q) => $q->whereIn('b.id', $this->brandIds() ?: [0]))
            ->orderBy('b.name')->limit(20)->get(['b.id', 'b.name']);
        if ($empty->isNotEmpty()) {
            $this->problem('Varlığı olmayan işletimdeki marka: '.$empty->map(fn (object $b): string => '#'.$b->id.' '.$b->name)->implode(', '));
        }
    }

    private function assetMatrix(): void
    {
        $hasBindings = $this->hasTable('core_asset_bindings');
        $operational = "case when a.status = 'active' and c.status = 'active' and b.deleted_at is null and c.deleted_at is null then 1 else 0 end";
        $query = DB::table('digital_assets as a')
            ->leftJoin('brands as b', 'b.id', '=', 'a.brand_id')
            ->leftJoin('customers as c', 'c.id', '=', 'b.customer_id')
            ->whereNull('a.deleted_at')
            ->when($this->scopeAssetIds !== null, fn ($q) => $q->whereIn('a.id', $this->scopeAssetIds ?: [0]));
        if ($hasBindings) {
            $bound = DB::table('core_asset_bindings')->where('status', 'active')->select('digital_asset_id')->distinct();
            $query->leftJoinSub($bound, 'bb', 'bb.digital_asset_id', '=', 'a.id')
                ->selectRaw("a.type, case when bb.digital_asset_id is null then 0 else 1 end as bound, {$operational} as operational, count(*) as n");
        } else {
            $query->selectRaw("a.type, 0 as bound, {$operational} as operational, count(*) as n");
        }
        $rows = $query->groupByRaw('1, 2, 3')->get();
        $matrix = [];
        foreach ($rows as $row) {
            $type = (string) $row->type;
            $matrix[$type] ??= ['total' => 0, 'operational' => 0, 'bound' => 0, 'operational_unbound' => 0];
            $matrix[$type]['total'] += (int) $row->n;
            $matrix[$type]['operational'] += (int) $row->operational === 1 ? (int) $row->n : 0;
            $matrix[$type]['bound'] += (int) $row->bound === 1 ? (int) $row->n : 0;
            $matrix[$type]['operational_unbound'] += ((int) $row->operational === 1 && (int) $row->bound === 0) ? (int) $row->n : 0;
        }
        ksort($matrix);
        $this->data('assets', $matrix);
        foreach ($matrix as $type => $m) {
            $this->info(sprintf('Varlık %s: toplam %d · işletimde %d · bağlı %d · bağsız %d', $type, $m['total'], $m['operational'], $m['bound'], $m['total'] - $m['bound']));
        }
        if (! $hasBindings) {
            return;
        }
        // Websites are reached by their URL / connector; every other operational type needs a bound provider resource.
        $unbound = DB::table('digital_assets as a')->join('brands as b', 'b.id', '=', 'a.brand_id')->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->whereNull('a.deleted_at')->whereNull('b.deleted_at')->where('a.status', 'active')->where('c.status', 'active')
            ->whereIn('a.type', ['ga4', 'gsc', 'google_ads', 'meta_ads', 'google_business_profile'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('core_asset_bindings as x')->whereColumn('x.digital_asset_id', 'a.id')->where('x.status', 'active'))
            ->when($this->scopeAssetIds !== null, fn ($q) => $q->whereIn('a.id', $this->scopeAssetIds ?: [0]))
            ->orderBy('a.type')->limit(30)->get(['a.id', 'a.type', 'a.name', 'b.name as brand']);
        foreach ($unbound->groupBy('type') as $type => $assets) {
            $this->problem(sprintf('İşletimde ama kaynağa bağlı değil (%s): %s', $type, $assets->take(10)->map(fn (object $a): string => '#'.$a->id.' '.$a->name.' ['.$a->brand.']')->implode(', ')));
        }
    }

    private function passiveCustomerAssets(): void
    {
        $rows = DB::table('digital_assets as a')->join('brands as b', 'b.id', '=', 'a.brand_id')->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->whereNull('a.deleted_at')->where('c.status', '!=', 'active')
            ->selectRaw('a.type, count(*) as n')->groupBy('a.type')->pluck('n', 'type');
        $this->info('Pasif müşterinin varlıkları: '.$this->counts($rows));
        $this->data('passive_customer_assets', $rows->all());
        if (! $this->hasTable('core_asset_bindings') || ! $this->hasTable('resource_automations')) {
            return;
        }
        $collecting = DB::table('digital_assets as a')->join('brands as b', 'b.id', '=', 'a.brand_id')->join('customers as c', 'c.id', '=', 'b.customer_id')
            ->join('core_asset_bindings as bd', fn ($j) => $j->on('bd.digital_asset_id', '=', 'a.id')->where('bd.status', 'active'))
            ->join('resource_automations as ra', 'ra.external_resource_id', '=', 'bd.external_resource_id')
            ->whereNull('a.deleted_at')->where('c.status', '!=', 'active')->where('ra.collection_enabled', true)
            ->limit(15)->get(['a.id', 'a.name', 'a.type']);
        if ($collecting->isNotEmpty()) {
            $this->problem('Pasif müşteri varlığında otomatik toplama açık: '.$collecting->map(fn (object $a): string => '#'.$a->id.' '.$a->name.' ('.$a->type.')')->implode(', '));
        }
    }

    private function mergedAssets(): void
    {
        if (! $this->hasColumn('digital_assets', 'merged_into_asset_id')) {
            $this->info('digital_assets.merged_into_asset_id: yok');

            return;
        }
        $merged = DB::table('digital_assets')->whereNotNull('merged_into_asset_id')->whereNull('deleted_at')
            ->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $this->info('Birleştirilmiş (merged_into_asset_id dolu, silinmemiş): '.$this->counts($merged));
        $active = DB::table('digital_assets')->whereNotNull('merged_into_asset_id')->whereNull('deleted_at')->where('status', 'active')
            ->limit(10)->get(['id', 'name', 'merged_into_asset_id']);
        if ($active->isNotEmpty()) {
            $this->problem('Birleştirilmiş ama hâlâ aktif varlık: '.$active->map(fn (object $a): string => '#'.$a->id.' '.$a->name.' → #'.$a->merged_into_asset_id)->implode(', '));
        }
    }

    private function duplicateWebsites(): void
    {
        $sites = DB::table('digital_assets')->where('type', 'website')->whereNull('deleted_at')
            ->when($this->hasColumn('digital_assets', 'merged_into_asset_id'), fn ($q) => $q->whereNull('merged_into_asset_id'))
            ->limit(5000)->get(['id', 'name', 'domain', 'primary_url', 'brand_id', 'status']);
        $byHost = [];
        foreach ($sites as $site) {
            $host = BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain));
            if ($host !== '') {
                $byHost[$host][] = $site;
            }
        }
        $duplicates = array_filter($byHost, fn (array $group): bool => count($group) > 1);
        $this->data('duplicate_website_hosts', array_map(fn (array $g): array => array_map(fn (object $s): int => (int) $s->id, $g), $duplicates));
        foreach (array_slice($duplicates, 0, 15, true) as $host => $group) {
            $this->problem(sprintf('Aynı alan adına birden çok web sitesi varlığı: %s → %s', $host,
                implode(', ', array_map(fn (object $s): string => '#'.$s->id.' ('.$s->status.', marka '.($s->brand_id ?? '—').')', $group))));
        }
        if ($duplicates === []) {
            $this->info('Yinelenen web sitesi alan adı: yok ('.count($byHost).' site)');
        }
    }

    private function bindingIntegrity(): void
    {
        if (! $this->need('core_asset_bindings', 'core_external_resources')) {
            return;
        }
        $multi = DB::table('core_asset_bindings')->where('status', 'active')
            ->selectRaw('external_resource_id, count(distinct digital_asset_id) as n')->groupBy('external_resource_id')
            ->havingRaw('count(distinct digital_asset_id) > 1')->limit(20)->get();
        foreach ($multi as $row) {
            $assets = DB::table('core_asset_bindings')->where('status', 'active')->where('external_resource_id', $row->external_resource_id)->pluck('digital_asset_id')->implode(', #');
            $this->problem(sprintf('Kaynak #%d birden çok varlığa bağlı: #%s', $row->external_resource_id, $assets));
        }

        $rows = DB::table('core_asset_bindings as bd')
            ->join('core_external_resources as r', 'r.id', '=', 'bd.external_resource_id')
            ->leftJoin('digital_assets as a', 'a.id', '=', 'bd.digital_asset_id')
            ->where('bd.status', 'active')
            ->when($this->scopeAssetIds !== null, fn ($q) => $q->whereIn('bd.digital_asset_id', $this->scopeAssetIds ?: [0]))
            ->limit(5000)
            ->get(['bd.id', 'bd.digital_asset_id', 'bd.capability', 'r.id as resource_id', 'r.provider', 'r.resource_type', 'r.status as resource_status', 'a.type as asset_type', 'a.deleted_at', 'a.name']);
        $mismatch = $rows->filter(fn (object $r): bool => $r->asset_type !== null
            && ExternalResourceAssetCompatibility::compatibleAssetTypes((string) $r->resource_type) !== []
            && ! ExternalResourceAssetCompatibility::canBindResourceToAssetType((string) $r->resource_type, (string) $r->asset_type));
        foreach ($mismatch->take(15) as $r) {
            $this->problem(sprintf('Tür uyuşmazlığı: varlık #%d %s (%s) ← kaynak #%d %s/%s', $r->digital_asset_id, $r->name, $r->asset_type, $r->resource_id, $r->provider, $r->resource_type));
        }
        $orphan = $rows->filter(fn (object $r): bool => $r->asset_type === null || $r->deleted_at !== null);
        if ($orphan->isNotEmpty()) {
            $this->problem('Silinmiş / olmayan varlığa aktif bağlama: '.$orphan->count().' (bağlama #'.$orphan->take(10)->pluck('id')->implode(', #').')');
        }
        $unavailable = $rows->filter(fn (object $r): bool => $r->resource_status !== null && $r->resource_status !== 'available');
        if ($unavailable->isNotEmpty()) {
            $this->problem('Kullanılamaz (unavailable) kaynağa aktif bağlama: '.$unavailable->take(10)->map(fn (object $r): string => '#'.$r->digital_asset_id.' '.$r->name.' ← '.$r->resource_type)->implode(', '));
        }
        $this->info(sprintf('Aktif bağlama: %d · tür uyuşmazlığı %d · çoklu bağlı kaynak %d', $rows->count(), $mismatch->count(), $multi->count()));
        $this->data('bindings', ['active' => $rows->count(), 'type_mismatch' => $mismatch->count(), 'multi_bound_resources' => $multi->count(), 'orphan' => $orphan->count(), 'unavailable' => $unavailable->count()]);
    }

    // ------------------------------------------------------------------ 3. integrations

    private function integrations(): void
    {
        if (! $this->need('core_integrations')) {
            return;
        }
        $integrations = DB::table('core_integrations')->orderBy('provider')->get(['id', 'provider', 'name', 'status', 'config', 'last_success_at', 'last_error']);
        $bound = $this->hasTable('core_asset_bindings')
            ? DB::table('core_asset_bindings')->where('status', 'active')->select('external_resource_id')->distinct()
            : null;
        $out = [];
        foreach ($integrations as $integration) {
            $config = $this->decode($integration->config);
            $credentials = $this->hasTable('core_integration_credentials')
                ? DB::table('core_integration_credentials')->where('integration_id', $integration->id)->get(['credential_type', 'expires_at', 'refreshed_at'])
                : collect();
            $expires = $config['refresh_token_expires_at'] ?? $credentials->where('credential_type', 'authorization')->max('expires_at');
            $token = $credentials->isEmpty() ? 'kimlik bilgisi yok' : 'unknown';
            $expiresInDays = null;
            if (filled($expires)) {
                try {
                    $expiresInDays = (int) floor(now()->diffInDays(CarbonImmutable::parse((string) $expires), false));
                    $token = $expiresInDays < 0 ? 'expired' : 'valid ('.$expiresInDays.' gün)';
                } catch (Throwable) {
                    $token = 'unknown';
                }
            } elseif ($credentials->isNotEmpty() && ! in_array($config['auth_status'] ?? '', self::BAD_AUTH, true)) {
                $token = 'valid (süresiz / bilinmiyor)';
            }
            $auth = (string) ($config['auth_status'] ?? $config['connection_status'] ?? '');
            $line = sprintf('%s · %s · durum %s%s · token %s · son başarı %s%s', $integration->provider, $integration->name, $integration->status,
                $auth !== '' ? ' / '.$auth : '', $token, $this->ago($integration->last_success_at),
                filled($integration->last_error) ? ' · hata: '.DiagnosticMasker::firstLine((string) $integration->last_error, 200) : '');
            $bad = $integration->status === 'active' && (str_starts_with($token, 'expired') || in_array($auth, self::BAD_AUTH, true) || $auth === 'issue'
                || filled($integration->last_error) || ($expiresInDays !== null && $expiresInDays <= 7)
                || (in_array($integration->provider, ['google', 'meta'], true) && $credentials->isEmpty()));
            $bad ? $this->problem($line) : $this->info($line);

            $discovery = $this->lastDiscovery((string) $integration->provider, (int) $integration->id);
            if ($discovery !== null) {
                $discoveryLine = '  keşif: '.$discovery['status'].' · '.$this->ago($discovery['at']).($discovery['error'] !== '' ? ' · '.$discovery['error'] : '').' · görülen '.$discovery['seen'];
                in_array($discovery['status'], self::FAILED, true) ? $this->problem($discoveryLine) : $this->info($discoveryLine);
            }

            $resources = [];
            if ($this->hasTable('core_external_resources')) {
                $query = DB::table('core_external_resources as r')->where('r.integration_id', $integration->id);
                if ($bound !== null) {
                    $query->leftJoinSub($bound, 'bb', 'bb.external_resource_id', '=', 'r.id')
                        ->selectRaw('r.resource_type, count(*) as n, sum(case when r.status = \'available\' then 1 else 0 end) as available, count(bb.external_resource_id) as bound, max(r.last_seen_at) as last_seen, max(r.discovered_at) as discovered')
                        ->selectRaw('sum(case when bb.external_resource_id is not null and (r.last_seen_at is null or r.last_seen_at < ?) then 1 else 0 end) as bound_unseen', [now()->subDays(7)]);
                } else {
                    $query->selectRaw('r.resource_type, count(*) as n, sum(case when r.status = \'available\' then 1 else 0 end) as available, 0 as bound, max(r.last_seen_at) as last_seen, max(r.discovered_at) as discovered, 0 as bound_unseen');
                }
                foreach ($query->groupBy('r.resource_type')->get() as $row) {
                    $resources[$row->resource_type] = ['discovered' => (int) $row->n, 'available' => (int) $row->available, 'bound' => (int) $row->bound, 'last_seen' => $row->last_seen];
                    $this->info(sprintf('  %s: %d bulundu (%d available) · %d bağlı · son görülme %s', $row->resource_type, $row->n, $row->available, $row->bound, $this->ago($row->last_seen)));
                    if ((int) $row->bound_unseen > 0) {
                        $this->problem(sprintf('  %s/%s: %d bağlı kaynak 7+ gündür keşifte görülmedi', $integration->provider, $row->resource_type, $row->bound_unseen));
                    }
                }
            }
            $out[] = ['provider' => $integration->provider, 'name' => $integration->name, 'status' => $integration->status, 'auth' => $auth, 'token' => $token,
                'last_success_at' => $integration->last_success_at, 'discovery' => $discovery, 'resources' => $resources];
        }
        $this->data('integrations', $out);
        $this->connections();
    }

    /** @return array{status: string, at: ?string, error: string, seen: int}|null */
    private function lastDiscovery(string $provider, int $integrationId): ?array
    {
        $table = match ($provider) {
            'google' => 'google_integration_discovery_attempts',
            'meta' => 'meta_integration_discovery_attempts',
            default => null,
        };
        if ($table === null || ! $this->hasTable($table)) {
            return null;
        }
        $row = DB::table($table)->where('integration_id', $integrationId)->orderByDesc('id')->first(['status', 'finished_at', 'started_at', 'error_category', 'safe_error_message', 'resources_seen']);
        if ($row === null) {
            return ['status' => 'hiç çalışmamış', 'at' => null, 'error' => '', 'seen' => 0];
        }

        return [
            'status' => (string) $row->status,
            'at' => $row->finished_at ?? $row->started_at,
            'error' => trim(($row->error_category ?? '').' '.DiagnosticMasker::firstLine((string) $row->safe_error_message, 160)),
            'seen' => (int) $row->resources_seen,
        ];
    }

    private function connections(): void
    {
        if (! $this->hasTable('core_connections')) {
            $this->info('core_connections: yok');

            return;
        }
        $types = DB::table('core_connections')
            ->when($this->scopeAssetIds !== null, fn ($q) => $q->whereIn('digital_asset_id', $this->scopeAssetIds ?: [0]))
            ->selectRaw('type, count(*) as n, sum(case when enabled then 1 else 0 end) as enabled, sum(case when last_error is not null and last_error <> \'\' then 1 else 0 end) as errors, max(last_success_at) as last_success')
            ->groupBy('type')->get();
        foreach ($types as $row) {
            $line = sprintf('Bağlantı %s: %d (açık %d) · hatalı %d · son başarı %s', $row->type, $row->n, $row->enabled, $row->errors, $this->ago($row->last_success));
            (int) $row->errors > 0 ? $this->problem($line) : $this->info($line);
        }
        $this->data('connections', $types->map(fn (object $r): array => (array) $r)->all());
        if (! $this->hasTable('website_connector_delivery')) {
            return;
        }
        $current = (string) config('moxdop-wordpress.connector_version', '');
        $plugins = DB::table('core_connections as c')->leftJoin('website_connector_delivery as d', 'd.connection_id', '=', 'c.id')
            ->leftJoin('digital_assets as a', 'a.id', '=', 'c.digital_asset_id')->where('c.type', 'wordpress_connector')
            ->when($this->scopeAssetIds !== null, fn ($q) => $q->whereIn('c.digital_asset_id', $this->scopeAssetIds ?: [0]))
            ->orderBy('a.name')->limit(60)
            ->get(['c.id', 'c.enabled', 'a.id as asset_id', 'a.name', 'a.domain', 'd.plugin_version', 'd.last_received_at', 'd.last_error', 'd.last_inventory_at']);
        $this->info('WordPress Connector güncel sürüm: '.($current !== '' ? $current : 'bilinmiyor'));
        foreach ($plugins as $p) {
            $outdated = $current !== '' && ($p->plugin_version === null || version_compare((string) $p->plugin_version, $current, '<'));
            $silent = $p->last_received_at === null || CarbonImmutable::parse((string) $p->last_received_at)->lt(now()->subDay());
            $line = sprintf('  WP #%s %s: eklenti %s · son veri %s · envanter %s%s', $p->asset_id ?? '—', $p->name ?: $p->domain ?: '—', $p->plugin_version ?? 'yok',
                $this->ago($p->last_received_at), $this->ago($p->last_inventory_at), filled($p->last_error) ? ' · hata: '.DiagnosticMasker::firstLine((string) $p->last_error, 160) : '');
            ((bool) $p->enabled && ($outdated || $silent || filled($p->last_error))) ? $this->problem($line) : $this->info($line);
        }
    }

    // ------------------------------------------------------------------ 4. collection

    private function collection(): void
    {
        if (! $this->need('core_asset_bindings', 'core_external_resources', 'digital_assets')) {
            return;
        }
        $hasAutomation = $this->hasTable('resource_automations');
        $hasActivity = $this->hasTable('resource_activity');
        $query = DB::table('core_asset_bindings as bd')
            ->join('core_external_resources as r', 'r.id', '=', 'bd.external_resource_id')
            ->join('digital_assets as a', 'a.id', '=', 'bd.digital_asset_id')
            ->leftJoin('brands as b', 'b.id', '=', 'a.brand_id')
            ->where('bd.status', 'active')->whereNull('a.deleted_at');
        $select = ['bd.digital_asset_id', 'a.name as asset_name', 'a.type as asset_type', 'b.name as brand_name', 'r.id as resource_id', 'r.provider', 'r.resource_type', 'r.external_id', 'r.display_name', 'r.status as resource_status'];
        if ($hasAutomation) {
            $query->leftJoin('resource_automations as ra', 'ra.external_resource_id', '=', 'r.id');
            array_push($select, 'ra.id as automation_id', 'ra.collection_enabled', 'ra.interval_days', 'ra.collection_status', 'ra.next_collection_at', 'ra.collection_queued_at',
                'ra.collection_run_id', 'ra.last_collection_success_at', 'ra.data_through', 'ra.collection_error', 'ra.collection_failures', 'ra.query_error');
            if ($this->hasColumn('resource_automations', 'gbp_run_id')) {
                $select[] = 'ra.gbp_run_id';
            }
        }
        if ($hasActivity) {
            $query->leftJoin('resource_activity as act', 'act.external_resource_id', '=', 'r.id');
            array_push($select, 'act.tier', 'act.operator_paused_at', 'act.last_full_collection_at');
        }
        if ($this->scopeAssetIds !== null) {
            $query->whereIn('a.id', $this->scopeAssetIds ?: [0]);
        } else {
            $query->whereIn('a.id', DigitalAsset::query()->operational()->select('digital_assets.id'));
        }
        $rows = $query->orderBy('a.id')->limit(1500)->get($select);
        $this->info(sprintf('%d aktif bağlama (kaynak) inceleniyor · pencere %d gün', $rows->count(), $this->days));

        $analyzed = $rows->map(fn (object $row): array => $this->analyzeResource($row));
        $datasets = $this->deep() ? $this->datasetHistory($rows) : [];

        $list = $this->deep() ? $analyzed : $analyzed->sortByDesc('score')->take(50);
        if (! $this->deep()) {
            $this->info(sprintf('Sorunlu kaynak: %d (en kötü 50 gösteriliyor)', $analyzed->where('score', '>', 0)->count()));
        }
        foreach ($list as $item) {
            $item['score'] > 0 ? $this->problem($item['line']) : $this->info($item['line']);
            foreach ($item['details'] as $detail) {
                $this->info('    '.$detail);
            }
            foreach ($datasets[$item['resource_id']] ?? [] as $dataset) {
                $dataset['problem'] ? $this->problem('    '.$dataset['line']) : $this->info('    '.$dataset['line']);
            }
        }
        $this->data('resources', $list->map(fn (array $i): array => array_diff_key($i, ['line' => 1, 'details' => 1]))->values()->all());
        $this->data('datasets', $datasets);
    }

    /** @return array<string, mixed> */
    private function analyzeResource(object $row): array
    {
        $type = (string) $row->resource_type;
        $issues = [];
        $score = 0;
        $interval = max(1, (int) ($row->interval_days ?? 1));
        [$factTable, $lag] = self::FACT_TABLES[$type] ?? [null, 3];
        $latest = null;
        if ($factTable !== null && $this->hasColumn($factTable, 'external_resource_id')) {
            $latest = DB::table($factTable)->where('external_resource_id', $row->resource_id)->max('reporting_date');
            $latest = $latest !== null ? substr((string) $latest, 0, 10) : null;
        }
        $age = $this->daysSince($latest);
        $stale = $factTable !== null && ($age === null || $age > $lag + $interval + 1);
        if ($factTable !== null && $latest === null) {
            $issues[] = 'VERİ YOK ('.$factTable.')';
            $score += 3;
        } elseif ($stale) {
            $issues[] = 'STALE: son veri '.$latest.' ('.$age.' gün; beklenen ≤ '.($lag + $interval + 1).')';
            $score += 2;
        }
        if (property_exists($row, 'automation_id')) {
            if ($row->automation_id === null) {
                $issues[] = 'otomasyon kaydı yok';
                $score += 2;
            } else {
                if (! (bool) $row->collection_enabled) {
                    $issues[] = 'otomatik toplama KAPALI';
                    $score += 1;
                }
                if ((int) $row->collection_failures > 0) {
                    $issues[] = 'art arda hata '.(int) $row->collection_failures;
                    $score += min(5, (int) $row->collection_failures);
                }
                if (in_array((string) $row->collection_status, ['attention', 'failed', 'stopped', 'reconnect', 'binding', 'customer_passive'], true)) {
                    $issues[] = 'durum '.$row->collection_status;
                    $score += 2;
                }
                if ($row->collection_queued_at !== null && CarbonImmutable::parse((string) $row->collection_queued_at)->lt(now()->subHours(6))) {
                    $issues[] = 'kuyrukta 6+ saattir ('.$this->ago($row->collection_queued_at).')';
                    $score += 2;
                }
            }
        }
        if (($row->operator_paused_at ?? null) !== null) {
            $issues[] = 'operatör durdurdu ('.$this->ago($row->operator_paused_at).')';
        }
        if ($row->resource_status !== null && $row->resource_status !== 'available') {
            $issues[] = 'kaynak '.$row->resource_status;
            $score += 2;
        }

        $line = sprintf('#%d %s [%s] · %s/%s %s (%s)%s', $row->digital_asset_id, $row->asset_name, $row->brand_name ?? 'markasız', $row->provider, $type,
            $row->display_name ?: '', DiagnosticMasker::id((string) $row->external_id), $issues !== [] ? ' — '.implode('; ', $issues) : '');
        $details = [];
        if (property_exists($row, 'automation_id') && $row->automation_id !== null) {
            $details[] = sprintf('otomasyon: %s · her %d gün · durum %s · son başarı %s · veri sonu %s · sonraki %s%s', (bool) $row->collection_enabled ? 'açık' : 'kapalı',
                $interval, $row->collection_status ?: 'bekliyor', $this->ago($row->last_collection_success_at), $row->data_through !== null ? substr((string) $row->data_through, 0, 10) : '—',
                $this->ago($row->next_collection_at), property_exists($row, 'tier') && $row->tier !== null ? ' · aktivite '.$row->tier : '');
            if (filled($row->collection_error)) {
                $details[] = 'son hata: '.DiagnosticMasker::firstLine((string) $row->collection_error, 200);
            }
            if (filled($row->query_error)) {
                $details[] = 'sorgu hatası: '.DiagnosticMasker::firstLine((string) $row->query_error, 200);
            }
        }
        $details[] = 'son veri tarihi: '.($factTable !== null ? $factTable.'='.($latest ?? 'yok') : '—').$this->detailFacts($row);
        if (($row->gbp_run_id ?? null) !== null && $this->hasTable('runs')) {
            $run = DB::table('runs')->where('id', $row->gbp_run_id)->first(['status', 'started_at', 'finished_at']);
            if ($run !== null) {
                $details[] = 'GBP çalıştırması #'.$row->gbp_run_id.': '.$run->status.' · '.$this->ago($run->finished_at ?? $run->started_at);
            }
        }

        return [
            'asset_id' => (int) $row->digital_asset_id, 'resource_id' => (int) $row->resource_id, 'provider' => $row->provider, 'resource_type' => $type,
            'latest_data' => $latest, 'stale' => $stale, 'issues' => $issues, 'score' => $score, 'line' => $line, 'details' => $details,
        ];
    }

    /** Latest dates in the detail fact tables, only for a --brand / --asset scope. */
    private function detailFacts(object $row): string
    {
        if (! $this->deep()) {
            return '';
        }
        $parts = [];
        foreach (self::DETAIL_FACT_TABLES[(string) $row->resource_type] ?? [] as $table) {
            if (! $this->hasColumn($table, 'external_resource_id')) {
                continue;
            }
            $max = DB::table($table)->where('digital_asset_id', $row->digital_asset_id)->where('external_resource_id', $row->resource_id)->max('reporting_date');
            $parts[] = $table.'='.($max !== null ? substr((string) $max, 0, 10) : 'yok');
        }
        if ($row->resource_type === 'google_business_profile' && $this->hasTable('gbp_reviews')) {
            $parts[] = 'gbp_reviews toplandı='.$this->ago(DB::table('gbp_reviews')->where('external_resource_id', $row->resource_id)->max('collected_at'));
        }

        return $parts === [] ? '' : ', '.implode(', ', $parts);
    }

    /**
     * Per resource and dataset: last attempt, last success, consecutive failures and the last error, from the
     * scope's recent collection runs (indexed by asset / run, never a full scan).
     *
     * @param  Collection<int, object>  $rows
     * @return array<int, list<array{dataset: string, line: string, problem: bool}>>
     */
    private function datasetHistory(Collection $rows): array
    {
        if (! $this->hasTable('collection_runs') || ! $this->hasTable('collection_resource_runs') || ! $this->hasTable('collection_dataset_runs')) {
            return [];
        }
        $resourceIds = $rows->pluck('resource_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        if ($resourceIds === []) {
            return [];
        }
        $since = now()->subDays($this->days);
        $runIds = DB::table('collection_runs')->whereIn('digital_asset_id', $this->scopeAssetIds ?: [0])->where('created_at', '>=', $since)
            ->orderByDesc('id')->limit(300)->pluck('id');
        $runIds = $runIds->merge($rows->pluck('collection_run_id')->filter())->map(fn ($id): int => (int) $id)->unique()->values();
        // Resource-first automatic runs may carry no asset: find them through the resource runs of the window.
        $resourceRuns = collect();
        foreach ($runIds->chunk(200) as $chunk) {
            $resourceRuns = $resourceRuns->merge(DB::table('collection_resource_runs')->whereIn('collection_run_id', $chunk->all())
                ->whereIn('external_resource_id', $resourceIds)->get(['id', 'external_resource_id']));
        }
        try {
            $extra = DB::table('collection_resource_runs')->whereIn('external_resource_id', $resourceIds)->where('created_at', '>=', $since)
                ->orderByDesc('id')->limit(500)->get(['id', 'external_resource_id']);
        } catch (Throwable) {
            $extra = collect();
        }
        $resourceRuns = $resourceRuns->merge($extra)->unique('id');
        $byRun = $resourceRuns->pluck('external_resource_id', 'id');

        $datasetRuns = collect();
        foreach ($byRun->keys()->chunk(200) as $chunk) {
            $datasetRuns = $datasetRuns->merge(DB::table('collection_dataset_runs')->whereIn('collection_resource_run_id', $chunk->all())
                ->orderByDesc('id')->limit(4000)
                ->get(['id', 'collection_resource_run_id', 'dataset_contract_id', 'status', 'attempt_count', 'started_at', 'finished_at', 'rows_written', 'error_code', 'error_message']));
        }

        $out = [];
        foreach ($datasetRuns->sortByDesc('id')->groupBy(fn (object $d): string => $byRun[$d->collection_resource_run_id].'|'.$d->dataset_contract_id) as $key => $runs) {
            [$resourceId, $dataset] = explode('|', (string) $key, 2);
            $last = $runs->first();
            $lastSuccess = $runs->first(fn (object $d): bool => in_array((string) $d->status, self::SUCCEEDED, true));
            $consecutive = 0;
            foreach ($runs as $run) {
                if (! in_array((string) $run->status, self::FAILED, true)) {
                    break;
                }
                $consecutive++;
            }
            $lastFailed = $runs->first(fn (object $d): bool => in_array((string) $d->status, self::FAILED, true));
            $problem = $consecutive > 0 || $lastSuccess === null;
            $line = sprintf('%s: son deneme %s (%s) · son başarı %s · art arda hata %d%s%s', $dataset, $last->status, $this->ago($last->finished_at ?? $last->started_at),
                $lastSuccess !== null ? $this->ago($lastSuccess->finished_at) : 'yok ('.$this->days.' gün)', $consecutive,
                $lastSuccess !== null ? ' · satır '.(int) $lastSuccess->rows_written : '',
                $lastFailed !== null ? ' · son hata: '.trim(($lastFailed->error_code ?? '').' '.DiagnosticMasker::firstLine((string) $lastFailed->error_message, 200)) : '');
            $out[(int) $resourceId][] = ['dataset' => $dataset, 'line' => $line, 'problem' => $problem];
        }
        foreach ($out as &$list) {
            usort($list, fn (array $a, array $b): int => [$b['problem'], $a['dataset']] <=> [$a['problem'], $b['dataset']]);
        }

        return $out;
    }

    // ------------------------------------------------------------------ 5. website

    private function website(): void
    {
        if (! $this->need('digital_assets')) {
            return;
        }
        $sites = DB::table('digital_assets')->where('type', 'website')->whereNull('deleted_at')
            ->when($this->scopeAssetIds !== null, fn ($q) => $q->whereIn('id', $this->scopeAssetIds ?: [0]))
            ->when($this->scopeAssetIds === null, fn ($q) => $q->whereIn('id', DigitalAsset::query()->operational()->select('digital_assets.id')))
            ->orderBy('id')->limit($this->deep() ? 20 : 60)->get(['id', 'name', 'domain', 'primary_url', 'status', 'cms']);
        if ($sites->isEmpty()) {
            $this->info('Kapsamda web sitesi yok');

            return;
        }
        $ids = $sites->pluck('id')->all();
        $profiles = $this->hasTable('website_page_profiles')
            ? DB::table('website_page_profiles')->whereIn('website_asset_id', $ids)->selectRaw('website_asset_id, count(*) as n')->groupBy('website_asset_id')->pluck('n', 'website_asset_id') : collect();
        $urls = $this->hasTable('website_url')
            ? DB::table('website_url')->whereIn('digital_asset_id', $ids)->selectRaw('digital_asset_id, count(*) as n')->groupBy('digital_asset_id')->pluck('n', 'digital_asset_id') : collect();
        $out = [];
        foreach ($sites as $site) {
            $out[] = $this->deep() ? $this->websiteDetail($site, (int) ($profiles[$site->id] ?? 0), (int) ($urls[$site->id] ?? 0))
                : $this->websiteSummary($site, (int) ($profiles[$site->id] ?? 0), (int) ($urls[$site->id] ?? 0));
        }
        $this->data('websites', $out);
    }

    /** @return array<string, mixed> */
    private function websiteSummary(object $site, int $profiles, int $urls): array
    {
        $projection = $this->hasTable('website_intelligence_projection_runs')
            ? DB::table('website_intelligence_projection_runs')->where('website_asset_id', $site->id)->orderByDesc('id')->first(['status', 'completed_at', 'created_at', 'error_code']) : null;
        $plan = $this->hasTable('seo_plans') ? DB::table('seo_plans')->where('digital_asset_id', $site->id)->orderByDesc('id')->first(['status', 'completed_at', 'input_summary']) : null;
        $inventory = $plan !== null ? data_get($this->decode($plan->input_summary), 'inventory.state', data_get($this->decode($plan->input_summary), 'inventory.status')) : null;
        $line = sprintf('#%d %s (%s): sayfa profili %d · sitemap URL %d · projeksiyon %s %s · SEO planı %s %s%s', $site->id, $site->name, BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain)),
            $profiles, $urls, $projection->status ?? 'yok', $this->ago($projection?->completed_at ?? $projection?->created_at), $plan->status ?? 'yok', $this->ago($plan?->completed_at),
            is_string($inventory) ? ' · envanter '.$inventory : '');
        $bad = ($profiles === 0 && $urls > 0) || ($projection !== null && in_array($projection->status, self::FAILED, true)) || ($plan !== null && $plan->status === 'failed');
        $bad ? $this->problem($line) : $this->info($line);

        return ['asset_id' => (int) $site->id, 'page_profiles' => $profiles, 'sitemap_urls' => $urls, 'projection' => $projection->status ?? null, 'seo_plan' => $plan->status ?? null];
    }

    /** @return array<string, mixed> */
    private function websiteDetail(object $site, int $profiles, int $urls): array
    {
        $host = BrandSetupMatcher::host((string) ($site->primary_url ?: $site->domain));
        $this->info(sprintf('— #%d %s (%s, %s, cms %s)', $site->id, $site->name, $host, $site->status, $site->cms ?: '—'));
        $data = ['asset_id' => (int) $site->id, 'host' => $host, 'page_profiles' => $profiles, 'sitemap_urls' => $urls];

        $documents = 0;
        if ($this->hasTable('website_page_profiles') && $profiles > 0) {
            DB::table('website_page_profiles')->where('website_asset_id', $site->id)->select(['id', 'preferred_url'])
                ->chunkById(2000, function (Collection $chunk) use (&$documents): void {
                    foreach ($chunk as $row) {
                        $documents += SeoText::isDocumentUrl((string) $row->preferred_url) ? 1 : 0;
                    }
                });
        }
        $data['documents'] = $documents;
        $line = sprintf('Sayfa profili: %d (belge %d · belge dışı %d) · sitemap/website_url: %d', $profiles, $documents, $profiles - $documents, $urls);
        ($profiles === 0 || $documents === 0) ? $this->problem($line) : $this->info($line);

        if ($this->hasTable('website_html_snapshot')) {
            $html = DB::table('website_html_snapshot')->where('digital_asset_id', $site->id)->selectRaw('count(distinct url) as pages, max(last_collected_at) as last')->first();
            $line = sprintf('HTML okunan sayfa: %d · son okuma %s', (int) ($html->pages ?? 0), $this->ago($html->last ?? null));
            ((int) ($html->pages ?? 0) === 0 && $profiles > 0) ? $this->problem($line) : $this->info($line);
            $data['html_pages'] = (int) ($html->pages ?? 0);
        }

        if ($this->hasTable('website_intelligence_projection_runs')) {
            $projection = DB::table('website_intelligence_projection_runs')->where('website_asset_id', $site->id)->orderByDesc('id')
                ->first(['status', 'trigger', 'coverage_state', 'started_at', 'completed_at', 'error_code', 'error_summary', 'summary']);
            if ($projection === null) {
                $this->problem('Projeksiyon (sayfa profili) hiç yeniden kurulmamış');
            } else {
                $line = sprintf('Son projeksiyon: %s · %s · tetik %s · kapsam %s%s · özet %s', $projection->status, $this->ago($projection->completed_at ?? $projection->started_at), $projection->trigger ?? '—',
                    $projection->coverage_state ?? '—', filled($projection->error_code) || filled($projection->error_summary) ? ' · hata '.trim(($projection->error_code ?? '').' '.DiagnosticMasker::firstLine((string) $projection->error_summary, 160)) : '',
                    $this->json($projection->summary, 300));
                in_array($projection->status, self::FAILED, true) ? $this->problem($line) : $this->info($line);
                $data['projection'] = $projection->status;
            }
        }

        $this->websiteCollectionRun($site, $data);
        $this->websiteConnector($site, $data);

        if ($this->hasTable('seo_plans')) {
            $plan = DB::table('seo_plans')->where('digital_asset_id', $site->id)->orderByDesc('id')->first(['id', 'status', 'trigger', 'completed_at', 'failed_at', 'created_at', 'error_summary', 'input_summary', 'result_summary']);
            if ($plan === null) {
                $this->info('SEO planı: hiç çalışmamış');
            } else {
                $input = $this->decode($plan->input_summary);
                $picked = array_intersect_key($input, array_flip(['pages', 'html', 'inventory', 'gsc', 'findings', 'offerings', 'matched_queries', 'ga4_available', 'depth', 'use_ai']));
                $inventoryState = data_get($input, 'inventory.state', data_get($input, 'inventory.status'));
                $line = sprintf('SEO planı #%d: %s · %s · tetik %s%s', $plan->id, $plan->status, $this->ago($plan->completed_at ?? $plan->failed_at ?? $plan->created_at), $plan->trigger ?? '—',
                    filled($plan->error_summary) ? ' · hata: '.DiagnosticMasker::firstLine((string) $plan->error_summary, 200) : '');
                ($plan->status === 'failed' || (int) ($input['pages'] ?? 1) === 0 || data_get($input, 'inventory.empty') === true) ? $this->problem($line) : $this->info($line);
                $this->info('  input_summary: '.$this->json($picked, 700));
                $this->info('  result_summary: '.$this->json($plan->result_summary, 300));
                $data['seo_plan'] = ['status' => $plan->status, 'pages' => $input['pages'] ?? null, 'inventory' => $inventoryState];
            }
        }

        if ($this->hasTable('topic_map_builds')) {
            $build = DB::table('topic_map_builds')->where('digital_asset_id', $site->id)->orderByDesc('id')->first(['status', 'trigger', 'stats', 'error', 'finished_at', 'started_at']);
            if ($build === null) {
                $this->info('Konu haritası: hiç kurulmamış');
            } else {
                $line = sprintf('Konu haritası: %s · %s · %s%s', $build->status, $this->ago($build->finished_at ?? $build->started_at), $this->json($build->stats, 300),
                    filled($build->error) ? ' · hata: '.DiagnosticMasker::firstLine((string) $build->error, 200) : '');
                $build->status === 'failed' ? $this->problem($line) : $this->info($line);
            }
        }
        if ($this->hasTable('website_url_verdicts')) {
            $verdicts = DB::table('website_url_verdicts')->where('digital_asset_id', $site->id)->selectRaw('verdict, count(*) as n')->groupBy('verdict')->pluck('n', 'verdict');
            $this->info('URL kararları: '.$this->counts($verdicts));
            $data['url_verdicts'] = $verdicts->all();
        }
        foreach (['content_ideas' => 'İçerik fikirleri', 'content_articles' => 'İçerik yazıları'] as $table => $label) {
            if ($this->hasTable($table)) {
                $counts = DB::table($table)->where('digital_asset_id', $site->id)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
                $this->info($label.': '.$this->counts($counts));
                $data[$table] = $counts->all();
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    private function websiteCollectionRun(object $site, array &$data): void
    {
        if (! $this->hasTable('collection_runs')) {
            return;
        }
        $run = DB::table('collection_runs')->where('digital_asset_id', $site->id)->orderByDesc('id')
            ->first(['id', 'status', 'trigger_type', 'started_at', 'finished_at', 'created_at', 'datasets_total', 'datasets_completed', 'datasets_failed', 'failure_summary']);
        if ($run === null) {
            $this->problem('Web sitesi toplama çalıştırması hiç yok');

            return;
        }
        $line = sprintf('Son site toplama #%d: %s · %s · tetik %s · veri seti %d/%d (hata %d)%s', $run->id, $run->status, $this->ago($run->finished_at ?? $run->started_at ?? $run->created_at),
            $run->trigger_type ?? '—', (int) $run->datasets_completed, (int) $run->datasets_total, (int) $run->datasets_failed,
            filled($run->failure_summary) ? ' · '.$this->json($run->failure_summary, 200) : '');
        (in_array($run->status, self::FAILED, true) || (int) $run->datasets_failed > 0) ? $this->problem($line) : $this->info($line);
        $data['collection_run'] = ['id' => (int) $run->id, 'status' => $run->status];
        if (! $this->hasTable('collection_dataset_runs')) {
            return;
        }
        $steps = DB::table('collection_dataset_runs')->where('collection_run_id', $run->id)->orderBy('id')->limit(80)
            ->get(['dataset_contract_id', 'status', 'rows_written', 'error_code', 'error_message']);
        foreach ($steps as $step) {
            $stepLine = sprintf('  adım %s: %s · satır %d%s', $step->dataset_contract_id, $step->status, (int) $step->rows_written,
                filled($step->error_code) || filled($step->error_message) ? ' · '.trim(($step->error_code ?? '').' '.DiagnosticMasker::firstLine((string) $step->error_message, 160)) : '');
            in_array($step->status, self::FAILED, true) ? $this->problem($stepLine) : $this->info($stepLine);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function websiteConnector(object $site, array &$data): void
    {
        if ($this->hasTable('core_connections')) {
            $connection = DB::table('core_connections')->where('digital_asset_id', $site->id)->where('type', 'wordpress_connector')->orderByDesc('id')->first(['id', 'enabled', 'last_success_at', 'last_error']);
            if ($connection === null) {
                $this->info('WordPress Connector: bağlı değil');
            } else {
                $delivery = $this->hasTable('website_connector_delivery')
                    ? DB::table('website_connector_delivery')->where('connection_id', $connection->id)->first(['plugin_version', 'last_received_at', 'last_inventory_at', 'last_error', 'pending_count']) : null;
                $current = (string) config('moxdop-wordpress.connector_version', '');
                $version = $delivery->plugin_version ?? null;
                $outdated = $current !== '' && ($version === null || version_compare((string) $version, $current, '<'));
                $line = sprintf('WordPress Connector: %s · eklenti %s (güncel %s) · son veri %s · son envanter %s · bekleyen %d%s', (bool) $connection->enabled ? 'açık' : 'kapalı',
                    $version ?? 'yok', $current ?: '?', $this->ago($delivery->last_received_at ?? null), $this->ago($delivery->last_inventory_at ?? null), (int) ($delivery->pending_count ?? 0),
                    filled($delivery->last_error ?? null) || filled($connection->last_error) ? ' · hata: '.DiagnosticMasker::firstLine((string) ($delivery->last_error ?? $connection->last_error), 160) : '');
                ($outdated || filled($delivery->last_error ?? null) || filled($connection->last_error)) ? $this->problem($line) : $this->info($line);
                $data['connector'] = ['version' => $version, 'outdated' => $outdated];
            }
        }
        if ($this->hasTable('website_cms_site_snapshot')) {
            $snapshot = DB::table('website_cms_site_snapshot')->where('digital_asset_id', $site->id)->orderByDesc('last_collected_at')->first(['wordpress_version', 'php_version', 'last_collected_at', 'site_health_critical_count']);
            if ($snapshot !== null) {
                $this->info(sprintf('WP site anlık görüntüsü: WP %s · PHP %s · kritik %d · %s', $snapshot->wordpress_version ?? '?', $snapshot->php_version ?? '?', (int) $snapshot->site_health_critical_count, $this->ago($snapshot->last_collected_at)));
            }
        }
        if ($this->hasTable('wordpress_site_health')) {
            $health = DB::table('wordpress_site_health')->where('digital_asset_id', $site->id)->first(['pending_updates', 'critical_issues', 'error', 'checked_at']);
            if ($health !== null) {
                $line = sprintf('WP sağlık: güncelleme %s · kritik %s · kontrol %s%s', (string) ($health->pending_updates ?? '—'), (string) ($health->critical_issues ?? '—'), $this->ago($health->checked_at),
                    filled($health->error) ? ' · hata: '.DiagnosticMasker::firstLine((string) $health->error, 160) : '');
                filled($health->error) ? $this->problem($line) : $this->info($line);
            }
        }
    }

    // ------------------------------------------------------------------ 6. queries

    private function queries(): void
    {
        if (! $this->need('brand_demand_queries')) {
            return;
        }
        if (! $this->deep()) {
            $rows = DB::table('brand_demand_queries as q')->join('brands as b', 'b.id', '=', 'q.brand_id')->whereNull('b.deleted_at')
                ->selectRaw("b.id, b.name, count(*) as total, sum(case when q.relevance = 'relevant' then 1 else 0 end) as relevant, sum(case when q.relevance = 'unclear' then 1 else 0 end) as unclear, sum(case when q.relevance = 'irrelevant' then 1 else 0 end) as irrelevant, sum(case when q.brand_offering_id is null and (q.relevance is null or q.relevance <> 'irrelevant') then 1 else 0 end) as unassigned")
                ->groupBy('b.id', 'b.name')->orderByDesc('unclear')->limit(30)->get();
            foreach ($rows as $row) {
                $line = sprintf('#%d %s: %d sorgu · ilgili %d · belirsiz %d · alakasız %d · hizmetsiz %d', $row->id, $row->name, $row->total, $row->relevant, $row->unclear, $row->irrelevant, $row->unassigned);
                ((int) $row->total > 0 && (int) $row->unclear / (int) $row->total > 0.3) ? $this->problem($line) : $this->info($line);
            }
            $this->data('brands', $rows->map(fn (object $r): array => (array) $r)->all());
            $this->info('(ayrıntı için --brand=…)');

            return;
        }

        foreach ($this->brands as $brand) {
            $this->brandQueries($brand);
        }
    }

    /** @param  array{id: int, name: string, customer_id: ?int}  $brand */
    private function brandQueries(array $brand): void
    {
        $base = fn () => DB::table('brand_demand_queries')->where('brand_id', $brand['id']);
        $total = $base()->count();
        $this->info(sprintf('— #%d %s: %d sorgu', $brand['id'], $brand['name'], $total));
        $relevance = $base()->selectRaw('relevance, count(*) as n')->groupBy('relevance')->pluck('n', 'relevance');
        $method = $base()->selectRaw('assignment_method, count(*) as n')->groupBy('assignment_method')->pluck('n', 'assignment_method');
        $source = $base()->selectRaw('assignment_source, count(*) as n')->groupBy('assignment_source')->pluck('n', 'assignment_source');
        $bySource = [];
        foreach (BrandDemandQuery::SOURCE_BITS as $name => $bit) {
            $bySource[$name] = $base()->whereRaw('(source_mask & ?) > 0', [$bit])->count();
        }
        $unassigned = $base()->whereNull('brand_offering_id')->where(fn ($q) => $q->whereNull('relevance')->orWhere('relevance', '!=', BrandDemandQuery::IRRELEVANT))->count();
        $unclear = (int) ($relevance[BrandDemandQuery::UNCLEAR] ?? 0);
        $this->info('İlgi: '.$this->counts($relevance));
        $this->info('Atama yöntemi: '.$this->counts($method).' · atama kaynağı: '.$this->counts($source));
        $this->info('Kaynak: '.$this->counts($bySource));
        $line = sprintf('Belirsiz %d · hizmete atanmamış (alakasız hariç) %d', $unclear, $unassigned);
        ($total > 0 && ($unclear / $total > 0.3 || $unassigned / $total > 0.5)) ? $this->problem($line) : $this->info($line);
        if ($total === 0) {
            $this->problem('Markanın hiç arama sorgusu yok (talep haritası kurulmamış olabilir)');
        }

        $top = $base()->whereNull('brand_offering_id')->where(fn ($q) => $q->whereNull('relevance')->orWhere('relevance', '!=', BrandDemandQuery::IRRELEVANT))
            ->orderByDesc('gsc_impressions')->limit(10)->get(['query', 'gsc_impressions', 'gsc_clicks', 'relevance', 'sources']);
        foreach ($top as $q) {
            $this->info(sprintf('  hizmetsiz: "%s" · gösterim %d · tık %d · %s · %s', DiagnosticMasker::text((string) $q->query, 80), (int) $q->gsc_impressions, (int) $q->gsc_clicks, $q->relevance ?? '—', filled($q->sources) ? $this->json($q->sources, 80) : '—'));
        }
        $offerings = $this->hasTable('brand_offerings') ? DB::table('brand_offerings')->where('brand_id', $brand['id'])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') : collect();
        $areas = $this->hasTable('brand_service_areas') ? DB::table('brand_service_areas')->where('brand_id', $brand['id'])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status') : collect();
        $this->info('Hizmetler: '.$this->counts($offerings).' · hizmet bölgeleri: '.$this->counts($areas));
        if ($offerings->sum() === 0) {
            $this->problem('Markada hizmet (offering) tanımlı değil');
        }
        $this->data('brand_'.$brand['id'], ['total' => $total, 'relevance' => $relevance->all(), 'method' => $method->all(), 'source' => $bySource,
            'unclear' => $unclear, 'unassigned' => $unassigned, 'offerings' => $offerings->all(), 'service_areas' => $areas->all()]);
    }

    // ------------------------------------------------------------------ 7. advisors

    private function advisors(): void
    {
        $brandIds = $this->deep() ? ($this->brandIds() ?: [0]) : null;
        foreach (['advisor_plans' => 'channel', 'seo_plans' => null] as $table => $channelColumn) {
            if (! $this->hasTable($table)) {
                $this->info($table.': yok');

                continue;
            }
            $since = now()->subDays($this->days);
            $query = DB::table($table)->when($brandIds !== null, fn ($q) => $q->whereIn('brand_id', $brandIds))
                ->selectRaw("max(id) as id, sum(case when status = 'failed' and created_at >= ? then 1 else 0 end) as failed, sum(case when created_at >= ? then 1 else 0 end) as recent", [$since, $since]);
            $latest = $channelColumn !== null
                ? $query->addSelect($channelColumn.' as channel')->groupBy($channelColumn)->get()
                : $query->get()->filter(fn (object $row): bool => $row->id !== null)->each(fn (object $row) => $row->channel = 'seo');
            foreach ($latest as $row) {
                $plan = DB::table($table)->where('id', $row->id)->first(['status', 'completed_at', 'failed_at', 'created_at', 'error_summary']);
                $lastOk = DB::table($table)->when($brandIds !== null, fn ($q) => $q->whereIn('brand_id', $brandIds))
                    ->when($channelColumn !== null, fn ($q) => $q->where($channelColumn, $row->channel))->where('status', 'completed')->max('completed_at');
                $line = sprintf('Danışman %s: son plan %s (%s) · son başarılı %s · %d günde %d plan, %d hatalı%s', $row->channel, $plan->status ?? '?', $this->ago($plan->completed_at ?? $plan->failed_at ?? $plan->created_at ?? null),
                    $this->ago($lastOk), $this->days, (int) $row->recent, (int) $row->failed, filled($plan->error_summary ?? null) ? ' · hata: '.DiagnosticMasker::firstLine((string) $plan->error_summary, 160) : '');
                ((int) $row->failed > 0 || ($this->daysSince($lastOk) ?? 99) > 8) ? $this->problem($line) : $this->info($line);
            }
        }

        if ($this->hasTable('advisor_items')) {
            $items = DB::table('advisor_items')->where('status', 'open')->when($brandIds !== null, fn ($q) => $q->whereIn('brand_id', $brandIds))
                ->selectRaw('channel, count(*) as n')->groupBy('channel')->pluck('n', 'channel');
            $this->info('Açık danışman maddeleri: '.$this->counts($items));
            $this->data('open_advisor_items', $items->all());
        }
        if ($this->hasTable('seo_tasks')) {
            $tasks = DB::table('seo_tasks')->where('status', 'open')->when($brandIds !== null, fn ($q) => $q->whereIn('brand_id', $brandIds))
                ->selectRaw('type, count(*) as n')->groupBy('type')->pluck('n', 'type');
            $this->info('Açık SEO görevleri: '.$this->counts($tasks));
            $this->data('open_seo_tasks', $tasks->all());
        }

        if ($this->hasTable('operational_alerts')) {
            $alerts = DB::table('operational_alerts')->whereIn('state', ['OPEN', 'ACKNOWLEDGED'])
                ->selectRaw('rule_key, severity, count(*) as n, sum(coalesce(occurrence_count, observation_count, 1)) as occurrences, max(last_observed_at) as last')
                ->groupBy('rule_key', 'severity')->orderByDesc('n')->limit(25)->get();
            foreach ($alerts as $alert) {
                $line = sprintf('Operasyon uyarısı %s [%s]: %d açık · %d gözlem · son %s', $alert->rule_key, $alert->severity, $alert->n, $alert->occurrences, $this->ago($alert->last));
                strtoupper((string) $alert->severity) === 'CRITICAL' ? $this->problem($line) : $this->info($line);
            }
            if ($alerts->isEmpty()) {
                $this->info('Açık operasyon uyarısı: yok');
            }
            $this->data('operational_alerts', $alerts->map(fn (object $r): array => (array) $r)->all());
        }
        if ($this->hasTable('asset_alerts')) {
            $assetAlerts = DB::table('asset_alerts')->whereNull('resolved_at')->when($brandIds !== null, fn ($q) => $q->whereIn('brand_id', $brandIds))
                ->selectRaw('kind, count(*) as n')->groupBy('kind')->orderByDesc('n')->limit(25)->pluck('n', 'kind');
            $this->info('Açık varlık uyarıları: '.$this->counts($assetAlerts));
            $this->data('asset_alerts', $assetAlerts->all());
        }
        if ($this->hasTable('inbox_item_states')) {
            $topics = [];
            DB::table('inbox_item_states')->whereNull('gone_at')->select(['id', 'item_key'])
                ->chunkById(5000, function (Collection $chunk) use (&$topics): void {
                    foreach ($chunk as $row) {
                        $topic = explode(':', (string) $row->item_key)[0];
                        $topics[$topic] = ($topics[$topic] ?? 0) + 1;
                    }
                });
            arsort($topics);
            $this->info('Komuta merkezi (son değerlendirmede görünen maddeler, kaynağa göre): '.$this->counts($topics));
            $this->data('command_center', $topics);
        }
    }

    // ------------------------------------------------------------------ 8. ai

    private function ai(): void
    {
        $registry = app(AiRouteRegistry::class);
        $keys = $registry->keys();
        sort($keys);
        $steps = $this->hasTable('ai_route_steps')
            ? DB::table('ai_route_steps')->where('enabled', true)->orderBy('route_key')->orderBy('position')->get(['route_key', 'provider', 'model'])->groupBy('route_key') : collect();
        $this->info(sprintf('AI rotaları: %d kayıtlı · %d rotada özel adım', count($keys), $steps->count()));
        $this->info('  '.implode(', ', $keys));
        foreach ($steps as $route => $routeSteps) {
            $this->info('  '.$route.' → '.$routeSteps->map(fn (object $s): string => $s->provider.($s->model ? ':'.$s->model : ''))->implode(' → '));
        }

        $providers = [];
        foreach (AiProviderCatalog::supported() as $provider) {
            $integration = $this->hasTable('core_integrations') ? DB::table('core_integrations')->where('provider', $provider)->orderBy('id')->first(['id', 'status']) : null;
            $stored = $integration !== null && $this->hasTable('core_integration_credentials')
                && DB::table('core_integration_credentials')->where('integration_id', $integration->id)->exists();
            $env = filled(config('ai.providers.'.$provider.'.key'));
            $providers[$provider] = ['integration' => $integration->status ?? 'yok', 'stored_credential' => $stored, 'env_key' => $env];
            $this->info(sprintf('Sağlayıcı %s: entegrasyon %s · kayıtlı kimlik %s · env anahtarı %s', $provider, $integration->status ?? 'yok', $stored ? 'evet' : 'hayır', $env ? 'evet' : 'hayır'));
        }
        if (! collect($providers)->contains(fn (array $p): bool => ($p['stored_credential'] || $p['env_key']) && $p['integration'] !== 'disabled')) {
            $this->problem('Hiçbir AI sağlayıcısı yapılandırılmamış');
        }
        $this->data('providers', $providers);

        if ($this->hasTable('ai_usage_records')) {
            $usage = DB::table('ai_usage_records')->where('created_at', '>=', now()->subDays(7))
                ->selectRaw('route_key, count(*) as calls, coalesce(sum(cost_usd), 0) as cost')->groupBy('route_key')->orderByDesc('cost')->limit(30)->get();
            foreach ($usage as $row) {
                $this->info(sprintf('Kullanım 7g %s: %d çağrı · $%.2f', $row->route_key ?? '—', $row->calls, $row->cost));
            }
            $this->data('usage_7d', $usage->map(fn (object $r): array => (array) $r)->all());
            $budget = $this->hasColumn('agency_settings', 'ai_monthly_budget_usd') ? DB::table('agency_settings')->value('ai_monthly_budget_usd') : null;
            $budget = $budget !== null ? (float) $budget : (float) config('moxdop-ai-pricing.monthly_budget_usd', 100);
            $spend = (float) DB::table('ai_usage_records')->where('created_at', '>=', now()->startOfMonth())->sum('cost_usd');
            $line = sprintf('Bütçe: bu ay $%.2f / $%.2f', $spend, $budget);
            ($budget > 0 && $spend >= $budget) ? $this->problem($line.' — BİTTİ (yalnız ücretsiz modeller)') : $this->info($line);
            $this->data('budget', ['budget' => $budget, 'spend' => $spend]);
        }
        if ($this->hasTable('agent_execution_runs')) {
            $failures = DB::table('agent_execution_runs')->where('created_at', '>=', now()->subDays(7))->whereNotIn('status', ['completed', 'succeeded'])
                ->selectRaw('ai_route_key, status, count(*) as n')->groupBy('ai_route_key', 'status')->orderByDesc('n')->limit(20)->get();
            foreach ($failures as $row) {
                $line = sprintf('Ajan çalıştırması 7g %s: %s ×%d', $row->ai_route_key ?? '—', $row->status, $row->n);
                in_array($row->status, ['failed', 'error'], true) ? $this->problem($line) : $this->info($line);
            }
        }
        if ($this->hasTable('ai_provider_attempts')) {
            $attempts = DB::table('ai_provider_attempts')->where('created_at', '>=', now()->subDays(7))->where('status', 'failed')
                ->selectRaw('provider, error_category, count(*) as n')->groupBy('provider', 'error_category')->orderByDesc('n')->limit(15)->get();
            foreach ($attempts as $row) {
                $this->problem(sprintf('Sağlayıcı hatası 7g %s · %s ×%d', $row->provider, $row->error_category ?? '—', $row->n));
            }
        }
    }

    // ------------------------------------------------------------------ 9. errors

    private function errors(): void
    {
        if ($this->hasTable('app_error_groups')) {
            $groups = DB::table('app_error_groups')->where('last_seen_at', '>=', now()->subDays(7))->orderByDesc('occurrences')->orderByDesc('last_seen_at')->limit(20)
                ->get(['exception_class', 'location', 'message', 'occurrences', 'last_seen_at', 'last_release']);
            if ($groups->isNotEmpty()) {
                foreach ($groups as $g) {
                    $this->problem(sprintf('×%d %s @ %s (son %s%s): %s', $g->occurrences, class_basename((string) $g->exception_class), $g->location, $this->ago($g->last_seen_at),
                        $g->last_release ? ', sürüm '.substr((string) $g->last_release, 0, 12) : '', DiagnosticMasker::firstLine((string) $g->message, 200)));
                }
                $this->data('error_groups', $groups->map(fn (object $g): array => ['class' => $g->exception_class, 'location' => $g->location, 'count' => (int) $g->occurrences, 'last_seen_at' => $g->last_seen_at, 'message' => DiagnosticMasker::firstLine((string) $g->message, 200)])->all());

                return;
            }
            $this->info('app_error_groups: son 7 günde kayıt yok; log dosyasına bakılıyor');
        } else {
            $this->info('app_error_groups: yok; log dosyasına bakılıyor');
        }
        $this->logTail();
    }

    private function logTail(): void
    {
        $files = glob(storage_path('logs/laravel*.log')) ?: [];
        usort($files, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $file = $files[0] ?? null;
        if ($file === null) {
            $this->info('Log dosyası yok');

            return;
        }
        $lines = $this->tail($file, 2000);
        $groups = [];
        foreach ($lines as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.*)$/', $line, $m) !== 1) {
                continue;
            }
            $message = (string) preg_replace(['/\{"(userId|exception)".*$/', '/\d{3,}/'], ['', 'N'], $m[3]);
            $key = DiagnosticMasker::text($message, 200);
            if (preg_match('#(?:app|app-modules|resources/views|routes)/[^\s:"()]+:\d+#', $m[3], $where) === 1) {
                $key .= ' @ '.$where[0];
            }
            $groups[$key] ??= ['count' => 0, 'last' => $m[1]];
            $groups[$key]['count']++;
            $groups[$key]['last'] = max($groups[$key]['last'], $m[1]);
        }
        uasort($groups, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
        $this->info(sprintf('%s: son %d satır, %d hata grubu', basename($file), count($lines), count($groups)));
        foreach (array_slice($groups, 0, 20, true) as $message => $group) {
            $this->problem(sprintf('×%d (son %s) %s', $group['count'], $group['last'], $message));
        }
        $this->data('log_groups', array_slice($groups, 0, 20, true));
    }

    /** @return list<string> the last $count lines of a file, read from its end */
    private function tail(string $file, int $count): array
    {
        $handle = @fopen($file, 'r');
        if ($handle === false) {
            return [];
        }
        $size = (int) filesize($file);
        $chunk = '';
        $position = $size;
        while ($position > 0 && substr_count($chunk, "\n") <= $count && strlen($chunk) < 16_000_000) {
            $read = min(262144, $position);
            $position -= $read;
            fseek($handle, $position);
            $chunk = fread($handle, $read).$chunk;
        }
        fclose($handle);
        $lines = explode("\n", rtrim($chunk, "\n"));

        return array_values(array_slice($lines, -$count));
    }
}
