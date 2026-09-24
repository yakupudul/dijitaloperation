<?php

namespace App\Console\Commands;

use App\Services\Intel\ReviewIntelService;
use Illuminate\Console\Command;

/**
 * moxdop:intel:reviews — queue review reads for brands that switched review intelligence on (inside the cap).
 */
final class IntelReviewsCommand extends Command
{
    protected $signature = 'moxdop:intel:reviews';

    protected $description = 'Queue Google review reads for the brand and nearby competitors (opt-in brands).';

    public function handle(ReviewIntelService $reviews): int
    {
        $stats = $reviews->runDue();
        $this->info(sprintf('Yenilenen %d marka, atlanan (tavan/bağlantı) %d.', $stats['refreshed'], $stats['skipped']));

        return self::SUCCESS;
    }
}
