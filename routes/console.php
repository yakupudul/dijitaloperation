<?php

use App\Enums\Collection\CollectionRunStatus;
use App\Jobs\Assistant\UptimeCheckJob;
use App\Jobs\Brand\RunBrandChiefJob;
use App\Jobs\CheckAdBudgetJob;
use App\Jobs\CheckSitemapChangesJob;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Jobs\CollectMetaGeoResultsJob;
use App\Jobs\Gbp\FillGbpPostQueueJob;
use App\Jobs\Gbp\SyncGbpSuggestionsJob;
use App\Jobs\GoogleAds\SyncGoogleAdsSuggestionsJob;
use App\Jobs\Meta\SyncMetaSuggestionsJob;
use App\Jobs\Ops\QueueHeartbeatProbeJob;
use App\Jobs\Queries\QueryAutopilotJob;
use App\Jobs\RefreshBrandCandidatesJob;
use App\Jobs\RefreshDataCenterSummaryJob;
use App\Jobs\Site\PullClarityJob;
use App\Models\AiLiveOperation;
use App\Models\Brand;
use App\Models\ClarityProject;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\ScreenCheck;
use App\Models\User;
use App\Services\Ads\AdsCatchUp;
use App\Services\Ads\Winners;
use App\Services\Ai\AiBudget;
use App\Services\Ai\ClaudeApiWindow;
use App\Services\Ai\OpenAiCostAudit;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Alerts\AdBudgetWatch;
use App\Services\Analyst\AnalystEngine;
use App\Services\Analyst\AnalystRegistry;
use App\Services\Assistant\ReminderService;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\Brand\BrandAudit;
use App\Services\Brand\BrandCare;
use App\Services\Brand\BrandDataAudit;
use App\Services\Brand\BrandDossier;
use App\Services\Brand\BrandGaps;
use App\Services\BrandIntelligence\BrandIntelligenceContextWriteService;
use App\Services\BrandSetup\BrandAutofill;
use App\Services\Collection\Activity\ActivityTierService;
use App\Services\Collection\CollectionErrorRecorder;
use App\Services\Collection\Monitoring\CollectionAccountPresenter;
use App\Services\Collection\RecoverInterruptedCollections;
use App\Services\Collection\StartCollectionService;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\GbpPostQueue;
use App\Services\Gsc\UrlInspectionTargets;
use App\Services\Integrations\Bing\BingWebmasterClient;
use App\Services\Integrations\Bing\BingWebmasterSync;
use App\Services\Integrations\Google\GoogleBusinessProfileRetentionService;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Integrations\WordPress\WordPressEventReconciliation;
use App\Services\Observability\ErrorTriage;
use App\Services\Observability\WorkerHeartbeatService;
use App\Services\Operations\ScreenChecker;
use App\Services\Ownership\OwnershipIntegrity;
use App\Services\Portfolio\BrandCandidateBuilder;
use App\Services\Queries\QueryNotifier;
use App\Services\Site\Backlinks\SerpMentionSources;
use App\Services\Site\PageCategorizer;
use App\Services\Site\ServicePageMapper;
use App\Services\Site\SiteFlow;
use App\Services\Site\SiteMetrics;
use App\Services\Site\SiteOperations;
use App\Services\Website\SitemapChangeWatcher;
use App\Services\WhatsApp\WhatsAppDispatch;
use App\Services\Work\ContentCoverage;
use App\Support\Ai\AiRouteKeys;
use App\Support\Console\ConsoleScope;
use App\Support\Console\ConsoleScopeException;
use App\Support\Roles;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('moxdop:collection:work-db {--provider=} {--exclude-provider=} {--sleep=1} {--max-runtime=3500}', function () {
    $sleep = max(1, (int) $this->option('sleep'));
    $maxRuntime = max(60, (int) $this->option('max-runtime'));
    // Both options take one provider or a comma-separated list (e.g. the Website lane: WEBSITE_DIRECT,DOMAIN_DNS_TLS,…).
    $provider = strtoupper(str_replace(' ', '', trim((string) $this->option('provider'))));
    $excludeProvider = strtoupper(str_replace(' ', '', trim((string) $this->option('exclude-provider'))));
    $providerList = array_values(array_filter(explode(',', $provider)));
    $excludeProviderList = array_values(array_filter(explode(',', $excludeProvider)));

    if ($provider !== '' && $excludeProvider !== '') {
        $this->error('Use either --provider or --exclude-provider, not both.');

        return 2;
    }

    $startedAt = microtime(true);
    $starter = app(StartCollectionService::class);
    $scope = $provider !== ''
        ? 'provider='.$provider
        : ($excludeProvider !== '' ? 'exclude_provider='.$excludeProvider : 'all');

    $this->info(sprintf(
        'MoxDOP DB collection worker started (%s, sleep=%ds, max_runtime=%ds).',
        $scope,
        $sleep,
        $maxRuntime,
    ));

    $lastBeat = 0.0;
    while ((microtime(true) - $startedAt) < $maxRuntime) {
        // Faz 4: heartbeat at most once a minute so the system health page and alerts see this worker.
        if (microtime(true) - $lastBeat >= 60) {
            try {
                app(WorkerHeartbeatService::class)->beat('db-collector:'.($scope === 'all' ? 'all' : strtolower($scope)), 'db-collector', 'COLLECTION', ['scope' => $scope]);
            } catch (Throwable) {
                // Heartbeats must never stop collection.
            }
            $lastBeat = microtime(true);
        }
        $query = CollectionDatasetRun::query()
            ->whereHas('collectionRun', function ($run): void {
                $run->whereIn('status', [
                    CollectionRunStatus::Queued->value,
                    CollectionRunStatus::Running->value,
                    CollectionRunStatus::Retrying->value,
                    CollectionRunStatus::CancellationRequested->value,
                ]);
            })
            ->where(function ($query): void {
                $query->where('status', CollectionRunStatus::Queued->value)
                    ->orWhere(function ($retry): void {
                        $retry->where('status', CollectionRunStatus::Retrying->value)
                            ->where(function ($due): void {
                                $due->whereNull('retry_at')
                                    ->orWhere('retry_at', '<=', now());
                            });
                    });
            })
            ->where(function ($lock): void {
                $lock->whereNull('dispatch_lock_token')
                    ->orWhereNull('dispatch_locked_at')
                    ->orWhere('dispatch_locked_at', '<', now()->subMinutes(15));
            });

        if ($providerList !== []) {
            $query->whereIn('provider_or_source', $providerList);
        } elseif ($excludeProviderList !== []) {
            $query->whereNotIn('provider_or_source', $excludeProviderList);
        }

        $candidates = $query
            ->orderByRaw('COALESCE(last_activity_at, created_at) ASC')
            ->orderBy('id')
            ->limit(100)
            ->get();

        $dataset = $candidates->first(
            fn (CollectionDatasetRun $candidate): bool => $starter->dependenciesSatisfied($candidate)
        );

        if (! $dataset instanceof CollectionDatasetRun) {
            sleep($sleep);

            continue;
        }

        $beforeAttempt = (int) $dataset->attempt_count;
        $beforeStatus = $dataset->status;

        try {
            Bus::dispatchSync(new ExecuteDatasetRunJob($dataset->id));

            $dataset->refresh();
            if ((int) $dataset->attempt_count === $beforeAttempt && $dataset->status === $beforeStatus) {
                // Another worker may have claimed the row between selection and execution.
                // Avoid a hot loop on the same queued dataset and give the owner time to progress.
                usleep(250000);
            }
        } catch (Throwable $e) {
            report($e);
            $this->error(sprintf(
                'Dataset #%d direct execution failed before normal job failure handling: %s',
                $dataset->id,
                $e->getMessage(),
            ));
            sleep($sleep);
        }
    }

    $this->info('MoxDOP DB collection worker reached max runtime; Supervisor will restart it.');

    return 0;
})->purpose('Continuously execute canonical queued/retrying collection datasets directly from PostgreSQL state.');

