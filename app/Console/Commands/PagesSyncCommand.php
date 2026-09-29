<?php

namespace App\Console\Commands;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Collection\Providers\Website\WebsiteRequestFamilyCatalog;
use App\Services\Collection\Website\WebsiteCollectionOrchestrator;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Website\SitemapChangeWatcher;
use Illuminate\Console\Command;
use Throwable;

/**
 * moxdop:pages:sync — fills / refreshes the v2 `pages` table of a website now instead of waiting for the schedule:
 * a WordPress site (paired connector) gets a full connector inventory queued (pages follow it); any other site gets
 * one sitemap pass (bounded number of pages per pass; the hourly watch continues).
 */
final class PagesSyncCommand extends Command
{
    protected $signature = 'moxdop:pages:sync
        {--site=* : Web sitesi varlık id (birden çok verilebilir)}
        {--all : Tüm web siteleri}';

    protected $description = 'Web sitesinin sayfalarını (pages) şimdi yeniler: WordPress için tam envanter kuyruğa alınır, diğerleri için sitemap geçişi yapılır.';

    public function handle(SitemapChangeWatcher $watcher): int
    {
        $ids = array_values(array_filter(array_map('intval', (array) $this->option('site'))));
        if ($ids === [] && ! $this->option('all')) {
            $this->error('--site=<id> ya da --all verin.');

            return self::INVALID;
        }
        $sites = DigitalAsset::query()->where('type', 'website')->when($ids !== [], fn ($q) => $q->whereIn('id', $ids))->orderBy('id')->get();
        if ($sites->isEmpty()) {
            $this->warn('Web sitesi bulunamadı.');

            return self::FAILURE;
        }
        foreach ($sites as $site) {
            $paired = CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
                ->where('enabled', true)->where('config->pairing_state', WordPressConnectorPairingService::PAIRED)->whereHas('credential')->exists();
            try {
                if ($paired) {
                    $run = app(WebsiteCollectionOrchestrator::class)->start(asset: $site, requestFamilyIds: [WebsiteRequestFamilyCatalog::FAMILY_WP_REST], context: [
                        'force_refresh' => true,
                        'idempotency_key' => 'pages-sync:'.$site->id.':'.now()->format('YmdHi'),
                        'collection_intent' => 'wordpress_event_reconciliation',
                        'collection_scope' => 'wordpress',
                        'collection_intent_label' => 'WordPress inventory (pages)',
                        'wordpress_object_ids' => [],
                    ]);
                    $this->line(sprintf('#%d %s: WordPress envanteri kuyrukta (çalıştırma #%d) · şu an %d sayfa', $site->id, $site->name, $run->id, $this->count($site)));

                    continue;
                }
                $result = $watcher->check($site);
                $store = (array) ($result['page_store'] ?? []);
                $this->line(sprintf('#%d %s: sitemap %s · %d URL · alınan %d · yeni %d · güncellenen %d · silinen %d · şu an %d sayfa', $site->id, $site->name,
                    $result['status'], (int) $result['pages'], (int) ($store['fetched'] ?? 0), (int) ($store['created'] ?? 0), (int) ($store['updated'] ?? 0),
                    (int) ($store['removed'] ?? 0), $this->count($site)));
            } catch (Throwable $error) {
                $this->error(sprintf('#%d %s: %s', $site->id, $site->name, $error->getMessage()));
            }
        }

        return self::SUCCESS;
    }

    private function count(DigitalAsset $site): int
    {
        return Page::query()->where('website_asset_id', $site->id)->count();
    }
}
