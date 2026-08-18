<?php

namespace App\Console\Commands;

use App\Services\Findings\FindingRuleRegistry;
use Illuminate\Console\Command;

class ValidateFindingRulesCommand extends Command
{
    protected $signature = 'findings:validate-rules';

    protected $description = 'Validate the Finding Rule Registry against Evidence Definitions and typed-condition invariants.';

    public function handle(FindingRuleRegistry $registry): int
    {
        $registry->validate();
        $this->info('Registry '.$registry->registryId().' v'.$registry->version());
        $this->info('Rules: '.count($registry->all()));
        $this->info('Enabled: '.count($registry->enabled()));

        return self::SUCCESS;
    }
}