Artisan::command('moxdop:collection:status {--provider=} {--details}', function () {
    $provider = strtoupper(trim((string) $this->option('provider')));
    $activeStatuses = [
        CollectionRunStatus::Queued->value,
        CollectionRunStatus::Running->value,
        CollectionRunStatus::Retrying->value,
        CollectionRunStatus::CancellationRequested->value,
    ];

    $runs = CollectionRun::query()
        ->whereIn('status', $activeStatuses)
        ->with(['datasetRuns', 'resourceRuns'])
        ->orderBy('id')
        ->get();

    if ($provider !== '') {
        $runs = $runs->filter(fn (CollectionRun $run): bool => $run->datasetRuns->contains(
            fn (CollectionDatasetRun $dataset): bool => strtoupper((string) $dataset->provider_or_source) === $provider
        ))->values();
    }

    $this->info(sprintf('Active collection runs: %d%s', $runs->count(), $provider !== '' ? ' · provider='.$provider : ''));

    foreach ($runs as $run) {
        $datasets = $run->datasetRuns;
        $providers = $datasets->pluck('provider_or_source')->filter()->unique()->implode(',');
        $queued = $datasets->where('status', CollectionRunStatus::Queued)->count();
        $running = $datasets->where('status', CollectionRunStatus::Running)->count();
        $retrying = $datasets->where('status', CollectionRunStatus::Retrying)->count();
        $completed = $datasets->where('status', CollectionRunStatus::Completed)->count();
        $failed = $datasets->where('status', CollectionRunStatus::Failed)->count();
        $attempts = (int) $datasets->sum('attempt_count');
        $locked = $datasets->filter(fn (CollectionDatasetRun $dataset): bool => filled($dataset->dispatch_lock_token))->count();
        $effectiveState = app(CollectionAccountPresenter::class)->state($run);
        $activity = $datasets->map(fn ($dataset) => $dataset->last_activity_at)->filter()->sortDesc()->first();

        $this->line(sprintf(
            'Run #%d | %s | providers=%s | q=%d run=%d retry=%d done=%d fail=%d | attempts=%d locks=%d | activity=%s',
            $run->id,
            $effectiveState,
            $providers !== '' ? $providers : '-',
            $queued,
            $running,
            $retrying,
            $completed,
            $failed,
            $attempts,
            $locked,
            $activity?->diffForHumans() ?? '-',
        ));
        $pending = $datasets->filter(fn ($dataset) => ! $dataset->status->isTerminal());
        $retryDates = $pending->filter(fn ($dataset) => $dataset->status === CollectionRunStatus::Retrying)
            ->map(fn ($dataset) => $dataset->retry_at)->filter()->sort();
        $this->line(sprintf('  stored_rows=%d | API_pages=%d | next_retry_UTC=%s',
            (int) $datasets->sum('rows_written'), (int) $datasets->sum('pages_completed'),
            $retryDates->first()?->toIso8601String() ?? '-'));
        if ($this->option('details')) {
            $safeErrors = app(CollectionErrorRecorder::class);
            foreach ($datasets->filter(fn ($dataset) => in_array($dataset->status, [
                CollectionRunStatus::Retrying, CollectionRunStatus::Failed, CollectionRunStatus::Running,
            ], true))->take(30) as $dataset) {
                $message = $safeErrors->sanitizeMessage($dataset->error_message, '');
                $this->line(sprintf('  dataset=%d family=%s state=%s code=%s retry_UTC=%s progress=%s/%s rows=%d pages=%d stage=%s',
                    $dataset->id, $dataset->request_family_id, $dataset->status->value,
                    $dataset->error_code ?: '-', $dataset->retry_at?->toIso8601String() ?? '-',
                    $dataset->progress_current ?? '-', $dataset->progress_total ?? '-',
                    (int) $dataset->rows_written, (int) $dataset->pages_completed, $dataset->stage ?: '-'));
                if ($message) {
                    $this->line('    '.mb_substr(preg_replace('/\s+/', ' ', $message) ?? '', 0, 500));
                }
            }
        }
    }

    $datasetQuery = CollectionDatasetRun::query()
        ->where(function ($query): void {
            $query->where('status', CollectionRunStatus::Queued->value)
                ->orWhere('status', CollectionRunStatus::Retrying->value);
        });

    if ($provider !== '') {
        $datasetQuery->where('provider_or_source', $provider);
    }

    $queuedDatasets = $datasetQuery->get();
    $freshLocks = $queuedDatasets->filter(fn (CollectionDatasetRun $dataset): bool => filled($dataset->dispatch_lock_token)
        && $dataset->dispatch_locked_at !== null
        && $dataset->dispatch_locked_at->greaterThan(now()->subMinutes(15))
    )->count();
    $staleLocks = $queuedDatasets->filter(fn (CollectionDatasetRun $dataset): bool => filled($dataset->dispatch_lock_token)
        && ($dataset->dispatch_locked_at === null || $dataset->dispatch_locked_at->lessThanOrEqualTo(now()->subMinutes(15)))
    )->count();

    $this->line(sprintf(
        'Queued/retrying datasets=%d | fresh_locks=%d | stale_locks=%d',
        $queuedDatasets->count(),
        $freshLocks,
        $staleLocks,
    ));

    return 0;
})->purpose('Show active collection runs, attempts and dispatch-lock health.');

Artisan::command('moxdop:collection:redispatch-stale {--run=} {--force}', function () {
    if (! $this->option('run')) {
        app(RecoverInterruptedCollections::class)->tick();
    }
    $query = CollectionRun::query()
        ->whereIn('status', [
            CollectionRunStatus::Queued->value,
            CollectionRunStatus::Running->value,
            CollectionRunStatus::Retrying->value,
        ])
        ->orderBy('id');

    $runId = $this->option('run');
    if ($runId !== null && $runId !== '') {
        $query->whereKey((int) $runId);
    }

    $starter = app(StartCollectionService::class);
    $runs = $query->get();
    $queued = 0;
    $forcedClaims = 0;

    foreach ($runs as $run) {
        $queuedDatasets = $run->datasetRuns()
            ->where('status', CollectionRunStatus::Queued->value)
            ->get();

        $queued += $queuedDatasets->count();

        if ((bool) $this->option('force')) {
            foreach ($queuedDatasets as $dataset) {
                $metadata = is_array($dataset->metadata) ? $dataset->metadata : [];
                if (($metadata['queue_dispatch_claimed'] ?? false) === true) {
                    $forcedClaims++;
                }
                unset($metadata['queue_dispatch_claimed'], $metadata['queue_dispatch_claimed_at']);
                $dataset->forceFill([
                    'metadata' => $metadata,
                    'dispatch_lock_token' => null,
                    'dispatch_locked_at' => null,
                ])->save();
            }
        }

        $starter->dispatchEligibleRootJobs($run->fresh() ?? $run);
    }

    $this->info(sprintf(
        'Collection recovery scanned %d active run(s); queued=%d; forced_claims=%d.',
        $runs->count(),
        $queued,
        $forcedClaims,
    ));
})->purpose('Recover interrupted collection work and republish expired queued dispatches.');

Schedule::command('moxdop:collection:redispatch-stale')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->name('collection-redispatch-stale');

Schedule::command('async:mark-stale-runs')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->name('async-mark-stale-runs');

// reports:dispatch-due-deliveries stays as a manual command; the shared dispatcher below already covers report deliveries.
Schedule::command('moxdop:dispatch-due-automations')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->name('moxdop-dispatch-due-automations');

Schedule::command('moxdop:ops:evaluate-alerts')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->name('moxdop-ops-evaluate-alerts');

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->withoutOverlapping(5)
    ->name('horizon-snapshot');

