<?php

use App\Enums\Collection\CollectionRunStatus;
use App\Jobs\Assistant\UptimeCheckJob;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Jobs\Ops\QueueHeartbeatProbeJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Models\DigitalAsset;
use App\Services\Assistant\ReminderService;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\Collection\CollectionErrorRecorder;
use App\Services\Collection\Monitoring\CollectionAccountPresenter;
use App\Services\Collection\RecoverInterruptedCollections;
use App\Services\Collection\StartCollectionService;
use App\Services\Integrations\Google\GoogleBusinessProfileRetentionService;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Integrations\WordPress\WordPressEventReconciliation;
use App\Services\Observability\WorkerHeartbeatService;
use App\Services\Sales\FreeIntentRadar;
use App\Services\WhatsApp\WhatsAppDispatch;
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
    $provider = strtoupper(trim((string) $this->option('provider')));
    $excludeProvider = strtoupper(trim((string) $this->option('exclude-provider')));

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

        if ($provider !== '') {
            $query->where('provider_or_source', $provider);
        } elseif ($excludeProvider !== '') {
            $query->where('provider_or_source', '!=', $excludeProvider);
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

Schedule::command('reports:dispatch-due-deliveries')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->name('reports-dispatch-due-deliveries');

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
Artisan::command('moxdop:resources:automate {--recover-ga4-landing-pages} {--recover-gsc-appearance}', function (): void {
    $service = app(ResourceAutomationService::class);
    if ($this->option('recover-ga4-landing-pages')) {
        $this->info('Recovered empty-dimension write failures: '.$service->recoverGa4LandingFailures());
    }
    if ($this->option('recover-gsc-appearance')) {
        $this->info('Recovered GSC appearance failures: '.$service->recoverGscAppearanceFailures());
    }
    $service->tick();
    if ($this->option('recover-ga4-landing-pages')) {
        $rows = DB::table('resource_automations as a')->join('core_external_resources as r', 'r.id', '=', 'a.external_resource_id')
            ->where('a.collection_enabled', true)->select('r.resource_type', 'a.collection_status')
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
    ->everyFiveMinutes()->withoutOverlapping(10)->name('wordpress-event-reconciliation');

Artisan::command('moxdop:whatsapp:dispatch', function (): void {
    app(WhatsAppDispatch::class)->tick();
})->purpose('Process received WhatsApp events and prepare advisory reply drafts.');

Schedule::command('moxdop:whatsapp:dispatch')
    ->everyMinute()->withoutOverlapping(2)->name('whatsapp-assistant-dispatch');

Artisan::command('moxdop:intent-radar:tick', function (): void {
    app(FreeIntentRadar::class)->tick();
})->purpose('Queue one bounded free public-source radar run.');

Schedule::command('moxdop:intent-radar:tick')
    ->everyFiveMinutes()->withoutOverlapping(5)->name('free-intent-radar');

// SEO Tasks: weekly plan refresh for Search-Console-connected websites (Monday morning).
// Thresholds, quotas and the CTR curve live in config/moxdop-seo-tasks.php.
Schedule::command('moxdop:seo:plan --scheduled')
    ->weeklyOn((int) config('moxdop-seo-tasks.schedule.weekly_day', 1), (string) config('moxdop-seo-tasks.schedule.weekly_time', '06:30'))
    ->withoutOverlapping(120)
    ->name('seo-tasks-weekly-plan');

// Reklam danışmanı (Faz 3): haftalık Google Ads danışman çalıştırması, SEO planından sonra.
// Eşikler config/moxdop-advisor.php içinde.
Schedule::command('moxdop:advisor:plan --scheduled')
    ->weeklyOn((int) config('moxdop-advisor.schedule.weekly_day', 1), (string) config('moxdop-advisor.schedule.weekly_time', '07:00'))
    ->withoutOverlapping(120)
    ->name('advisor-weekly-plan');

// Faz 6: "Yapıldı" işlerin 28 gün sonra ölçülmesi ve (açıksa) haftalık iç özet e-postası.
Schedule::command('moxdop:advisor:measure')
    ->weeklyOn((int) config('moxdop-advisor.schedule.weekly_day', 1), (string) config('moxdop-advisor.measure.weekly_time', '07:30'))
    ->withoutOverlapping(60)
    ->name('advisor-weekly-measure');

// Faz 7: anahtar kelime kalite puanı günlük kopyası (düşüş kuralı için; snapshot yalnızca son değeri tutar).
Schedule::command('moxdop:google-ads:record-quality-scores')
    ->dailyAt('05:40')
    ->withoutOverlapping(30)
    ->name('google-ads-quality-score-history');

// Faz 8: DataForSEO kuyruk sonuçları (ücretsiz okuma) ve zamanı gelen harita grid taramaları (isteğe bağlı, aylık tavan).
Schedule::command('moxdop:intel:collect')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->name('intel-collect-dataforseo-tasks');

Schedule::command('moxdop:intel:grid')
    ->dailyAt('04:30')
    ->withoutOverlapping(30)
    ->name('intel-map-grid-daily');

Schedule::command('moxdop:intel:backlinks')
    ->dailyAt('04:50')
    ->withoutOverlapping(60)
    ->name('intel-backlinks-daily');

Schedule::command('moxdop:intel:reviews')
    ->dailyAt('05:05')
    ->withoutOverlapping(30)
    ->name('intel-reviews-daily');

Schedule::command('moxdop:intel:competitors')
    ->weeklyOn(1, '05:25')
    ->withoutOverlapping(120)
    ->name('intel-competitor-watch-weekly');

// Faz 9: WordPress Connector v2 sağlık okuması (sürümler, bekleyen güncellemeler, Site Sağlığı).
Schedule::command('moxdop:wordpress:health')
    ->dailyAt('06:20')
    ->withoutOverlapping(60)
    ->name('wordpress-health-daily');

// Faz 10b: müşteri sağlığı puanı (her sabah, veri toplamalarından sonra).
Schedule::command('moxdop:customers:health')
    ->dailyAt('07:10')
    ->withoutOverlapping(30)
    ->name('customer-health-daily');

// Faz 10d: gece veritabanı yedeği ve KVKK WhatsApp mesaj saklama süresi.
Schedule::command('moxdop:backup')
    ->dailyAt('03:30')
    ->withoutOverlapping(120)
    ->name('system-backup-nightly');

Schedule::command('moxdop:whatsapp:retention')
    ->dailyAt('03:50')
    ->withoutOverlapping(30)
    ->name('whatsapp-retention-daily');

Schedule::command('moxdop:advisor:digest')
    ->weeklyOn((int) config('moxdop-advisor.schedule.weekly_day', 1), (string) config('moxdop-advisor.digest.weekly_time', '08:00'))
    ->withoutOverlapping(30)
    ->name('advisor-weekly-digest');

// Faz D: günlük varlık uyarıları (harcama sıçraması/durması, dönüşüm kesilmesi, arama trafiği düşüşü, eski veri, yanıtsız kötü yorum).
Schedule::command('moxdop:alerts:scan')
    ->dailyAt((string) env('MOXDOP_ALERTS_TIME', '06:30'))
    ->withoutOverlapping(60)
    ->name('asset-alerts-daily');

// Faz 0: GBP API içerik saklama (yorum, medya, gönderi, profil anlık görüntüleri) — 30 günden eskiler silinir.
// Performans ve arama anahtar kelimeleri silinmez (altın veri).
Artisan::command('moxdop:gbp:purge-expired', function (): void {
    $this->info('Purged GBP content rows: '.app(GoogleBusinessProfileRetentionService::class)->purgeExpired());
})->purpose('Delete Google Business Profile provider content older than the retention window.');

Schedule::command('moxdop:gbp:purge-expired')
    ->dailyAt('04:40')
    ->withoutOverlapping(60)
    ->name('gbp-content-retention');

// Faz 1: veri saklama — ham kopyalar 90 gün (her sayfanın son HTML'i kalır), telemetri kendi süresi,
// 25 aydan eski günlük performans aylık satıra çevrilir. Altın veri (sorgu/arama terimi/anahtar kelime) dokunulmaz.
Schedule::command('moxdop:data:retention')
    ->dailyAt('04:10')
    ->withoutOverlapping(120)
    ->name('data-retention');

// Faz 2b: marka talep tablosu (sorgu → hizmet, bölge, markalı/markasız, değer) — SEO planından önce, haftalık.
Schedule::command('moxdop:demand:build')
    ->weeklyOn((int) config('moxdop-demand.schedule.weekly_day', 1), (string) config('moxdop-demand.schedule.weekly_time', '05:30'))
    ->withoutOverlapping(120)
    ->name('brand-demand-weekly');

// Faz 2b: bölge bazlı SERP kontrolleri (ücretli, marka başına açılır, aylık USD tavanı, 28 gün tekrar kullanım).
Schedule::command('moxdop:demand:serp')
    ->weeklyOn((int) config('moxdop-demand.schedule.weekly_day', 1), (string) config('moxdop-demand.serp.weekly_time', '05:45'))
    ->withoutOverlapping(120)
    ->name('brand-demand-serp-weekly');

// Faz 2b: hizmet sayfası ↔ bölgede üstte çıkan rakip sayfaları karşılaştırması (ücretsiz), SEO planından önce.
Schedule::command('moxdop:demand:compare')
    ->weeklyOn((int) config('moxdop-demand.schedule.weekly_day', 1), (string) config('moxdop-demand.compare.weekly_time', '06:00'))
    ->withoutOverlapping(120)
    ->name('brand-demand-compare-weekly');

// Faz 3: marka dönüşüm sözlüğü (GA4 anahtar olay, Ads dönüşüm işlemi, Meta işlem, İşletme Profili) — uyarı taramasından önce.
Schedule::command('moxdop:measurement:refresh')
    ->dailyAt('06:15')
    ->withoutOverlapping(60)
    ->name('brand-measurement-daily');

// Faz 4: kuyruk işçisi yoklaması — her kuyruğa küçük bir iş; işlenince heartbeat yazar (Sistem Sağlığı ve uyarılar).
Schedule::call(function (): void {
    foreach ((array) config('moxdop-observability.probe_queues', ['default', 'collection']) as $queue) {
        QueueHeartbeatProbeJob::dispatch((string) $queue)->onQueue((string) $queue);
    }
})->everyFiveMinutes()->name('queue-heartbeat-probe')->withoutOverlapping(5);

// Faz 4: durmuş toplamalara günlük ikinci şans (tekrarlayan hata; yeniden kullanılabilir hale gelen bağlantılar).
Schedule::command('moxdop:resources:retry-stopped')
    ->dailyAt('05:10')
    ->withoutOverlapping(30)
    ->name('resource-automation-daily-retry');

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

// Faz 6: yenilemeler — alan adı (RDAP) ve SSL bitiş tarihleri + 30/14/7/1 gün hatırlatması (uyarı taramasından önce).
Schedule::command('moxdop:renewals:daily')
    ->dailyAt('06:05')
    ->withoutOverlapping(60)
    ->name('renewals-daily');

// Faz 6: zamanı gelen hatırlatıcılar telefona (her dakika).
Schedule::call(fn () => app(ReminderService::class)->dispatchDue())
    ->everyMinute()->name('reminders-due')->withoutOverlapping(2);

// Faz 6: WhatsApp konuşmalarını telefon numarasından müşteri / adaylara bağla (yeni eklenen numaralar için).
Schedule::call(fn () => app(WhatsAppContactLinker::class)->linkAll())
    ->hourly()->name('whatsapp-contact-link')->withoutOverlapping(30);
