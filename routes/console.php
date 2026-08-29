<?php

use App\Enums\Collection\CollectionRunStatus;
use App\Jobs\Collection\ExecuteDatasetRunJob;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionRun;
use App\Services\Collection\StartCollectionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
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

    while ((microtime(true) - $startedAt) < $maxRuntime) {
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
            ->orderBy('last_activity_at')
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

Artisan::command('moxdop:collection:status {--provider=}', function () {
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

        $this->line(sprintf(
            'Run #%d | %s | providers=%s | q=%d run=%d retry=%d done=%d fail=%d | attempts=%d locks=%d | activity=%s',
            $run->id,
            $run->status->value,
            $providers !== '' ? $providers : '-',
            $queued,
            $running,
            $retrying,
            $completed,
            $failed,
            $attempts,
            $locked,
            $run->last_activity_at?->diffForHumans() ?? '-',
        ));
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
    $freshLocks = $queuedDatasets->filter(fn (CollectionDatasetRun $dataset): bool =>
        filled($dataset->dispatch_lock_token)
        && $dataset->dispatch_locked_at !== null
        && $dataset->dispatch_locked_at->greaterThan(now()->subMinutes(15))
    )->count();
    $staleLocks = $queuedDatasets->filter(fn (CollectionDatasetRun $dataset): bool =>
        filled($dataset->dispatch_lock_token)
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
})->purpose('Republish queued collection datasets whose Redis dispatch lease has expired.');

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
Schedule::command('moxdop:ga4:central-restatement')
    ->dailyAt('04:10')
    ->withoutOverlapping(120)
    ->name('moxdop-ga4-central-restatement');

// Meta Ads UI readiness is backed by the central integrity registry. Re-run a
// local-only audit daily so newly collected Professional V2 datasets and any
// later integrity regressions are reflected in REAL/PARTIAL_REAL gating.
Schedule::command('moxdop:data-pool-audit --provider=META_ADS')
    ->dailyAt('05:10')
    ->withoutOverlapping(180)
    ->name('moxdop-meta-ads-data-pool-audit');