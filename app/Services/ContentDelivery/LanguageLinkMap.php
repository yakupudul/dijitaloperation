<?php

namespace App\Services\ContentDelivery;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Which languages a WordPress site has (Polylang, from the connector's site snapshot) and where each page lives in
 * another language (Polylang translations in the content snapshot, else hreflang of crawled pages). Read only.
 */
final class LanguageLinkMap
{
    /** @return list<array{slug: string, name: string, locale: string, default: bool, home_url: string}> */
    public function languages(DigitalAsset $site): array
    {
        if (! Schema::hasTable('website_cms_site_snapshot')) {
            return [];
        }
        $metadata = DB::table('website_cms_site_snapshot')->where('digital_asset_id', $site->id)->orderByDesc('observed_at')->value('metadata');
        $metadata = is_string($metadata) ? json_decode($metadata, true) : $metadata;
        $out = [];
        foreach ((array) data_get($metadata, 'languages', []) as $language) {
            if (is_array($language) && filled($language['slug'] ?? null)) {
                $out[] = ['slug' => strtolower((string) $language['slug']), 'name' => (string) ($language['name'] ?? $language['slug']), 'locale' => (string) ($language['locale'] ?? ''),
                    'default' => (bool) ($language['default'] ?? false), 'home_url' => (string) ($language['home_url'] ?? '')];
            }
        }

        return $out;
    }

    public function home(DigitalAsset $site, string $language): ?string
    {
        foreach ($this->languages($site) as $row) {
            if ($row['slug'] === $language && $row['home_url'] !== '') {
                return $row['home_url'];
            }
        }
        $base = $this->baseUrl($site);

        return $base !== null ? rtrim($base, '/').'/'.$language.'/' : null;
    }

    /**
     * Source URL (normalised key) → the same page in the target language.
     *
     * @return array<string, string>
     */
    public function map(DigitalAsset $site, string $targetLanguage): array
    {
        $map = [];
        if (Schema::hasTable('website_cms_object_snapshot')) {
            $rows = [];
            foreach (DB::table('website_cms_object_snapshot')->where('digital_asset_id', $site->id)->whereIn('object_type', ['page', 'post'])
                ->orderBy('observed_at')->get(['object_id', 'permalink', 'status', 'metadata']) as $row) {
                $rows[(string) $row->object_id] = $row;
            }
            foreach ($rows as $row) {
                $metadata = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                $targetId = (string) (((array) ($metadata['translations'] ?? []))[$targetLanguage] ?? '');
                $target = $rows[$targetId] ?? null;
                if (filled($row->permalink) && $target !== null && filled($target->permalink) && $target->status === 'publish' && $targetId !== (string) $row->object_id) {
                    $map[SeoText::urlKey((string) $row->permalink)] = (string) $target->permalink;
                }
            }
        }
        foreach (WebsitePageProfile::query()->where('website_asset_id', $site->id)->limit(2000)->get(['preferred_url', 'source_states']) as $profile) {
            $key = SeoText::urlKey((string) $profile->preferred_url);
            if (isset($map[$key])) {
                continue;
            }
            foreach ((array) data_get($profile->source_states, 'website.document_head.hreflang', []) as $alternate) {
                $lang = strtolower(explode('-', (string) ($alternate['hreflang'] ?? ''))[0]);
                if ($lang === $targetLanguage && filled($alternate['href'] ?? null)) {
                    $map[$key] = (string) $alternate['href'];
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * Rewrites internal links of a localized body: a known translation, else the target-language home. External
     * links and anchors stay as they are.
     *
     * @param  array<string, string>  $map
     * @param  list<string>  $hosts  hosts of the site (links to them are internal)
     */
    public static function rewrite(string $html, array $map, ?string $home, array $hosts): string
    {
        $hosts = array_map(fn (string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? $h, array_filter($hosts));

        return preg_replace_callback('/<a\s+href="([^"]*)"/i', static function (array $m) use ($map, $home, $hosts): string {
            $url = html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $internal = str_starts_with($url, '/') && ! str_starts_with($url, '//') || ($host !== '' && in_array(preg_replace('/^www\./', '', $host), $hosts, true));
            if (! $internal) {
                return $m[0];
            }
            $absolute = str_starts_with($url, '/') && $hosts !== [] ? 'https://'.$hosts[0].$url : $url;
            $target = $map[SeoText::urlKey($absolute)] ?? $home;

            return $target !== null ? '<a href="'.htmlspecialchars($target, ENT_QUOTES).'"' : $m[0];
        }, $html) ?? $html;
    }

    /** @return list<string> */
    public function hosts(DigitalAsset $site): array
    {
        $hosts = [];
        foreach ([$site->domain ?? null, $site->primary_url ?? null, $this->baseUrl($site)] as $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $host = parse_url(str_contains($value, '://') ? $value : 'https://'.$value, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                $hosts[] = strtolower($host);
            }
        }

        return array_values(array_unique($hosts));
    }

    private function baseUrl(DigitalAsset $site): ?string
    {
        if (Schema::hasTable('website_cms_site_snapshot')) {
            $home = DB::table('website_cms_site_snapshot')->where('digital_asset_id', $site->id)->orderByDesc('observed_at')->value('home_url');
            if (filled($home)) {
                return (string) $home;
            }
        }
        $domain = trim((string) ($site->primary_url ?? $site->domain ?? ''));

        return $domain === '' ? null : (str_contains($domain, '://') ? $domain : 'https://'.$domain);
    }
}
