<?php

namespace App\Console\Commands;

use App\Services\Findings\LegacyFindingOriginMigrator;
use Illuminate\Console\Command;

class MigrateLegacyFindingOriginCommand extends Command
{
    protected $signature = 'findings:migrate-legacy-origin';

    protected $description = 'Idempotently classify existing Findings as rule-traceable or LEGACY_UNVERIFIED. Does not invent provenance.';

    public function handle(LegacyFindingOriginMigrator $migrator): int
    {
        $stats = $migrator->migrate();
        $this->info('Mapped: '.$stats['mapped']);
        $this->info('Unverified: '.$stats['unverified']);
        $this->info('Skipped: '.$stats['skipped']);

        return self::SUCCESS;
    }
}
