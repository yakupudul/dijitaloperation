<?php

namespace App\Console\Commands;

use App\Services\Advisor\GoogleAds\QualityScoreHistoryRecorder;
use Illuminate\Console\Command;

/**
 * moxdop:google-ads:record-quality-scores — daily copy of keyword Quality Scores (for the drop rule).
 */
final class RecordQualityScoresCommand extends Command
{
    protected $signature = 'moxdop:google-ads:record-quality-scores';

    protected $description = 'Copy each keyword\'s current Quality Score and components into the daily history table.';

    public function handle(QualityScoreHistoryRecorder $recorder): int
    {
        $this->info(sprintf('Kalite puanı geçmişi: %d satır yazıldı.', $recorder->record()));

        return self::SUCCESS;
    }
}
