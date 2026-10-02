<?php

namespace App\Console\Commands;

use App\Services\Opportunities\OpportunityRuleRegistry;
use Illuminate\Console\Command;

class ValidateOpportunityRulesCommand extends Command
{
    protected $signature = 'opportunities:validate-rules';

    protected $description = 'Validate the Opportunity Rule Registry against Evidence Definitions, Finding Rules, and typed-condition invariants.';

    public function handle(OpportunityRuleRegistry $registry): int
    {
        $registry->validate();
        $this->info('Registry '.$registry->registryId().' v'.$registry->version());
        $this->info('Rules: '.count($registry->all()));
        $this->info('Enabled: '.count($registry->enabled()));

        return self::SUCCESS;
    }
}
