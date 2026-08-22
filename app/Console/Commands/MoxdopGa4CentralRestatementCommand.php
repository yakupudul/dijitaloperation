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

    protected $description = 'Recollect the last 14 closed reporting days for GA4 properties already opted into the central Data Pool.';

    public function handle(Ga4CentralCollectionService $collector): int
    {
        $resourceIds = CollectionResourceRun::query()
            ->where('provider_or_source', 'GA4')
            ->where('status', CollectionRunStatus::Completed->value)
            ->whereNull('digital_asset_id')
            ->whereNotNull('external_resource_id')
            ->whereHas('collectionRun', function ($query): void {
                $query->where('metadata->collection_intent', 'ga4_central_initial');
            })
            ->distinct()
            ->pluck('external_resource_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values();

        if ($resourceIds->isEmpty()) {
            $this->info('No centrally collected GA4 properties are enrolled yet.');

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

            $collector->startRestatement(
                $integration,
                $group->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                null,
            );
            $started += $group->count();
        }

        $this->info("GA4 central 14-day restatement queued for {$started} propert".($started === 1 ? 'y' : 'ies').'.');

        return self::SUCCESS;
    }
}
