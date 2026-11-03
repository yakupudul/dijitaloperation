<?php

namespace App\Services\Site\Backlinks;

use App\Models\Backlink;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Integrations\Bing\BingWebmasterClient;
use App\Services\Site\BrandlessSites;
use App\Services\Site\SiteDomains;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Backlink Faz 0 (yakup 2026-10-26): who links to each site, read every week from Bing Webmaster (Google's Links report
 * has no API). For every verified Bing site of an operational, branded website: the pages other sites link to
 * (GetLinkCounts, most linked first) → the linking pages of each (GetUrlLinks, with the link text) → `backlinks`
 * (source bing). A link seen again gets today's last_seen; a Bing link of a page read in full this round that Bing no
 * longer lists is marked kaldirildi with lost_at (a page cut off by the caps is never judged). Own domains are skipped.
 * Read only; a failing site keeps its error on its bing_sites row and the rest go on.
 */
final class BingBacklinkSync
{
    public const string SOURCE = 'bing';

    /** GetLinkCounts pages read per site (Bing pages hold about 20 rows). */
    public const int COUNT_PAGES = 10;

    /** Linked pages of a site whose linking pages are read, most linked first. */
    public const int TARGETS = 150;

    /** GetUrlLinks pages read per linked page. */
    public const int LINK_PAGES = 5;

    public function __construct(private readonly BingWebmasterClient $client) {}

    /** @return array{sites: int, links: int, new: int, lost: int, failed: int} */
    public function collect(?int $assetId = null): array
    {
        $out = ['sites' => 0, 'links' => 0, 'new' => 0, 'lost' => 0, 'failed' => 0];
        $rows = DB::table('bing_sites')->where('verified', true)->when($assetId !== null, fn ($q) => $q->where('digital_asset_id', $assetId))->get();
        foreach ($rows as $row) {
            $site = DigitalAsset::query()->find((int) $row->digital_asset_id);
            $brand = $site?->brand_id !== null ? Brand::query()->find($site->brand_id) : null;
            if ($site === null || $brand === null || ! BrandlessSites::serviced($site) || ! $brand->isOperational()) {
                continue;
            }
            $out['sites']++;
            try {
                $stats = $this->site($brand, (string) $row->site_url);
                $out['links'] += $stats['links'];
                $out['new'] += $stats['new'];
                $out['lost'] += $stats['lost'];
                DB::table('bing_sites')->where('id', $row->id)->update(['links_collected_at' => now(), 'links_error' => null, 'updated_at' => now()]);
            } catch (Throwable $exception) {
                $out['failed']++;
                DB::table('bing_sites')->where('id', $row->id)->update(['links_error' => mb_substr($exception->getMessage(), 0, 500), 'updated_at' => now()]);
            }
        }

        return $out;
    }

    /** @return array{links: int, new: int, lost: int} */
    public function site(Brand $brand, string $siteUrl): array
    {
        $targets = [];
        for ($page = 0; $page < self::COUNT_PAGES; $page++) {
            $answer = $this->client->linkCounts($siteUrl, $page);
            foreach ($answer['links'] as $link) {
                $targets[$link['url']] = max($targets[$link['url']] ?? 0, $link['count']);
            }
            if ($answer['links'] === [] || $page + 1 >= $answer['pages']) {
                break;
            }
        }
        arsort($targets);
        $own = SiteDomains::ownDomains($brand);
        $today = now()->toDateString();
        $stats = ['links' => 0, 'new' => 0, 'lost' => 0];
        foreach (array_slice(array_keys($targets), 0, self::TARGETS) as $target) {
            $complete = false;
            $seen = [];
            for ($page = 0; $page < self::LINK_PAGES; $page++) {
                $answer = $this->client->urlLinks($siteUrl, $target, $page);
                foreach ($answer['links'] as $link) {
                    $domain = SiteDomains::host($link['url']);
                    if ($domain === null || SiteDomains::isOwn($domain, $own)) {
                        continue;
                    }
                    $hash = hash('sha256', $link['url'].'|'.$target);
                    $seen[] = $hash;
                    $stats['links']++;
                    $existing = Backlink::query()->where('brand_id', $brand->id)->where('link_hash', $hash)->first();
                    if ($existing === null) {
                        Backlink::query()->create(['brand_id' => $brand->id, 'source_url' => $link['url'], 'source_domain' => $domain, 'target_url' => $target,
                            'link_hash' => $hash, 'first_seen' => $today, 'last_seen' => $today, 'source' => self::SOURCE, 'status' => 'aktif', 'anchor' => $link['anchor']]);
                        $stats['new']++;

                        continue;
                    }
                    $existing->forceFill(['last_seen' => $today, 'status' => 'aktif', 'lost_at' => null, 'anchor' => $link['anchor'] ?? $existing->anchor])->save();
                }
                if ($answer['links'] === [] || $page + 1 >= $answer['pages']) {
                    $complete = true;

                    break;
                }
            }
            if ($complete) {
                $stats['lost'] += Backlink::query()->where('brand_id', $brand->id)->where('source', self::SOURCE)->where('target_url', $target)
                    ->where('status', 'aktif')->whereNotIn('link_hash', $seen ?: [''])->update(['status' => 'kaldirildi', 'lost_at' => $today, 'updated_at' => now()]);
            }
        }

        return $stats;
    }
}
