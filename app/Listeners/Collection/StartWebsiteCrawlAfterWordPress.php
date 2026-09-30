<?php

namespace App\Listeners\Collection;

use App\Enums\Collection\CollectionRunStatus;
use App\Events\Collection\CollectionRunCompleted;
use App\Models\DigitalAsset;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use Throwable;

/**
 * WordPress first: when a WordPress inventory run that carries `chain_after_wordpress` finishes, the HTML crawl
 * (and the other public families) start, so the crawl's page list comes from the fresh inventory. A failed
 * inventory still starts the crawl (it then uses the stored inventory or the sitemap); a cancelled one does not.
 */
final class StartWebsiteCrawlAfterWordPress
{
    public function __construct(private readonly WebsiteCollectionOrchestrator $orchestrator) {}

    public function handle(CollectionRunCompleted $event): void
    {
        $run = $event->collectionRun;
        $families = data_get($run->request_context, 'context.chain_after_wordpress');
        if (! is_array($families) || $families === [] || ! $run->status->isTerminal()
            || $run->status === CollectionRunStatus::Cancelled || $run->digital_asset_id === null) {
            return;
        }

        $asset = DigitalAsset::query()->find($run->digital_asset_id);
        if (! $asset instanceof DigitalAsset) {
            return;
        }

        $context = (array) data_get($run->request_context, 'context', []);
        unset($context['chain_after_wordpress'], $context['idempotency_key']);

        try {
            $this->orchestrator->start(
                asset: $asset,
                requestedBy: $run->requestedBy,
                requestFamilyIds: array_values(array_filter($families, 'is_string')),
                context: array_merge($context, [
                    'collection_scope' => 'public',
                    'chained_from_run_id' => $run->id,
                    'idempotency_key' => 'website-after-wordpress:'.$run->id,
                ]),
                includeDataForSeo: in_array('DATAFORSEO', (array) data_get($run->request_context, 'provider_sources', []), true),
                paidEnrichmentConsented: (bool) ($context['paid_enrichment_consented'] ?? false),
                publicDiscovery: (bool) ($context['public_discovery'] ?? false),
            );
        } catch (Throwable $error) {
            // Never break the aggregator that finished the WordPress run; the operator can start the crawl again.
            report($error);
        }
    }
}
