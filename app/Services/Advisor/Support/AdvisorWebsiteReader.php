<?php

namespace App\Services\Advisor\Support;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Services\SeoTasks\SeoText;

/**
 * The brand's website asset and its crawled pages (status, redirect, noindex, head text) keyed by url key,
 * so ad channels can check the landing pages they spend on. Stored crawl data only.
 */
final class AdvisorWebsiteReader
{
    /** @return array{available: bool, asset_id: ?int, domain?: ?string, pages: array<string, array<string, mixed>>} */
    public function forBrandOf(DigitalAsset $asset): array
    {
        if ($asset->brand_id === null) {
            return ['available' => false, 'asset_id' => null, 'pages' => []];
        }
        $site = DigitalAsset::query()->where('brand_id', $asset->brand_id)->where('type', 'website')->where('status', 'active')->orderBy('id')->first();
        if ($site === null) {
            return ['available' => false, 'asset_id' => null, 'pages' => []];
        }
        $pages = [];
        WebsitePageProfile::query()->where('website_asset_id', $site->id)->orderBy('id')->chunk(500, function ($profiles) use (&$pages): void {
            foreach ($profiles as $profile) {
                $web = (array) data_get($profile->source_states, 'website', []);
                $url = (string) ($web['url'] ?? $profile->preferred_url);
                if ($url === '') {
                    continue;
                }
                $robots = data_get($web, 'document_head.robots');
                $robots = is_array($robots) ? implode(',', $robots) : (string) $robots;
                $pages[SeoText::urlKey($url)] = [
                    'url' => $url,
                    'status_code' => is_numeric(data_get($web, 'http.status_code')) ? (int) data_get($web, 'http.status_code') : null,
                    'final_url' => data_get($web, 'http.final_url'),
                    'noindex' => str_contains(mb_strtolower($robots), 'noindex'),
                    'title' => data_get($web, 'document_head.title'),
                    'h1' => data_get($web, 'document_head.h1') ?? data_get($web, 'headings.h1.0'),
                    'meta_description' => data_get($web, 'document_head.meta_description'),
                ];
            }
        });

        return ['available' => $pages !== [], 'asset_id' => $site->id, 'domain' => $site->domain, 'pages' => $pages];
    }

    /**
     * Problems of one landing URL against the crawl, or [] when fine / not crawled.
     *
     * @param  array<string, array<string, mixed>>  $pages
     * @return list<string>
     */
    public static function landingIssues(string $url, array $pages): array
    {
        $page = $pages[SeoText::urlKey($url)] ?? null;
        if ($page === null) {
            return [];
        }
        if ($page['status_code'] !== null && $page['status_code'] >= 400) {
            return ['Sayfa hata veriyor ('.$page['status_code'].')'];
        }
        $issues = [];
        if (is_string($page['final_url']) && $page['final_url'] !== '' && SeoText::urlKey($page['final_url']) !== SeoText::urlKey($url)) {
            $issues[] = 'Başka adrese yönleniyor: '.$page['final_url'];
        }
        if ($page['noindex']) {
            $issues[] = 'noindex';
        }

        return $issues;
    }
}
