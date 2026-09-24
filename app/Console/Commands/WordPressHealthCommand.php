<?php

namespace App\Console\Commands;

use App\Services\Integrations\WordPress\WordPressManagementService;
use Illuminate\Console\Command;

/**
 * moxdop:wordpress:health — daily health read of every paired site with connector ≥ 1.3.0.
 */
final class WordPressHealthCommand extends Command
{
    protected $signature = 'moxdop:wordpress:health';

    protected $description = 'Read WordPress health (versions, pending updates, Site Health) from connector v2 sites.';

    public function handle(WordPressManagementService $management): int
    {
        $stats = $management->refreshAll();
        $this->info(sprintf('%d site okundu, %d başarısız.', $stats['checked'], $stats['failed']));

        return self::SUCCESS;
    }
}
