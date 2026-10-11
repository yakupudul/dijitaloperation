<?php

namespace App\Console\Commands;

use App\Models\Suggestion;
use App\Services\Outcomes\OutcomeTracker;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:outcomes:measure — Sonuç takibi (Faz 9), nightly: every applied suggestion (repair desk approvals included)
 * whose 30 / 90-day window is over is measured once per point against the same number of days before apply
 * (OutcomeTracker); afterwards the learned work-type weights of the desk are rebuilt.
 */
final class OutcomesMeasureCommand extends Command
{
    protected $signature = 'moxdop:outcomes:measure';

    protected $description = 'Uygulanan işlerin 30. ve 90. gün sonuçlarını ölçer (işe yaradı / değişmedi / kötüleşti / ölçülemedi).';

    public function handle(OutcomeTracker $tracker): int
    {
        $maxAge = max(OutcomeTracker::POINTS) + OutcomeTracker::WAIT_DAYS + 7;
        $points = 0;
        Suggestion::query()->where('status', Suggestion::APPLIED)->whereNotNull('applied_at')
            ->where('applied_at', '<=', now()->subDays(min(OutcomeTracker::POINTS)))
            ->where('applied_at', '>=', now()->subDays($maxAge))
            ->chunkById(200, function ($suggestions) use ($tracker, &$points): void {
                foreach ($suggestions as $suggestion) {
                    try {
                        $points += count($tracker->measure($suggestion));
                    } catch (Throwable $error) {
                        report($error);
                    }
                }
            });
        OutcomeTracker::forgetWorkTypes();
        $this->info('Ölçülen sonuç: '.$points);

        return self::SUCCESS;
    }
}
