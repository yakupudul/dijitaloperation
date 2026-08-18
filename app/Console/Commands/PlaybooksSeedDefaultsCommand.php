<?php

namespace App\Console\Commands;

use App\Services\Playbooks\SeedDefaultPlaybooks;
use Illuminate\Console\Command;

class PlaybooksSeedDefaultsCommand extends Command
{
    protected $signature = 'playbooks:seed-defaults';

    protected $description = 'Idempotently seed curated default Playbooks. Never overwrites operator revisions.';

    public function handle(SeedDefaultPlaybooks $seeder): int
    {
        $result = $seeder->seed();
        $this->info(sprintf(
            'Playbooks seed complete. created=%d skipped=%d keys=%s',
            $result['created'],
            $result['skipped'],
            implode(',', $result['keys']),
        ));

        return self::SUCCESS;
    }
}