// Properties explicitly selected for central GA4 collection are refreshed daily.
// The command recalculates each property's last 14 closed reporting days in that property's timezone.
// Resource automation now owns GA4 cadence too; no second daily restatement schedule.
Artisan::command('moxdop:resources:automate {--recover-ga4-landing-pages} {--recover-gsc-appearance} {--resolve-unbound-alerts}', function (): void {
    $service = app(ResourceAutomationService::class);
    if ($this->option('resolve-unbound-alerts')) {
        $this->info('Resolved alerts of accounts without an operational asset: '.$service->resolveUnboundAlerts());
    }
    if ($this->option('recover-ga4-landing-pages')) {
        $this->info('Recovered empty-dimension write failures: '.$service->recoverGa4LandingFailures());
    }
    if ($this->option('recover-gsc-appearance')) {
        $this->info('Recovered GSC appearance failures: '.$service->recoverGscAppearanceFailures());
    }
    $service->tick();
    if ($this->option('recover-ga4-landing-pages')) {
        // Only brand-bound accounts: an account without a brand has no purpose until it is bound (Marka adayları).
        $rows = DB::table('resource_automations as a')->join('core_external_resources as r', 'r.id', '=', 'a.external_resource_id')
            ->where('a.collection_enabled', true)->whereIn('a.external_resource_id', DB::table('core_asset_bindings as b')
            ->join('digital_assets as d', 'd.id', '=', 'b.digital_asset_id')->where('b.status', 'active')->whereNotNull('d.brand_id')->select('b.external_resource_id'))
            ->select('r.resource_type', 'a.collection_status')
            ->selectRaw('COUNT(*) as accounts')->groupBy('r.resource_type', 'a.collection_status')
            ->orderBy('r.resource_type')->orderBy('a.collection_status')->get()
            ->map(fn ($row) => [$row->resource_type, $row->collection_status, $row->accounts])->all();
        $this->table(['Source', 'Automatic collection state', 'Accounts'], $rows);
    }
})->purpose('Schedule bounded account collection and completed-data query imports.');

Schedule::command('moxdop:resources:automate')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->name('moxdop-resource-automation');

// Activity-aware collection: recompute every account's tier (active / idle / dormant) from stored facts nightly
// (each successful collection also refreshes its own account). Idle / dormant accounts then get one light pass per week
// through resource automation; an account whose activity resumed is made due immediately.
Artisan::command('moxdop:collection:activity-refresh', function (): void {
    $tiers = app(ActivityTierService::class);
    $counts = $tiers->refresh();
    $pruned = $tiers->pruneLog();
    $health = $tiers->healthSummary();
    $this->info(sprintf(
        'Collection activity: active=%d idle=%d dormant=%d paused=%d · last 24h planned=%d skipped=%d datasets · pruned %d log rows.',
        $counts['active'], $counts['idle'], $counts['dormant'], $counts['paused'],
        $health['planned_datasets_24h'], $health['skipped_datasets_24h'], $pruned,
    ));
})->purpose('Recompute collection activity tiers from stored facts and report datasets avoided.');

// Monthly fact partitions (incl. compact gsc_f_* tables) for the coming months + DEFAULT safety partition.
Schedule::command('moxdop:db:ensure-partitions --months=3')
    ->dailyAt('03:20')
    ->withoutOverlapping(30)
    ->name('moxdop-db-ensure-partitions');

Schedule::command('moxdop:collection:activity-refresh')
    ->dailyAt('03:35')
    ->withoutOverlapping(60)
    ->name('moxdop-collection-activity-refresh');

// Meta Ads UI readiness is backed by the central integrity registry. Re-run a
// local-only audit daily so newly collected Professional V2 datasets and any
// later integrity regressions are reflected in REAL/PARTIAL_REAL gating.
Schedule::command('moxdop:data-pool-audit --provider=META_ADS')
    ->dailyAt('05:10')
    ->withoutOverlapping(180)
    ->name('moxdop-meta-ads-data-pool-audit');

Artisan::command('moxdop:wordpress:reconcile', function (): void {
    app(WordPressEventReconciliation::class)->tick();
})->purpose('Reconcile WordPress activity through bounded queued collections.');

Schedule::command('moxdop:wordpress:reconcile')
    ->everyMinute()->withoutOverlapping(10)->name('wordpress-event-reconciliation');

// 1.4.1: sites without the WordPress Connector — hourly sitemap lastmod check, targeted crawl of changed pages only.
Artisan::command('moxdop:website:sitemap-watch', function (): void {
    foreach (app(SitemapChangeWatcher::class)->eligibleSiteIds() as $siteId) {
        CheckSitemapChangesJob::dispatch($siteId);
    }
})->purpose('Queue the hourly sitemap change check of websites without the WordPress Connector.');

// Teknik SEO › Google'ın bildirdikleri: each day a small batch of a site's pages goes to the Search Console URL Inspection
// API (service pages first, each URL again after 14 days), so Google's index state reaches the screen without a click.
Artisan::command('moxdop:gsc:inspect-urls {--site= : Yalnız bu web sitesi}', function (): void {
    $sites = DigitalAsset::query()->where('type', 'website')->when($this->option('site'), fn ($q, $id) => $q->whereKey((int) $id))->get();
    foreach ($sites as $site) {
        if (! $site->isOperational()) {
            continue;
        }
        try {
            $run = app(UrlInspectionTargets::class)->start($site);
            $this->line($site->name.': '.($run !== null ? 'URL denetimi başladı (run '.$run->id.')' : 'denetlenecek sayfa yok'));
        } catch (Throwable $e) {
            $this->warn($site->name.': '.$e->getMessage());
        }
    }
})->purpose('Queue today\'s Search Console URL Inspection batch of every website.');

Schedule::command('moxdop:gsc:inspect-urls')
    ->dailyAt('06:05')->withoutOverlapping(60)->name('gsc-inspect-urls');

Schedule::command('moxdop:website:sitemap-watch')
    ->hourlyAt(17)->withoutOverlapping(30)->name('website-sitemap-watch');

// Sorgu otomatik pilotu: AI ile hizmet / filtre (her sorgu bir kez), Bekleyenler içe aktarımı, günlük kümeleme.
Artisan::command('moxdop:queries:autopilot {--clean : Saatlik temizlik (tam tarama + Silinecekler uygulanır)}', function (): void {
    QueryAutopilotJob::dispatch((bool) $this->option('clean'));
    $this->info($this->option('clean') ? 'Temizlik kuyruğa alındı.' : 'Otomatik pilot turu kuyruğa alındı.');
})->purpose('Queue a query autopilot tick (or the hourly clean-up).');
Schedule::command('moxdop:queries:autopilot')
    ->everyFifteenMinutes()->withoutOverlapping(30)->name('queries-autopilot');
Schedule::command('moxdop:queries:autopilot --clean')
    ->hourlyAt(5)->withoutOverlapping(60)->name('queries-autopilot-clean');

// Claude API dönemi (yakup, 2026-10-10): until 25 October every AI operation that may move runs on the Claude API;
// on that day the operations go back to the models they had before (ClaudeApiWindow).
Artisan::command('moxdop:ai:claude-api-window {action : start | end}', function (ClaudeApiWindow $window): int {
    $admin = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
    if ($admin === null) {
        $this->error('Aktif Admin kullanıcı yok.');

        return 1;
    }
    if ($this->argument('action') === 'start') {
        $out = $window->start($admin);
        $this->info(sprintf('Claude API dönemi başladı (%s tarihine kadar): %d işlem taşındı, %d zaten öyleydi, %d yerinde kaldı; bekleyen %d iş yeniden başlatıldı.',
            ClaudeApiWindow::until()->format('d.m.Y'), $out['changed'], $out['unchanged'], $out['kept'], $out['restarted']));
    } else {
        $this->info(sprintf('Claude API dönemi bitti: %d işlem eski modeline döndü.', $window->end($admin)['restored']));
    }

    return 0;
})->purpose('Start or end the Claude API period (free credit until 25 October).');
Schedule::command('moxdop:ai:claude-api-window end')
    ->dailyAt('00:10')->timezone('Europe/Istanbul')->when(fn (): bool => ! ClaudeApiWindow::active())
    ->withoutOverlapping(30)->name('claude-api-window-end');

