<?php

namespace App\Services\Site\Backlinks;

use App\Jobs\Site\WriteLinkPack;
use App\Models\Backlink;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteDomains;
use App\Services\Website\PageFetcher;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Anılma motoru (Backlink hizmeti mimarisi, motor 4): pages that write the brand's name without a link are the easiest
 * links to get (the writer already knows the brand). The brand's Google Alerts RSS feed is read every day; each new
 * page is opened, and when it names the brand and has no link to its sites it becomes an anılma row ("adımızı
 * anmışsınız, teşekkürler; okuyucularınız için sitemize bağlantı ekler misiniz?"). Our own sites, social networks and
 * pages that already link are left out.
 */
final class LinkMentions
{
    public const int MAX_ITEMS = 20;

    /** Hosts that are never asked (social networks, video, our own channels, search engines). */
    public const array SKIP = ['facebook.com', 'instagram.com', 'twitter.com', 'x.com', 'youtube.com', 'tiktok.com', 'linkedin.com', 'google.com', 'pinterest.com',
        'sikayetvar.com', 'eksisozluk.com'];

    public function __construct(private readonly PageFetcher $fetcher) {}

    /** @return array{read: int, added: int} */
    public function read(Brand $brand): array
    {
        $feed = trim((string) $brand->link_alert_feed);
        $out = ['read' => 0, 'added' => 0];
        if ($feed === '' || ! $brand->isOperational()) {
            return $out;
        }
        try {
            $xml = Http::timeout(20)->get($feed)->throw()->body();
            $doc = new SimpleXMLElement($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } catch (Throwable) {
            return $out;
        }
        $own = SiteDomains::ownDomains($brand);
        $known = BacklinkSource::query()->where('brand_id', $brand->id)->pluck('domain')->map(fn ($d): string => SiteDomains::registrable((string) $d))->flip();
        $linking = Backlink::query()->where('brand_id', $brand->id)->where('status', 'aktif')->distinct()->pluck('source_domain')->map(fn ($d): string => SiteDomains::registrable((string) $d))->flip();
        $name = SeoText::fold((string) $brand->name);
        foreach (array_slice(self::urls($doc), 0, self::MAX_ITEMS) as $url) {
            $host = SiteDomains::host($url);
            if ($host === null) {
                continue;
            }
            $domain = SiteDomains::registrable($host);
            if ($known->has($domain) || $linking->has($domain) || SiteDomains::isOwn($host, $own) || in_array($domain, self::SKIP, true)) {
                continue;
            }
            $out['read']++;
            try {
                $html = $this->fetcher->fetch($url)['html'] ?? null;
            } catch (Throwable) {
                $html = null;
            }
            if (! is_string($html) || $html === '' || BacklinkVerifier::linksTo($html, $own)
                || ! str_contains(SeoText::fold(strip_tags(str_replace('>', '> ', $html))), $name)) {
                continue;
            }
            // The easiest link there is (the writer already knows the brand): its material is written at once.
            $row = BacklinkSource::query()->create(['brand_id' => $brand->id, 'name' => $domain, 'url' => $url, 'domain' => $host, 'kind' => 'yerel_haber', 'fee' => 'teyit',
                'origin' => 'serp', 'status' => BacklinkSource::NONE, 'engine' => 'anilma', 'lane' => 'masa', 'anchor' => $brand->name, 'material_status' => 'queued',
                'material' => ($email = self::contactEmail($html, $host)) !== null ? ['to' => $email] : null,
                'reason' => 'Bu sayfa '.$brand->name.' adını yazıyor ama siteye bağlantı vermiyor (Google Alerts).']);
            WriteLinkPack::dispatch((int) $row->id);
            $known[$domain] = true;
            $out['added']++;
        }
        $brand->forceFill(['link_alert_read_at' => now()])->save();

        return $out;
    }

    /** The page's own contact address (a mailto: link or a written address on the page's domain), or null. */
    public static function contactEmail(string $html, string $host): ?string
    {
        preg_match_all('/(?:mailto:)?([A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,})/i', html_entity_decode($html), $m);
        $root = SiteDomains::registrable($host);
        foreach (array_unique(array_map('strtolower', $m[1] ?? [])) as $email) {
            if (str_ends_with($email, '@'.$root) || str_ends_with($email, '.'.$root)) {
                return $email;
            }
        }

        return null;
    }

    /**
     * Page addresses of a Google Alerts feed (Atom; the link wraps the page in google.com/url?url=…) or of an RSS feed.
     *
     * @return list<string>
     */
    public static function urls(SimpleXMLElement $doc): array
    {
        $out = [];
        $doc->registerXPathNamespace('a', 'http://www.w3.org/2005/Atom');
        foreach ($doc->xpath('//a:entry/a:link/@href') ?: [] as $href) {
            $out[] = (string) $href;
        }
        foreach ($doc->xpath('//item/link') ?: [] as $link) {
            $out[] = trim((string) $link);
        }

        return array_values(array_unique(array_filter(array_map(function (string $url): ?string {
            $parts = parse_url($url);
            if (str_ends_with((string) ($parts['host'] ?? ''), 'google.com') && ($parts['path'] ?? '') === '/url') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $url = (string) ($query['url'] ?? $query['q'] ?? '');
            }

            return preg_match('#^https?://#i', $url) === 1 ? $url : null;
        }, $out))));
    }
}
