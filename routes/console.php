<?php

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionRun;
use App\Services\Collection\StartCollectionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

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