// Sorgular › Bekleyenler: kütüphanede olan / filtre terimine takılan bekleyen sorgular saatlik temizlenir.
Schedule::command('moxdop:queries:prune-pending')
    ->hourlyAt(23)->withoutOverlapping(30)->name('queries-prune-pending');

// Faz 7: anahtar kelime kalite puanı günlük kopyası (düşüş kuralı için; snapshot yalnızca son değeri tutar).
Schedule::command('moxdop:google-ads:record-quality-scores')
    ->dailyAt('05:40')
    ->withoutOverlapping(30)
    ->name('google-ads-quality-score-history');

// Anahtar Kelime Planlayıcı (yakup, 2026-10-09): ince arama verisi olan markalara talep; salt okuma, marka başına 30 günde bir.
Schedule::command('moxdop:queries:keyword-planner')
    ->dailyAt('05:47')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(120)
    ->name('queries-keyword-planner');

// v2 Faz 3: küme ana sorguları + marka hedef sorguları için DataForSEO arama hacmi (ayda bir, harcama tavanı geçerli).
Schedule::command('moxdop:intel:query-volumes')
    ->monthlyOn(4, '05:37')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(120)
    ->name('intel-query-volumes');

// Faz 9: WordPress Connector v2 sağlık okuması (sürümler, bekleyen güncellemeler, Site Sağlığı).
Schedule::command('moxdop:wordpress:health')
    ->dailyAt('06:20')
    ->withoutOverlapping(60)
    ->name('wordpress-health-daily');

// Faz 10d: gece veritabanı yedeği.
Schedule::command('moxdop:backup')
    ->dailyAt('03:30')
    ->withoutOverlapping(120)
    ->name('system-backup-nightly');

// Faz D: günlük varlık uyarıları (harcama sıçraması/durması, dönüşüm kesilmesi, arama trafiği düşüşü, eski veri, yanıtsız kötü yorum).
Schedule::command('moxdop:alerts:scan')
    ->dailyAt((string) env('MOXDOP_ALERTS_TIME', '06:30'))
    ->withoutOverlapping(60)
    ->name('asset-alerts-daily');

// Kanıt: her aktif bağlantı ve bağlı hesap için en ucuz salt okunur çağrı (heavy kuyruk); başarısız olan Komuta merkezinde.
Schedule::command('moxdop:verify:live')
    ->dailyAt((string) config('moxdop-verification.live.time', '06:20'))
    ->timezone('Europe/Istanbul')
    ->when(fn (): bool => (bool) config('moxdop-verification.live.enabled', true))
    ->withoutOverlapping(60)
    ->name('verify-live-daily');

// Kanıt: toplanan veride eksik gün, etiketsiz reklam trafiği, dönüşüm farkı, para birimi uyuşmazlığı ("Veri şüpheli").
Schedule::command('moxdop:verify:data')
    ->dailyAt((string) config('moxdop-verification.consistency.time', '07:25'))
    ->timezone('Europe/Istanbul')
    ->when(fn (): bool => (bool) config('moxdop-verification.consistency.enabled', true))
    ->withoutOverlapping(60)
    ->name('verify-data-daily');

// Bütçe izleme: Google Ads / Meta hesap durumu, harcama limiti, ön ödemeli bakiye, bugünkü harcama, bütçesi dolan
// kampanya, reddedilen reklam — iki saatte bir (salt okunur), bitince hemen uyarı + telefona bildirim.
Artisan::command('moxdop:ads:budget-watch', function (): void {
    foreach (app(AdBudgetWatch::class)->eligibleAssetIds() as $assetId) {
        CheckAdBudgetJob::dispatch($assetId);
    }
})->purpose('Queue the read-only budget / balance / delivery check of bound Google Ads and Meta accounts.');

Schedule::command('moxdop:ads:budget-watch')
    ->cron('23 */2 * * *')->withoutOverlapping(30)->name('ads-budget-watch');

// Meta ülke + şehir performansı (reklam × ülke / il, sonuçlarla) — her gün son 3 gün, ilk seferde 30 gün (salt okunur).
Artisan::command('moxdop:meta:geo-results', function (): void {
    DigitalAsset::query()->where('type', 'meta_ads')->whereNotNull('brand_id') // brand-assigned only; passive customers keep collecting
        ->whereIn('id', CoreAssetBinding::query()->where('status', CoreAssetBinding::STATUS_ACTIVE)->select('digital_asset_id'))
        // Spread over time: each account 3 minutes after the previous one (shared Meta app-level budget).
        ->orderBy('id')->pluck('id')->values()->each(fn ($id, $index) => CollectMetaGeoResultsJob::dispatch((int) $id)->delay(now()->addMinutes(3 * $index)));
})->purpose('Queue the daily Meta country + city results collection of bound ad accounts.');

Schedule::command('moxdop:meta:geo-results')
    ->dailyAt('05:41')->withoutOverlapping(60)->name('meta-geo-results');

// Faz 0: GBP API içerik saklama (yorum, medya, gönderi, profil anlık görüntüleri) — 30 günden eskiler silinir.
// Performans ve arama anahtar kelimeleri silinmez (altın veri).
Artisan::command('moxdop:gbp:purge-expired', function (): void {
    $this->info('Purged GBP content rows: '.app(GoogleBusinessProfileRetentionService::class)->purgeExpired());
})->purpose('Delete Google Business Profile provider content older than the retention window.');

Schedule::command('moxdop:gbp:purge-expired')
    ->dailyAt('04:40')
    ->withoutOverlapping(60)
    ->name('gbp-content-retention');

// v2 Faz 1: veri saklama (ayda bir) — günlük veriler 16 ay (eskisi aylığa çevrilip silinir), sorgu günlükleri 16 ay
// (aylık hali query_sources, 24 ay), ham kopyalar 90 gün (her sayfanın son HTML'i kalır), telemetri kendi süresi.
Schedule::command('moxdop:retention --apply')
    ->monthlyOn(2, '04:10')
    ->withoutOverlapping(720)
    ->name('data-retention');

// Veri merkezi: the row counts of every source (full table counts) are counted hourly on the background queue; the
// screen reads them from the cache. An erase counts again at once.
Schedule::job(RefreshDataCenterSummaryJob::class)
    ->hourlyAt(41)
    ->name('data-center-summary');

Artisan::command('moxdop:ownership:integrity {--fix : Disable extra / orphan bindings}', function (): void {
    $integrity = app(OwnershipIntegrity::class);
    $problems = $integrity->problems();
    $this->table(['Sorun', 'Varlık', 'Ayrıntı'], array_map(fn (array $p): array => [$p['label'], $p['subject'], $p['detail']], $problems));
    if ($this->option('fix')) {
        $admin = User::query()->where('is_active', true)->whereHas('roles', fn ($q) => $q->where('name', Roles::ADMIN))->orderBy('id')->firstOrFail();
        $this->info('Kapatılan bağlantı: '.$integrity->fix($admin));
    }
})->purpose('List customer / brand / asset / account ownership violations (and fix the safe ones).');

// Günlük hesap keşfi: yeni reklam hesabı / mülk ve kaybedilen erişim kendiliğinden fark edilir (bildirim).
Schedule::command('moxdop:integrations:discover')
    ->dailyAt('05:10')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('integrations-discover-daily');

