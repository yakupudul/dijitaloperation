<?php

namespace App\Console\Commands;

use App\Services\BrandIntelligence\LegacyBicGoalsOfferingsMigrator;
use Illuminate\Console\Command;

class MigrateLegacyBicGoalsOfferingsCommand extends Command
{
    protected $signature = 'bic:migrate-goals-offerings';

    protected $description = 'Migrate legacy BIC business_goals / conversion_goals / priority_offerings into stable Goal and Offering entities (idempotent).';

    public function handle(LegacyBicGoalsOfferingsMigrator $migrator): int
    {
        $result = $migrator->migrateAll();
        $this->info('Brands migrated: '.$result['stats']['brands']);
        $this->info('Business goals upserted: '.$result['stats']['business_goals']);
        $this->info('Conversion goals upserted: '.$result['stats']['conversion_goals']);
        $this->info('Offerings created: '.$result['stats']['offerings']);
        $this->info('Structural collapses: '.$result['stats']['collapsed']);
        $this->info('Conflicts: '.count($result['conflicts']));

        foreach ($result['conflicts'] as $conflict) {
            $this->warn('Brand '.$conflict['brand_id'].': '.$conflict['reason']);
        }

        return self::SUCCESS;
    }
}
