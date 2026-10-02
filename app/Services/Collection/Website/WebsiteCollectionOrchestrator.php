<?php

namespace App\Services\Collection\Website;

use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\Providers\DataForSeo\DataForSeoRequestFamilyCatalog;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use RuntimeException;

/**
 * Starts shared-engine Website production collection for one Website Digital Asset.
 * Does not pull Google/Meta sibling bindings.
 */
final class WebsiteCollectionOrchestrator
{
    public function __construct(
        private readonly StartCollectionService $starter,
    ) {}

    /**
     * @param  list<string>|null  $requestFamilyIds
     * @param  array<string, mixed>  $context
     */
    public function start(
        DigitalAsset $asset,
        ?User $requestedBy = null,
        ?array $requestFamilyIds = null,
        array $context = [],
        bool $includeDataForSeo = false,
        bool $paidEnrichmentConsented = false,
        bool $publicDiscovery = false,
    ): CollectionRun {
        if ((string) $asset->type !== 'website') {
            throw new InvalidArgumentException('Website production collection requires a Website Digital Asset.');
        }

        $providers = ['WEBSITE_DIRECT', 'DOMAIN_DNS_TLS', 'PAGESPEED_TECHNICAL'];
        if ($includeDataForSeo) {
            $providers[] = 'DATAFORSEO';
        }

        $families = $requestFamilyIds;
        if ($families === null) {
            // The Website data center collects only production-ready public website families.
            // Authenticated CMS/site-connector families are added only when their production
            // connector is explicitly enabled; they must never be silently planned here.
            $families = WebsiteRequestFamilyCatalog::publicFamilies();

            if ($this->hasPairedWordPressConnector($asset)) {
                $providers[] = 'WORDPRESS_SITE_CONNECTOR';
                $families = array_values(array_unique(array_merge(
                    $families,
                    WebsiteRequestFamilyCatalog::connectorFamilies(),
                )));
            }

            if ($includeDataForSeo) {
                $families = array_values(array_unique(array_merge(
                    $families,
                    DataForSeoRequestFamilyCatalog::supportedFamilies(),
                )));
            }
        }

        if (in_array(WebsiteRequestFamilyCatalog::FAMILY_WP_REST, $families, true)) {
            $providers[] = 'WORDPRESS_SITE_CONNECTOR';
            $providers = array_values(array_unique($providers));
        }

        // WordPress first: the page list of the HTML crawl comes from the WordPress inventory, so a general
        // collection runs the inventory alone and the rest starts when it finishes (StartWebsiteCrawlAfterWordPress).
        $afterWordPress = $this->familiesAfterWordPress($families, $context);
        if ($afterWordPress !== []) {
            $families = [WebsiteRequestFamilyCatalog::FAMILY_WP_REST];
            $context['chain_after_wordpress'] = $afterWordPress;
        }

        $lock = Cache::lock('website-collection-admission:'.$asset->id, 60);
        if (! $lock->get()) {
            // A concurrent trigger (sitemap watch, WordPress event, operator) is admitting a run right now: that run
            // does the work.
            if (($active = $this->activeRun($asset)) instanceof CollectionRun) {
                return $active;
            }
            throw new RuntimeException('Website collection admission is already in progress.');
        }

        try {
            // Already collecting: a no-op that returns the active run instead of an exception thrown at jobs / users.
            if (($active = $this->activeRun($asset)) instanceof CollectionRun) {
                return $active;
            }

            return $this->starter->start(new StartCollectionRequest(
                digitalAsset: $asset,
                // Automatic WordPress refreshes are System runs: an Incremental (freshness) trigger plans every
                // Website / WordPress family as not eligible, so the refresh would collect nothing.
                triggerType: ($context['collection_intent'] ?? null) === 'wordpress_event_reconciliation'
                    ? CollectionTriggerType::System : CollectionTriggerType::Manual,
                requestedBy: $requestedBy,
                bindingIds: [],
                requestFamilyIds: $families,
                providerSources: $providers,
                dateRange: null,
                idempotencyKey: $context['idempotency_key'] ?? null,
                forceRefresh: (bool) ($context['force_refresh'] ?? false),
                context: array_merge($context, [
                    'collection_intent' => $context['collection_intent'] ?? 'website_production_collection',
                    'collection_intent_label' => $context['collection_intent_label'] ?? 'Website production collection',
                    'allow_multi_asset_bindings' => false,
                    'paid_enrichment_consented' => $paidEnrichmentConsented,
                    'public_discovery' => $publicDiscovery,
                    'website_intelligence_version' => 'v1',
                ]),
            ));
        } finally {
            $lock->release();
        }
    }

    public function activeRun(DigitalAsset $asset): ?CollectionRun
    {
        return CollectionRun::query()->where('digital_asset_id', $asset->id)
            ->whereIn('status', ['queued', 'running', 'retrying', 'cancellation_requested'])->latest('id')->first();
    }

    /**
     * Families that wait for the WordPress inventory: everything but WP_REST when a page crawl rides along with it.
     * A targeted (changed-object) refresh and an already chained run are not split.
     *
     * @param  list<string>  $families
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    private function familiesAfterWordPress(array $families, array $context): array
    {
        if (! in_array(WebsiteRequestFamilyCatalog::FAMILY_WP_REST, $families, true)
            || isset($context['chained_from_run_id'])
            || data_get($context, 'targeted_verification.urls', []) !== []
            || array_intersect($families, [WebsiteRequestFamilyCatalog::FAMILY_PUBLIC_CRAWL, WebsiteRequestFamilyCatalog::FAMILY_HTTP_HTML_DIAGNOSIS]) === []) {
            return [];
        }

        return array_values(array_diff($families, [WebsiteRequestFamilyCatalog::FAMILY_WP_REST]));
    }

    private function hasPairedWordPressConnector(DigitalAsset $asset): bool
    {
        return CoreConnection::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('config->pairing_state', WordPressConnectorPairingService::PAIRED)
            ->where('enabled', true)
            ->whereNotNull('last_success_at')
            ->whereHas('credential')
            ->exists();
    }
}