// Faz 3: marka dönüşüm sözlüğü (GA4 anahtar olay, Ads dönüşüm işlemi, Meta işlem, İşletme Profili) — uyarı taramasından önce.
Schedule::command('moxdop:measurement:refresh')
    ->dailyAt('06:15')
    ->withoutOverlapping(60)
    ->name('brand-measurement-daily');

// Faz 4: kuyruk işçisi yoklaması — her kuyruğa küçük bir iş; işlenince heartbeat yazar (Sistem Sağlığı ve uyarılar).
Schedule::call(function (): void {
    // The heavy queue (Horizon supervisor-heavy) is probed too when it is a separate queue.
    $queues = array_values(array_unique([...(array) config('moxdop-observability.probe_queues', ['default', 'collection']), (string) config('queue.heavy_queue', 'default')]));
    foreach ($queues as $queue) {
        QueueHeartbeatProbeJob::dispatch((string) $queue)->onQueue((string) $queue);
    }
})->everyFiveMinutes()->name('queue-heartbeat-probe')->withoutOverlapping(5);

// Faz 4: durmuş toplamalara günlük ikinci şans (tekrarlayan hata; yeniden kullanılabilir hale gelen bağlantılar).
Schedule::command('moxdop:resources:retry-stopped')
    ->dailyAt('05:10')
    ->withoutOverlapping(30)
    ->name('resource-automation-daily-retry');

// Bağlantı sağlığı (Onarım Faz 1): gecikmiş hesapların çekimi ve taranmamış sitelerin taraması kendiliğinden başlar.
Schedule::command('moxdop:health:repair')
    ->dailyAt('05:40')
    ->withoutOverlapping(30)
    ->name('connection-health-repair');

// Onarım masası (Faz 2): gece değeri hazır olmayan site önerileri hazırlanır; sabah onay bekleyenler bildirilir.
Schedule::command('moxdop:repair:audit')
    ->dailyAt('01:40')
    ->withoutOverlapping(60)
    ->name('repair-site-audit');

Schedule::command('moxdop:repair:prepare')
    ->dailyAt('02:10')
    ->withoutOverlapping(30)
    ->name('repair-desk-prepare');

// Toplu hazırlık (yakup, 2026-10-09): title / description fixes every hour, 15 pages per AI call, until none waits.
Schedule::command('moxdop:repair:prepare --batch-only')
    ->hourlyAt(12)
    ->withoutOverlapping(50)
    ->when(fn (): bool => AiBudget::automaticAllowed('site.seo_fields_batch'))
    ->name('repair-desk-prepare-batch');

Schedule::command('moxdop:repair:verify')
    ->hourlyAt(35)
    ->withoutOverlapping(30)
    ->name('repair-desk-verify');

Schedule::command('moxdop:repair:digest')
    ->dailyAt('09:05')
    ->withoutOverlapping(10)
    ->name('repair-desk-digest');

// Faz 5: sektör paketi uyum denetimi (AI taslakları, Meta reklam metni/hedefleme, site sayfaları, İşletme Profili).
Schedule::command('moxdop:compliance:scan')
    ->dailyAt((string) config('moxdop-sector-packs.scan_time', '06:45'))
    ->withoutOverlapping(60)
    ->name('compliance-scan-daily');

// Faz 6: site erişilebilirliği — her 5 dakikada site başına bir kuyruk işi (üst üste 2 hata = kesinti + telefon bildirimi).
Schedule::call(function (): void {
    if (! config('moxdop-assistant.uptime.enabled', true)) {
        return;
    }
    DigitalAsset::query()->operational()->where('type', 'website')->whereNotNull('primary_url')->pluck('digital_assets.id')
        ->each(fn ($id) => UptimeCheckJob::dispatch((int) $id));
})->everyFiveMinutes()->name('uptime-checks')->withoutOverlapping(5);

// Faz 6: zamanı gelen hatırlatıcılar telefona (her dakika).
Schedule::call(fn () => app(ReminderService::class)->dispatchDue())
    ->everyMinute()->name('reminders-due')->withoutOverlapping(2);

// Marka çalışma alanı: kanal başına AI analisti (Arama; Harita / Google Ads / Meta sınıfları eklenince) — her
// operasyonel marka için haftalık, markalar kuyruğa aralıklı verilir. Tek marka / kanal: --brand / --channel.
Artisan::command('moxdop:analyst:weekly {--brand= : Marka id veya adının bir parçası (ör. Panorama)} {--channel= : Only this channel}', function (): int {
    $engine = app(AnalystEngine::class);
    if ($this->option('brand') !== null) {
        try {
            $brand = ConsoleScope::brand((string) $this->option('brand'));
        } catch (ConsoleScopeException $exception) {
            $this->error($exception->getMessage());

            return 2;
        }
        $channels = app(AnalystRegistry::class)->liveChannels();
        if ($this->option('channel') !== null && ! in_array((string) $this->option('channel'), $channels, true)) {
            $this->error('Bilinmeyen kanal: '.$this->option('channel').'. Geçerli: '.implode(', ', $channels));

            return 2;
        }
        $this->line('Marka #'.$brand->id.' '.$brand->name);
        foreach ($this->option('channel') !== null ? [(string) $this->option('channel')] : $channels as $channel) {
            $run = $engine->queue($brand, $channel, null, 'manual');
            $this->line($channel.': run '.$run->id.' '.$run->status);
        }

        return 0;
    }
    $this->line(json_encode($engine->queueWeekly(), JSON_UNESCAPED_UNICODE));

    return 0;
})->purpose('Queue the brand workspace AI analysts (every live channel × operational brand, staggered).');

Schedule::command('moxdop:analyst:weekly')
    ->weeklyOn(1, '07:40')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->when(fn (): bool => AiBudget::automaticAllowed('analyst.weekly'))
    ->name('analyst-weekly');

// Faz 2: keşfedilen varlıklar → marka adayları (yeni kaynaklar her gün; onaylı gruplar değişmez).
Artisan::command('moxdop:brand-candidates {--sync : Run now instead of queueing}', function (): void {
    // Delegated to Claude: grouping waits for Claude, so it always runs as the resumable job.
    if ($this->option('sync') && ! app(AiTaskQueue::class)->delegated(AiRouteKeys::BRAND_CANDIDATES)) {
        $summary = app(BrandCandidateBuilder::class)->refresh();
        $this->info(sprintf('Yeni kaynak %d · yeni aday %d · AI çağrısı %d (%s)', $summary['new_subjects'], $summary['candidates_created'], $summary['ai_calls'], $summary['ai_status']));
        if ($summary['ai_error'] !== null) {
            $this->error('AI hatası: '.$summary['ai_error'].' — eşleşmeyen hesaplar bir sonraki çalıştırmada yeniden denenir.');
        }

        return;
    }
    RefreshBrandCandidatesJob::dispatch();
    $this->info('Kuyruğa alındı.');
})->purpose('Group discovered websites / accounts into brand candidates (deterministic + one AI call per batch).');

Schedule::command('moxdop:brand-candidates')
    ->dailyAt('06:47')
    ->withoutOverlapping(60)
    ->name('brand-candidates');

// Faz 7: İşletme Profili — onaylı zamanlanmış gönderiler zamanı gelince Google'a gider (ADR-073).
Artisan::command('moxdop:gbp:publish-scheduled', function (): void {
    $this->info('Gönderilen: '.app(ExternalWriteService::class)->releaseScheduled());
})->purpose('Send the Admin-approved scheduled Business Profile posts whose time has come.');

Schedule::command('moxdop:gbp:publish-scheduled')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->name('gbp-publish-scheduled');

