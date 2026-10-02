<?php

namespace App\Console\Commands;

use App\Models\Suggestion;
use App\Services\Outcomes\OutcomeTracker;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:outcomes:measure — Sonuç takibi (Faz 9), daily: applied suggestions whose 28 / 56-day window is over are
 * measured once per point (OutcomeTracker).
 */
final class OutcomesMeasureCommand extends Command
{
    protected $signature = 'moxdop:outcomes:measure';

    protected $description = 'Uygulanan önerilerin 28. ve 56. gün sonuçlarını ölçer (işe yaradı / yaramadı / belirsiz).';

    public function handle(OutcomeTracker $tracker): int
    {
        $maxAge = OutcomeTracker::WINDOW_DAYS * count(OutcomeTracker::POINTS) + OutcomeTracker::WAIT_DAYS + 7;
        $points = 0;
        Suggestion::query()->where('status', Suggestion::APPLIED)->whereNotNull('applied_at')
            ->where('applied_at', '<=', now()->subDays(OutcomeTracker::WINDOW_DAYS))
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
        $this->info('Ölçülen sonuç: '.$points);

        return self::SUCCESS;
    }
}
