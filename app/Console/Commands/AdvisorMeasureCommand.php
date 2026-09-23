<?php

namespace App\Console\Commands;

use App\Services\Advisor\AdvisorOutcomeMeasurer;
use Illuminate\Console\Command;

/**
 * moxdop:advisor:measure — measure "Yapıldı" advisor items and SEO tasks whose 28-day window has passed.
 */
final class AdvisorMeasureCommand extends Command
{
    protected $signature = 'moxdop:advisor:measure';

    protected $description = 'Measure done advisor items and SEO tasks 28 days after completion (observed change, not causation).';

    public function handle(AdvisorOutcomeMeasurer $measurer): int
    {
        $counts = $measurer->measureDue();
        $this->info(sprintf('Ölçüldü: %d danışman önerisi, %d SEO görevi.', $counts['advisor'], $counts['seo']));

        return self::SUCCESS;
    }
}