// ADR-078: otomatik İşletme Profili gönderileri — her işletme için önümüzdeki 30 gün dolu tutulur (23 günün altına
// inince doldurulur), onaylananlar günü gelince yayınlanır, onaylanmamışların günü geçince düşer.
Artisan::command('moxdop:gbp:fill-post-queue {--force}', function (): void {
    $ids = GbpPostQueue::locations()->pluck('digital_assets.id');
    $ids->each(fn ($id) => FillGbpPostQueueJob::dispatch((int) $id, (bool) $this->option('force')));
    $this->info('Kuyruğa alınan işletme: '.$ids->count());
})->purpose('Plan the next 30 days of automatic Business Profile posts of every location of an operational brand.');

Schedule::command('moxdop:gbp:fill-post-queue')
    ->dailyAt('05:37')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('gbp-fill-post-queue');

Artisan::command('moxdop:gbp:publish-queue', function (): void {
    $this->info(json_encode(app(GbpPostQueue::class)->publishDue(), JSON_UNESCAPED_UNICODE));
})->purpose('Publish the approved automatic Business Profile posts whose time has come; expire drafts whose day passed.');

Schedule::command('moxdop:gbp:publish-queue')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->name('gbp-publish-queue');

// Faz 7: İşletme Profili sistem kontrolleri (profil standartları → öneriler; AI yok), operasyonel markalar.
Artisan::command('moxdop:gbp:suggestions', function (): void {
    $ids = DigitalAsset::query()->operational()->whereIn('type', ['google_business_profile', 'gbp'])->pluck('digital_assets.id');
    $ids->each(fn ($id) => SyncGbpSuggestionsJob::dispatch((int) $id));
    $this->info('Kuyruğa alınan profil: '.$ids->count());
})->purpose('Refresh the Business Profile system-check suggestions (failing profile standards) of operational brands.');

Schedule::command('moxdop:gbp:suggestions')
    ->dailyAt('06:52')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(30)
    ->name('gbp-suggestions-daily');

// Faz 4b site ekranı: rakipler (ayda bir), backlink doğrulama (haftada bir), SSL / alan adı bitişi (her gün).
Schedule::command('moxdop:site competitors')
    ->monthlyOn(3, '05:13')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(120)
    ->name('site-competitors');

Schedule::command('moxdop:site backlinks')
    ->weeklyOn(1, '05:43')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(120)
    ->name('site-backlinks-verify');

Schedule::command('moxdop:site expiry')
    ->dailyAt('05:27')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('site-expiry');
// Faz 4a: web sitesi haftalık yenileme — yeni sayfaların kategorisi, hizmet ↔ sayfa, küme ↔ sayfa, kullanılan ve
// değişen sayfaların özetleri. Yalnız operasyonel markaların siteleri (pasif müşteride AI yok).
Artisan::command('moxdop:site:weekly {--site= : One website asset id}', function (): void {
    $sites = DigitalAsset::query()->where('type', 'website')->whereNotNull('brand_id')
        ->whereIn('brand_id', Brand::query()->operational()->select('id'))
        ->when($this->option('site'), fn ($q, $id) => $q->whereKey((int) $id))
        ->orderBy('id')->pluck('id');
    foreach ($sites as $siteId) {
        SiteOperations::dispatch((int) $siteId, SiteOperations::WEEKLY_REFRESH);
    }
    $this->info('Kuyruğa alınan site: '.$sites->count());
})->purpose('Queue the weekly website refresh (categories, service ↔ page, cluster ↔ page, summaries) of operational brands.');

Schedule::command('moxdop:site:weekly')
    ->weeklyOn(1, '05:52')
    ->withoutOverlapping(60)
    ->when(fn (): bool => AiBudget::automaticAllowed('site.weekly_refresh'))
    ->name('site-weekly');

// İçerik fikir havuzu (yakup, 2026-10-06: "havuzda her koşulda her dilde 20 içerik fikri olsun, üstüne haftalık
// otomatik üretim"; 2026-10-09 "fikir üret butonuna basmayayım"): at 09:17 and 15:17 each operational site with matched clusters gets every active language's pool of
// waiting titles back to ContentCoverage::POOL; on Monday each language also gets the brand's weekly number of fresh
// ideas on top, and in the first week of the month the out-of-cluster opportunities. Only titles: an article is
// written only after the operator approves it.
Artisan::command('moxdop:content:weekly-titles {--site= : One website asset id} {--weekly : Add the weekly fresh ideas (default: Monday)} {--discover : Also out-of-cluster opportunities}', function (ContentCoverage $coverage): void {
    $today = now('Europe/Istanbul');
    $weekly = $this->option('weekly') || $today->isMonday();
    $discover = $this->option('discover') || ($today->isMonday() && $today->day <= 7);
    $matching = $this->option('site') === null ? $coverage->startMatching() : [];
    $needs = $coverage->needs($weekly, $this->option('site') !== null ? (int) $this->option('site') : null);
    foreach ($needs as $need) {
        SiteOperations::dispatch($need['site_id'], SiteOperations::WEEKLY_CONTENT, ['wants' => $need['wants']]);
    }
    $sites = array_column($needs, 'site_id');
    if ($discover) {
        foreach ($sites as $siteId) {
            SiteOperations::dispatch($siteId, SiteOperations::DISCOVERY);
        }
    }
    $this->info(($matching !== [] ? count($matching).' sitede küme eşleştirmesi başlatıldı. ' : '').'Fikir havuzu: '.count($sites).' site, '.array_sum(array_map(fn (array $n): int => count($n['wants']), $needs)).' dil, '.array_sum(array_map(fn (array $n): int => array_sum($n['wants']), $needs)).' başlık istendi'.($discover ? ' (kümeler dışı fırsatlarla)' : '').'.');
})->purpose('Fill every active language of operational sites to the content idea pool, plus weekly fresh ideas on Monday.');

// Marka tamamlama (yakup, 2026-10-07 "Hemen kullan"): brands missing services, places, sector, context or the Search
// Console / GA4 match get an "Otomatik kur" run by themselves, applied when ready; before the morning content run.
Artisan::command('moxdop:brands:autofill {--brand= : One brand id}', function (BrandAutofill $autofill): void {
    $out = $autofill->run($this->option('brand') !== null ? (int) $this->option('brand') : null);
    $this->info('Marka tamamlama: '.collect($out)->map(fn (int $n, string $k): string => $k.' '.$n)->implode(', '));
})->purpose('Fill missing brand facts with an automatic Otomatik kur run and apply it.');

Schedule::command('moxdop:brands:autofill')
    ->dailyAt('02:40')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('brands-autofill');

// Marka verisi denetimi (yakup, 2026-10-09 "bana onaylamak kalsın"): places and services without evidence, profile
// addresses and places the site keeps naming go to the Onarım masası as approval rows (no AI, nothing applied by itself).
Artisan::command('moxdop:brands:audit {--brand= : One brand id}', function (BrandDataAudit $audit): void {
    $out = $audit->run($this->option('brand') !== null ? (int) $this->option('brand') : null);
    $this->info(sprintf('Marka verisi denetimi: %d marka, %d yeni onay satırı, %d satır kapandı.', $out['brands'], $out['proposed'], $out['closed']));
})->purpose('Check brand places and services against their evidence and put the changes on the Onarım masası.');

Schedule::command('moxdop:brands:audit')
    ->dailyAt('03:20')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('brands-data-audit');

// Bing Webmaster (yakup, 2026-10-07): ChatGPT search reads Bing's index; every morning the sites are matched again and
// their weekly Bing searches read (read only).
Artisan::command('moxdop:bing:collect', function (BingWebmasterSync $sync): void {
    if (! BingWebmasterClient::configured()) {
        $this->info('Bing anahtarı yok; atlandı.');

        return;
    }
    $match = $sync->match();
    $out = $sync->collect();
    $this->info(sprintf('Bing: %d site, %d eşleşti, %d satır, %d hata.', $match['sites'], $match['matched'], $out['rows'], $out['failed']));
})->purpose('Match Bing Webmaster sites to websites and read their search queries.');

