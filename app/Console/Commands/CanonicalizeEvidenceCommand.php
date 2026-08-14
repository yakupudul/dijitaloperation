<?php

namespace App\Console\Commands;

use App\Models\DigitalAsset;
use App\Services\Evidence\CanonicalEvidencePipeline;
use Illuminate\Console\Command;

class CanonicalizeEvidenceCommand extends Command
{
    protected $signature = 'evidence:canonicalize {digital_asset_id : Production Digital Asset ID}';

    protected $description = 'Build canonical Evidence from eligible normalized pool facts (idempotent upsert). Does not create Findings.';

    public function handle(CanonicalEvidencePipeline $pipeline): int
    {
        $asset = DigitalAsset::query()->find($this->argument('digital_asset_id'));
        if (! $asset instanceof DigitalAsset) {
            $this->error('Digital Asset not found.');

            return self::FAILURE;
        }

        $result = $pipeline->canonicalizeAsset($asset);
        $this->info('Run '.$result->run->id);
        $this->info('Created: '.$result->created);
        $this->info('Updated: '.$result->updated);
        $this->info('Ineligible definitions: '.count($result->ineligible));

        foreach ($result->ineligible as $row) {
            $this->warn($row['definition_id'].': '.($row['report']['reason'] ?? 'ineligible'));
        }

        return self::SUCCESS;
    }
}
