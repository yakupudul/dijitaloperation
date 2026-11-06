<?php

namespace App\Services\Site\Backlinks;

use App\Models\Brand;
use App\Models\LinkSource;
use App\Services\Site\SiteDomains;
use Illuminate\Support\Facades\DB;

/**
 * Rakip boşluğu (Backlink hizmeti mimarisi, motor 5), without a link index: a full web link graph (Common Crawl) does not
 * fit the server, so the gap is read from what MoxDOP already has. The brand's competitors are the commercial sites in
 * its tracked Google results (Rakipler screen); the pool's pages are read by the quality gate, which keeps the sites
 * each page links to. A pool source that links to the brand's competitors and not to the brand is offered first, with
 * the competitors named ("bu rehber rakiplerinizden X ve Y'ye link veriyor").
 */
final class LinkRivals
{
    public const int MAX_RIVALS = 15;

    /** @return array<string, int> the brand's competitor domains => how many of its tracked searches they show in */
    public static function rivals(Brand $brand): array
    {
        $own = SiteDomains::ownDomains($brand);
        $count = [];
        foreach (DB::table('brand_cluster_serps')->where('brand_id', $brand->id)->where('status', 'ready')->pluck('results') as $json) {
            foreach ((array) json_decode((string) $json, true) as $result) {
                $host = SiteDomains::host((string) ($result['domain'] ?? $result['url'] ?? ''));
                if ($host === null || ($result['class'] ?? 'ticari') !== 'ticari' || SiteDomains::isOwn($host, $own)) {
                    continue;
                }
                $domain = SiteDomains::registrable($host);
                $count[$domain] = ($count[$domain] ?? 0) + 1;
            }
        }
        arsort($count);

        return array_slice($count, 0, self::MAX_RIVALS, true);
    }

    /**
     * The competitors a pool source links to (none when it already links to the brand).
     *
     * @param  array<string, int>  $rivals
     * @param  list<string>  $own
     * @return list<string>
     */
    public static function gap(LinkSource $source, array $rivals, array $own): array
    {
        $linked = array_map('strval', (array) $source->linked_domains);
        if ($linked === [] || $rivals === []) {
            return [];
        }
        foreach ($linked as $domain) {
            if (SiteDomains::isOwn($domain, $own)) {
                return [];
            }
        }

        return array_values(array_intersect(array_keys($rivals), $linked));
    }
}