// Anılma fırsatları (yakup, 2026-10-07): directories / news sites Google shows for the brands' own searches become link
// sources (Rakipler SERPs, rules only). The competitor refresh does it too; this weekly round covers every brand.
Artisan::command('moxdop:mentions:sync', function (SerpMentionSources $mentions): void {
    $added = 0;
    foreach (Brand::query()->operational()->orderBy('id')->get() as $brand) {
        $added += $mentions->sync($brand)['added'];
    }
    $this->info('Anılma fırsatları: '.$added.' yeni kaynak.');
})->purpose('Add directories and news sites ranking for the brands\' searches as link sources.');

Schedule::command('moxdop:mentions:sync')
    ->weeklyOn(1, '07:25')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('mentions-sync');

Schedule::command('moxdop:bing:collect')
    ->dailyAt('07:05')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('bing-collect');

Schedule::command('moxdop:content:weekly-titles')
    ->twiceDaily(9, 15, 17)
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->when(fn (): bool => ContentCoverage::automaticAllowed())
    ->name('content-weekly-titles');

// Küme çakışmaları: rebuilt every night from the stored match (no AI, no site call), so a rule change or a page that
// changed since the last Eşleştir run closes stale overlaps (for example an /en/ page proposed into a Turkish page).
Schedule::command('moxdop:clusters:sync-overlaps')
    ->dailyAt('04:37')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('clusters-sync-overlaps');

// Faz 6: Meta sistem kontrolleri (en çok 10 kontrol → öneriler; AI yok), operasyonel markalar.
Artisan::command('moxdop:meta:suggestions', function (): void {
    $ids = DigitalAsset::query()->operational()->where('type', 'meta_ads')->pluck('digital_assets.id');
    $ids->each(fn ($id) => SyncMetaSuggestionsJob::dispatch((int) $id));
    $this->info('Kuyruğa alınan Meta hesabı: '.$ids->count());
})->purpose('Refresh the Meta system-check suggestions of operational brands.');

Schedule::command('moxdop:meta:suggestions')
    ->dailyAt('06:56')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(30)
    ->name('meta-suggestions-daily');

// Hizmet ortalaması: every Meta / Google Ads account's 30-day numbers per brand service and Meta campaign (rules, no AI),
// plus every website's and Business Profile's per-service numbers for Kazananlar, after the morning collection passes.
Artisan::command('moxdop:ads:service-stats', function (): void {
    $this->info('Kuyruğa alınan varlık: '.AdsCatchUp::queueServiceStats());
})->purpose('Rebuild the cross-brand ad numbers per service (hizmet ortalaması) and the Meta campaign rows.');

Schedule::command('moxdop:ads:service-stats')
    ->dailyAt('07:43')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(30)
    ->name('ads-service-stats-daily');

// Kazananlar: every service's channel leaders and overall ranking of the day (rules), for "yükselenler / düşenler" and
// "liderlik değişimleri"; after the morning per-service numbers.
Artisan::command('moxdop:ads:winners-snapshot', function (): void {
    $this->info('Kaydedilen hizmet: '.app(Winners::class)->snapshot());
})->purpose('Store today\'s winners per service (leaders and ranking).');

Schedule::command('moxdop:ads:winners-snapshot')
    ->dailyAt('10:13')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(30)
    ->name('ads-winners-snapshot-daily');

// Hourly catch-up: a deploy or a missed morning run must not leave Meta / Kazananlar empty until the next day.
Artisan::command('moxdop:ads:catch-up', function (): void {
    $done = app(AdsCatchUp::class)->run();
    $this->info('Hizmet satırları: '.$done['service_stats'].' · kırılım çekimi: '.$done['breakdowns'].' · kazanan kaydı: '.$done['snapshot']);
})->purpose('Rebuild stale per-service rows, fetch missing Meta breakdowns and take a missing winners snapshot.');

Schedule::command('moxdop:ads:catch-up')
    ->hourlyAt(27)
    ->withoutOverlapping(30)
    ->name('ads-catch-up-hourly');

// Faz 5: Google Ads sistem kontrolleri (≤10 kontrol → öneriler; AI yok), operasyonel markalar.
Artisan::command('moxdop:google-ads:suggestions', function (): void {
    $ids = DigitalAsset::query()->operational()->where('type', 'google_ads')->whereNotNull('brand_id')->pluck('digital_assets.id');
    $ids->each(fn ($id) => SyncGoogleAdsSuggestionsJob::dispatch((int) $id));
    $this->info('Kuyruğa alınan Google Ads hesabı: '.$ids->count());
})->purpose('Refresh the Google Ads system-check suggestions of operational brands.');

Schedule::command('moxdop:google-ads:suggestions')
    ->dailyAt('07:07')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(30)
    ->name('google-ads-suggestions-daily');

// Microsoft Clarity: sitelerin günlük ziyaretçi davranışı (öfkeli / çalışmayan tıklama, hızlı geri dönüş, JS hatası,
// kaydırma) → Genel işler › Teknik sağlık. Proje başına günde bir istek (API sınırı günde 10). AI yok.
Artisan::command('moxdop:clarity:pull {--site= : One website asset id}', function (): void {
    $ids = ClarityProject::query()->where('enabled', true)
        ->whereIn('website_asset_id', DigitalAsset::query()->operational()->where('type', 'website')->select('digital_assets.id'))
        ->when($this->option('site'), fn ($q, $id) => $q->where('website_asset_id', (int) $id))
        ->pluck('website_asset_id');
    $ids->each(fn ($id) => PullClarityJob::dispatch((int) $id));
    $this->info('Kuyruğa alınan site: '.$ids->count());
})->purpose('Queue the daily Microsoft Clarity behaviour pull (and its rules) of operational websites.');

Schedule::command('moxdop:clarity:pull')
    ->dailyAt('07:03')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(30)
    ->name('clarity-pull-daily');

// Faz 9: sonuç takibi — uygulanan önerilerin 28. ve 56. gün ölçümü (her nokta bir kez).
Schedule::command('moxdop:outcomes:measure')
    ->dailyAt('07:13')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('outcomes-measure');

// Sayfa puanı (docs/product/CONTENT_IDEAS_BLUEPRINT.md §3): after the night's Search Console collections.
Schedule::command('moxdop:clusters:score-pages')
    ->dailyAt('07:10')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(120)
    ->name('clusters-score-pages');

// Hata merkezi: one morning notice with what needs the operator (ErrorTriage `you` + `code`); nothing when all is fine.
Artisan::command('moxdop:ops:error-digest', function (ErrorTriage $triage, QueryNotifier $notifier): void {
    $counts = $triage->counts();
    $you = (int) ($counts[ErrorTriage::YOU] ?? 0);
    $code = (int) ($counts[ErrorTriage::CODE] ?? 0);
    if ($you + $code === 0) {
        $this->info('Hata merkezi: nothing needs the operator.');

        return;
    }
    $parts = array_filter([$you > 0 ? $you.' iş seni bekliyor' : null, $code > 0 ? $code.' yazılım hatası' : null]);
    $notifier->send(null, 'Hata merkezi: '.implode(', ', $parts).' · sistem '.(int) ($counts[ErrorTriage::AUTO] ?? 0).' sorunu kendisi hallediyor',
        route('operator.settings.system-health', [], false));
    $this->info('Digest sent: you='.$you.' code='.$code);
})->purpose('Daily Hata merkezi digest (only what needs the operator).');
Schedule::command('moxdop:ops:error-digest')->dailyAt('05:52')->name('ops-error-digest')->withoutOverlapping(30);

