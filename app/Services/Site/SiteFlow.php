<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandDossier;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Site akışı — the website's demand ↔ content chain runs by itself once the brand's WordPress plugin is paired:
 *
 *  1. Sayfalar: collected pages (WordPress first, crawl for the rest).
 *  2. Sınıflandırma + hizmet ↔ sayfa (SiteOperations::SETUP: rules first, AI for the rest; at most
 *     PageCategorizer::UNATTENDED_AI_LIMIT unsure pages without the operator).
 *  3. Küme ↔ sayfa (SiteOperations::CLUSTER_AUDIT, "Eşleştir"): every approved cluster of the brand's services gets its
 *     page, coverage, gaps and the other pages that answer the same need (ClusterOverlaps → work list); a cluster
 *     without a page gets its content idea on İçerik fikirleri.
 *
 * advance() starts the next step that is due (one at a time, never while one runs): nightly (moxdop:brands:dossier),
 * after a site setup finishes and after clustering approves new clusters. Step 3 runs again only when its inputs
 * changed (approved clusters of the brand, page contents) or rows were never read — no daily AI loop.
 */
final class SiteFlow
{
    /** A "running" mark older than this is stale (a job that died). */
    private const int RUNNING_HOURS = 3;

    /** @return string setup | audit | running | ready | waiting:not_operational | waiting:wordpress | waiting:pages */
    public static function advance(DigitalAsset $site, bool $setup = true): string
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return 'waiting:not_operational';
        }
        if (! self::wordpressPaired($site)) {
            return 'waiting:wordpress';
        }
        if (Page::query()->where('website_asset_id', $site->id)->doesntExist()) {
            return 'waiting:pages';
        }
        if (self::running($site)) {
            return 'running';
        }
        if ($setup && BrandDossier::siteNeedsSetup($site)) {
            SiteOperations::dispatch((int) $site->id, SiteOperations::SETUP, ['unattended' => true]);

            return 'setup';
        }
        if (self::auditDue($site)) {
            SiteOperations::dispatch((int) $site->id, SiteOperations::CLUSTER_AUDIT);

            return 'audit';
        }

        return 'ready';
    }

    public static function wordpressPaired(DigitalAsset $site): bool
    {
        return CoreConnection::query()->where('digital_asset_id', $site->id)->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)->where('config->pairing_state', WordPressConnectorPairingService::PAIRED)->exists();
    }

    /** Küme ↔ sayfa must run: rows never read, or the approved clusters / page contents changed since the last run. */
    public static function auditDue(DigitalAsset $site): bool
    {
        $fingerprint = self::fingerprint($site);
        if ($fingerprint === null) {
            return false;
        }
        if (BrandClusterPage::query()->where('website_asset_id', $site->id)->where('excluded', false)->whereNull('audited_at')->exists()) {
            return true;
        }

        return Cache::get(self::key($site)) !== $fingerprint;
    }

    /** Called when Eşleştir finished: the inputs it read are remembered. */
    public static function audited(DigitalAsset $site): void
    {
        Cache::forever(self::key($site), self::fingerprint($site));
        Cache::forever(self::key($site).':at', now()->toIso8601String());
    }

    /**
     * The flow as the operator sees it: one line per step, done or not, with the numbers behind it.
     *
     * @return list<array{key: string, label: string, done: bool, detail: string}>
     */
    public static function steps(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        if ($brand === null) {
            return [];
        }
        $pages = Page::query()->where('website_asset_id', $site->id)->count();
        $areas = SiteScope::areas($brand)->count();
        $servicePages = BrandDossier::servicePageCount($site);
        $offerings = SiteScope::offerings($brand);
        $linked = $offerings->filter(fn (BrandOffering $o): bool => OfferingPage::query()->where('brand_offering_id', $o->id)
            ->whereIn('page_id', Page::query()->where('website_asset_id', $site->id)->select('id'))->exists())->count();
        $rows = BrandClusterPage::query()->where('website_asset_id', $site->id)->where('excluded', false);
        $total = (clone $rows)->count();
        $read = (clone $rows)->whereNotNull('audited_at')->count();
        $noPage = (clone $rows)->whereNotNull('audited_at')->whereNull('page_id')->count();
        $overlaps = Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', ClusterOverlaps::DECISION)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])->get(['action'])
            ->filter(fn (Suggestion $s): bool => (int) data_get($s->action, 'site_id') === (int) $site->id)->count();
        $at = Cache::get(self::key($site).':at');

        return [
            ['key' => 'wordpress', 'label' => 'WordPress eklentisi', 'done' => self::wordpressPaired($site),
                'detail' => self::wordpressPaired($site) ? 'bağlı' : 'bağlı değil — akış bekliyor'],
            ['key' => 'pages', 'label' => 'Sayfalar', 'done' => $pages > 0, 'detail' => number_format($pages, 0, ',', '.').' sayfa'],
            ['key' => 'areas', 'label' => 'Hizmet bölgeleri', 'done' => $areas > 0, 'detail' => $areas > 0 ? $areas.' bölge' : 'yok — Eksikler\'de öneri'],
            ['key' => 'service_pages', 'label' => 'Hizmet sayfaları', 'done' => $servicePages > 0, 'detail' => $servicePages.' sayfa'],
            ['key' => 'service_match', 'label' => 'Hizmet ↔ sayfa', 'done' => $offerings->isNotEmpty() && $linked === $offerings->count(),
                'detail' => $linked.' / '.$offerings->count().' hizmet eşleşti'],
            ['key' => 'cluster_match', 'label' => 'Küme ↔ sayfa', 'done' => $total > 0 && $read === $total,
                'detail' => $read.' / '.$total.' küme okundu'.($at !== null ? ' · '.CarbonImmutable::parse((string) $at)->diffForHumans() : '')],
            ['key' => 'results', 'label' => 'Sonuç', 'done' => $read > 0, 'detail' => $noPage.' kümede sayfa yok (içerik önerisi) · '.$overlaps.' çakışma'],
        ];
    }

    private static function running(DigitalAsset $site): bool
    {
        foreach ([[SiteOperations::SETUP, []], [SiteOperations::SETUP, ['unattended' => true]], [SiteOperations::CLUSTER_AUDIT, []]] as [$operation, $params]) {
            $status = SiteOperations::status((int) $site->id, $operation, $params);
            if (($status['status'] ?? null) === 'running' && isset($status['at'])
                && CarbonImmutable::parse((string) $status['at'])->gt(now()->subHours(self::RUNNING_HOURS))) {
                return true;
            }
        }

        return false;
    }

    /** Approved clusters of the brand's services + the site's page contents; null when there is nothing to match. */
    private static function fingerprint(DigitalAsset $site): ?string
    {
        $brand = SiteScope::brandOf($site);
        if ($brand === null) {
            return null;
        }
        $clusters = self::approvedClusterIds($brand);
        if ($clusters === []) {
            return null;
        }
        $pages = Page::query()->where('website_asset_id', $site->id)->orderBy('id')->toBase()->get(['id', 'content_hash', 'category'])
            ->map(fn (object $p): string => $p->id.':'.$p->content_hash.':'.$p->category)->implode(',');

        return hash('sha256', implode(',', $clusters).'|'.$pages);
    }

    /** @return list<int> */
    private static function approvedClusterIds(Brand $brand): array
    {
        $services = SiteScope::offerings($brand)->pluck('service_catalog_item_id')->filter()->unique()->all();

        return Cluster::query()->where('approved', true)->whereIn('service_id', $services ?: [0])
            ->when($brand->sector_id !== null, fn ($q) => $q->where('sector_id', $brand->sector_id))
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private static function key(DigitalAsset $site): string
    {
        return 'site-flow:audit:'.$site->id;
    }
}
