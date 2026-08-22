<?php

namespace App\Console\Commands;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Services\Collection\Ga4\Ga4CentralCollectionService;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Console\Command;

final class MoxdopGa4CentralRestatementCommand extends Command
{
    protected $signature = 'moxdop:ga4:central-restatement';

    protected $description = 'Smart-refresh centrally enrolled GA4 properties, repairing gaps and restating recent closed reporting days.';

    public function handle(Ga4CentralCollectionService $collector): int
    {
        $resourceIds = CollectionResourceRun::query()
            ->where('provider_or_source', 'GA4')
            ->whereNull('digital_asset_id')
            ->whereNotNull('external_resource_id')
            ->where('metadata->collection_scope', 'provider_resource_first')
            ->whereIn('status', [
                CollectionRunStatus::Completed->value,
                CollectionRunStatus::Partial->value,
                CollectionRunStatus::Failed->value,
                CollectionRunStatus::Cancelled->value,
            ])
            ->distinct()
            ->pluck('external_resource_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($resourceIds->isEmpty()) {
            $this->info('No centrally enrolled GA4 properties found.');

            return self::SUCCESS;
        }

        $resources = CoreExternalResource::query()
            ->whereIn('id', $resourceIds->all())
            ->where('provider', ProviderRegistry::GOOGLE)
            ->where('resource_type', GoogleResourceType::GA4_PROPERTY)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->get(['id', 'integration_id'])
            ->groupBy('integration_id');

        $started = 0;
        foreach ($resources as $integrationId => $group) {
            $integration = CoreIntegration::query()->find((int) $integrationId);
            if (! $integration instanceof CoreIntegration || ! $integration->isActive()) {
                continue;
            }

            try {
                $collector->startSmartUpdate(
                    $integration,
                    $group->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    null,
                );
                $started += $group->count();
            } catch (\InvalidArgumentException $e) {
                $this->warn('Skipped integration #'.$integration->id.': '.$e->getMessage());
            }
        }

        $this->info("GA4 smart refresh queued for {$started} propert".($started === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
