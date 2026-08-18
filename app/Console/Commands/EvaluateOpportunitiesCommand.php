<?php

namespace App\Console\Commands;

use App\Models\DigitalAsset;
use App\Services\Opportunities\OpportunityEvaluationService;
use Illuminate\Console\Command;

class EvaluateOpportunitiesCommand extends Command
{
    protected $signature = 'opportunities:evaluate
        {digital_asset_id : Production Digital Asset ID}
        {--rule= : Opportunity rule id or stable id}
        {--definition= : Evidence Definition ID}';

    protected $description = 'Evaluate versioned Opportunity Rules against canonical Evidence and Findings. No provider calls.';

    public function handle(OpportunityEvaluationService $evaluator): int
    {
        $asset = DigitalAsset::query()->find($this->argument('digital_asset_id'));
        if (! $asset instanceof DigitalAsset) {
            $this->error('Digital Asset not found.');

            return self::FAILURE;
        }

        $rule = $this->option('rule');
        $definition = $this->option('definition');

        $stats = $evaluator->evaluateAsset(
            $asset,
            ruleIds: is_string($rule) && $rule !== '' ? [$rule] : null,
            definitionIds: is_string($definition) && $definition !== '' ? [$definition] : null,
        );

        $this->info('Rules considered: '.$stats->rulesConsidered);
        $this->info('Eligible: '.$stats->rulesEligible);
        $this->info('Blocked: '.$stats->rulesBlocked);
        $this->info('Conditions true: '.$stats->conditionsTrue);
        $this->info('Opportunities created: '.$stats->opportunitiesCreated);
        $this->info('Opportunities reused: '.$stats->opportunitiesReused);
        $this->info('Opportunities reopened: '.$stats->opportunitiesReopened);
        $this->info('Opportunities closed: '.$stats->opportunitiesClosed);
        $this->info('Evaluations reused: '.$stats->evaluationsReused);

        return self::SUCCESS;
    }
}
