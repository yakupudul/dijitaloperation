<?php

namespace App\Console\Commands;

use App\Models\DigitalAsset;
use App\Services\Findings\FindingEvaluationService;
use Illuminate\Console\Command;

class EvaluateFindingsCommand extends Command
{
    protected $signature = 'findings:evaluate
        {digital_asset_id : Production Digital Asset ID}
        {--rule= : Finding rule id or stable id}
        {--definition= : Evidence Definition ID}';

    protected $description = 'Evaluate versioned Finding Rules against canonical Evidence. No provider calls.';

    public function handle(FindingEvaluationService $evaluator): int
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
        $this->info('Findings created: '.$stats->findingsCreated);
        $this->info('Findings reused: '.$stats->findingsReused);
        $this->info('Findings reopened: '.$stats->findingsReopened);
        $this->info('Findings resolved: '.$stats->findingsResolved);
        $this->info('Evaluations reused: '.$stats->evaluationsReused);

        return self::SUCCESS;
    }
}
