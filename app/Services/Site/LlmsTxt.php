<?php

namespace App\Services\Site;

use App\Models\BrandExpert;
use App\Models\BrandIntelligenceContext;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Models\Page;
use App\Services\Gbp\Desk\GbpDesk;
use Illuminate\Support\Collection;

/**
 * llms.txt (yakup, 2026-10-07, AI görünürlüğü 6. madde): a short Markdown map of the site for AI assistants
 * (llmstxt.org): what the business is, where it serves, how to reach it, and links to its service pages, key pages,
 * experts and recent articles. Built by rules from MoxDOP's own data (no AI, nothing invented): brand, İş bağlamı
 * summary, service ↔ page links, service areas, Business Profile phone / address, experts and the stored pages. The
 * operator sends it to the site (ADR-070 site fix `llms_txt`, connector ≥ 1.11.0); the connector serves /llms.txt.
 */
final class LlmsTxt
{
    public const string MIN_PLUGIN_VERSION = '1.11.0';

    private const int MAX_ARTICLES = 15;

    public function __construct(private readonly GbpDesk $gbp) {}

    public function build(DigitalAsset $site): string
    {
        $brand = $site->brand;
        if ($brand === null) {
            return '';
        }
        $language = SiteScope::primaryLanguage($site);
        $pages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)
            ->when($language !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('language')->orWhere('language', $language)))
            ->orderBy('path')->get(['id', 'url', 'path', 'title', 'h1', 'category', 'meta_description', 'changed_at']);
        $summary = trim((string) BrandIntelligenceContext::query()->where('brand_id', $brand->id)->value('business_summary'));
        $areas = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderByDesc('physical_branch')->orderBy('name')->get()
            ->map(fn (BrandServiceArea $a): string => trim(implode(' / ', array_filter([$a->district_name, $a->city_name]))) ?: (string) $a->name)->filter()->unique()->values()->all();
        $gbpIds = DigitalAsset::query()->where('brand_id', $brand->id)->whereIn('type', ['gbp', 'google_business_profile'])->where('status', 'active')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $profiles = array_values($this->gbp->snapshots($gbpIds));

        $lines = ['# '.$brand->name, ''];
        $lines[] = '> '.self::oneLine($summary !== '' ? $summary : (string) ($pages->firstWhere('path', '/')?->meta_description ?? $brand->name));
        $lines[] = '';
        if ($areas !== []) {
            $lines[] = 'Hizmet verilen yerler: '.implode(', ', array_slice($areas, 0, 15)).'.';
        }
        foreach (array_slice($profiles, 0, 10) as $profile) {
            $contact = array_filter([GbpDesk::shortName((string) $profile['title']), (string) $profile['address_text'], (string) $profile['phone']]);
            if (count($contact) > 1) {
                $lines[] = '- '.implode(' · ', $contact);
            }
        }

        $services = $this->services((int) $brand->id, $pages);
        if ($services !== []) {
            array_push($lines, '', '## Hizmetler', '', ...$services);
        }
        $key = $pages->filter(fn (Page $p): bool => in_array($p->category, ['kurumsal', 'sss'], true) || preg_match('#^/(hakkimizda|hakkinda|iletisim|ekibimiz|ekip|sss|about|contact)/?$#u', (string) $p->path) === 1)
            ->take(8)->map(fn (Page $p): string => self::link($p))->values()->all();
        if ($key !== []) {
            array_push($lines, '', '## Sayfalar', '', ...$key);
        }
        $experts = BrandExpert::query()->where('brand_id', $brand->id)->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (BrandExpert $e): string => '- '.($e->profile_url ? '['.$e->name.']('.$e->profile_url.')' : $e->name).($e->title ? ': '.$e->title : ''))->all();
        if ($experts !== []) {
            array_push($lines, '', '## Uzmanlar', '', ...$experts);
        }
        $articles = $pages->where('category', 'blog')->sortByDesc('changed_at')->take(self::MAX_ARTICLES)->map(fn (Page $p): string => self::link($p))->values()->all();
        if ($articles !== []) {
            array_push($lines, '', '## Yazılar', '', ...$articles);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * Each active service with its page on the site (main service first).
     *
     * @param  Collection<int, Page>  $pages
     * @return list<string>
     */
    private function services(int $brandId, $pages): array
    {
        $offerings = BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName'])->where('brand_id', $brandId)->where('status', 'active')
            ->orderByRaw("CASE WHEN priority = 'main' THEN 0 ELSE 1 END")->get();
        $byId = $pages->keyBy('id');
        $pageOf = OfferingPage::query()->whereIn('brand_offering_id', $offerings->pluck('id')->all() ?: [0])->whereIn('page_id', $byId->keys()->all() ?: [0])
            ->orderBy('id')->get(['brand_offering_id', 'page_id'])->groupBy('brand_offering_id')->map(fn ($links) => $byId->get((int) $links->first()->page_id));

        return $offerings->map(function (BrandOffering $offering) use ($pageOf): string {
            $page = $pageOf->get($offering->id);

            return '- '.($page !== null ? '['.$offering->displayName().']('.$page->url.')' : $offering->displayName()).($page?->meta_description ? ': '.self::oneLine((string) $page->meta_description) : '');
        })->values()->all();
    }

    private static function link(Page $page): string
    {
        $title = trim((string) ($page->title ?: $page->h1 ?: $page->path));
        $title = trim(explode(' | ', explode(' - ', $title)[0])[0]) ?: $title;

        return '- ['.str_replace(['[', ']'], '', $title).']('.$page->url.')'.($page->meta_description ? ': '.self::oneLine((string) $page->meta_description) : '');
    }

    private static function oneLine(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));

        return mb_strlen($text) > 300 ? rtrim(mb_substr($text, 0, 297)).'…' : $text;
    }
}