// Sınıflandırma temizliği: rules only (no AI) over every unlocked page — template / archive sections stop being service
// pages, their service links are removed. Run once after the template-section rule shipped; safe to repeat.
Artisan::command('moxdop:site:recategorize {site? : website asset id}', function (PageCategorizer $categorizer): void {
    $sites = DigitalAsset::query()->where('type', 'website')->whereNotNull('brand_id')->when($this->argument('site'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->get();
    foreach ($sites as $site) {
        $result = $categorizer->categorize($site, useAi: false);
        $pruned = ServicePageMapper::prune($site);
        SiteMetrics::forgetPageTotals((int) $site->id);
        $this->line($site->id.' '.($site->domain ?: $site->name).': rule='.$result['rule'].' links_removed='.$pruned);
    }
    $this->info('Recategorized: '.$sites->count());
})->purpose('Rules-only page recategorization (template / archive sections) and stale service-link cleanup.');

// Marka dosyası: rebuilt every night for operational brands (no AI; unchanged sections keep their hash).
Artisan::command('moxdop:brands:dossier {brand? : brand id}', function (BrandDossier $dossier): void {
    $brands = Brand::query()->operational()->when($this->argument('brand'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->get();
    foreach ($brands as $brand) {
        try {
            // Site akışı (WordPress plugin paired): the next due step of categorize → service ↔ page → cluster ↔ page.
            foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get() as $site) {
                SiteFlow::advance($site);
            }
            // Eksikler: what blocks the brand's AI work, into the work list (fixes run only on the operator's approval).
            app(BrandGaps::class)->sync($brand);
            // Marka çekirdeği: the context copies of services, priority and places follow the brand's own lists.
            app(BrandIntelligenceContextWriteService::class)->projectIdentityFields($brand);
            $dossier->build($brand);
        } catch (Throwable $exception) {
            report($exception);
            $this->warn('Brand '.$brand->id.': '.$exception->getMessage());
        }
    }
    $this->info('Dossiers built: '.$brands->count());
})->purpose('Rebuild the Marka dosyası of operational brands.');
Schedule::command('moxdop:brands:dossier')->dailyAt('04:37')->timezone('Europe/Istanbul')->name('brands-dossier')->withoutOverlapping(60);

// Marka bakım ajanı: Sunday night, one queued review per active brand; a brand whose dossier did not change costs nothing.
Artisan::command('moxdop:brands:care {brand? : brand id} {--force : review even when nothing changed}', function (): void {
    $brands = Brand::query()->operational()->when($this->argument('brand'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->get();
    foreach ($brands as $brand) {
        BrandCare::queue($brand, (bool) $this->option('force'));
    }
    $this->info('Brand care queued: '.$brands->count());
})->purpose('Queue the weekly Marka bakım ajanı review of the active brands.');
Schedule::command('moxdop:brands:care')->weeklyOn(0, '21:13')->timezone('Europe/Istanbul')->name('brands-care')->withoutOverlapping(60)
    ->when(fn (): bool => AiBudget::automaticAllowed('brand.care'));

// Şef: Monday morning plan across the active brands, after Sunday's care reviews.
// AI harcama dökümü: where the money went (per operation, automatic vs operator), and today's automatic ceiling.
Artisan::command('moxdop:ai:costs {--hours=24 : Kaç saat geriye}', function (AiBudget $budget): void {
    $rows = $budget->breakdown((int) $this->option('hours'));
    $this->table(['İşlem', 'Çağrı', 'Toplam $', 'Otomatik $', 'Model'], array_map(fn (array $r): array => [
        $r['label'].' ('.$r['operation'].')', $r['calls'], number_format($r['cost'], 3), number_format($r['auto_cost'], 3), $r['model'] ?? '—',
    ], $rows));
    $this->info(sprintf('Toplam: $%.3f · bugün tüm AI: $%.3f / günlük tavan $%.2f', array_sum(array_column($rows, 'cost')), $budget->dailySpend(), $budget->dailyBudget()));
})->purpose('Show the AI spend per operation (automatic vs operator) and the daily automatic ceiling.');

// Kota denetimi: OpenAI's real costs against our estimate (turns the free-quota accounting off when they disagree).
Artisan::command('moxdop:ai:openai-audit', function (OpenAiCostAudit $audit): void {
    $result = $audit->run();
    $this->info((string) ($result['message'] ?? $result['status']));
})->purpose('Compare OpenAI\'s real costs (Costs API) with the recorded AI spend; disable the free-quota accounting on mismatch.');
Schedule::command('moxdop:ai:openai-audit')->hourlyAt(23)->withoutOverlapping(10)->name('ai-openai-audit');

// Takılı AI işleri: rows a dead worker left "Çalışıyor" (killed for timeout before the tracker closed them on failure).
Artisan::command('moxdop:ai:close-stuck {--minutes=30 : Bu kadar dakikadır çalışıyor görünenler (en uzun iş süresi 28 dk)}', function (): void {
    $before = now()->subMinutes(max(5, (int) $this->option('minutes')));
    $closed = AiLiveOperation::query()->where('status', AiLiveOperation::RUNNING)->where('started_at', '<', $before)
        ->update(['status' => AiLiveOperation::FAILED, 'error' => 'İş yarıda kaldı (zaman aşımı); kapatıldı.', 'finished_at' => now()]);
    $this->info($closed.' takılı AI satırı kapatıldı.');
})->purpose('Close AI job / call rows still marked running long after their worker died.');

// Şef denetimi on demand (it also runs before every weekly plan): errors in what the AI did, rules only.
Artisan::command('moxdop:brands:chief-audit {brand? : brand id}', function (BrandAudit $audit): void {
    $brands = Brand::query()->operational()->when($this->argument('brand'), fn ($q, $id) => $q->whereKey((int) $id))->orderBy('id')->get();
    foreach ($brands as $brand) {
        $this->line($brand->id.' '.$brand->name.': '.$audit->sync($brand).' açık hata');
    }
})->purpose('Şef denetimi: rule checks of the AI decisions of operational brands.');

Artisan::command('moxdop:brands:chief', function (): void {
    RunBrandChiefJob::dispatch();
    $this->info('Chief plan queued.');
})->purpose('Queue Şef\'s weekly plan.');
Schedule::command('moxdop:brands:chief')->weeklyOn(1, '07:41')->timezone('Europe/Istanbul')->name('brands-chief')->withoutOverlapping(60)
    ->when(fn (): bool => AiBudget::automaticAllowed('brand.chief'));

// Geliştirme havuzu: sayfa taraması — every operator screen rendered as an Admin (status, time, queries, error, outline)
// for Claude to find broken / slow / confusing pages (MCP screen-checks). Also after each "Deploy tamamlandı".
Artisan::command('moxdop:screens:check', function (ScreenChecker $checker): void {
    $failed = $checker->run();
    $this->info('Taranan ekran: '.ScreenCheck::query()->count().' · hatalı: '.$failed);
})->purpose('Render every operator screen as an Admin and store status, time, queries, errors and an outline.');

Schedule::command('moxdop:screens:check')
    ->dailyAt('04:50')
    ->timezone('Europe/Istanbul')
    ->withoutOverlapping(60)
    ->name('screens-check');

// WhatsApp inbox: received events → conversations, then reply suggestions (automatic or on click; the webhook and
// the button already dispatch right away, this minute tick is the fallback). Message texts past the KVKK retention
// are blanked nightly; new customer phones link their conversations hourly.
Artisan::command('moxdop:whatsapp:dispatch', function (): void {
    app(WhatsAppDispatch::class)->tick();
})->purpose('Process received WhatsApp events and prepare advisory reply drafts.');

Schedule::command('moxdop:whatsapp:dispatch')
    ->everyMinute()->withoutOverlapping(2)->name('whatsapp-assistant-dispatch');

Schedule::command('moxdop:whatsapp:retention')
    ->dailyAt('03:50')
    ->withoutOverlapping(30)
    ->name('whatsapp-retention-daily');

Schedule::call(fn () => app(WhatsAppContactLinker::class)->linkAll())
    ->hourly()->name('whatsapp-contact-link')->withoutOverlapping(30);
