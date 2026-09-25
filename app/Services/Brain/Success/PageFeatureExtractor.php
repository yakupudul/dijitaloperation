<?php

namespace App\Services\Brain\Success;

use App\Models\DigitalAsset;
use App\Models\IntelligenceProjection\WebsitePageProfile;
use App\Services\Demand\PageContentMetrics;
use App\Services\SeoTasks\SeoStoredHtmlReader;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Measured facts about one cluster page, from the stored crawl HTML (no fetch, no AI): length, structure, FAQ,
 * schema, price and place mentions, internal links, images, and CLUSTER COVERAGE — the share of the cluster's
 * queries whose words the page actually contains (how many of the topic's sub-questions it can answer).
 */
final class PageFeatureExtractor
{
    public function __construct(private readonly SeoStoredHtmlReader $pages) {}

    /** @var array<int, array<string, WebsitePageProfile>> */
    private array $profiles = [];

    /**
     * @param  array<string, true>  $clusterKeys
     * @return array<string, mixed>|null
     */
    public function extract(DigitalAsset $site, string $url, array $clusterKeys, array $places = []): ?array
    {
        $profile = $this->profile($site, $url);
        if ($profile === null) {
            return null;
        }
        try {
            $html = $this->pages->html($site, $profile);
        } catch (Throwable) {
            $html = null;
        }
        if ($html === null || trim($html) === '') {
            return null;
        }

        return $this->fromHtml($site, $url, $html, $clusterKeys, $places);
    }

    /**
     * Measure one page from its HTML and store the facts.
     *
     * @param  array<string, true>  $clusterKeys
     * @param  list<string>  $places
     * @return array<string, mixed>
     */
    public function fromHtml(DigitalAsset $site, string $url, string $html, array $clusterKeys, array $places = []): array
    {
        $metrics = PageContentMetrics::from($url, $html, $places);
        $folded = ' '.$metrics['text_folded'].' ';
        $covered = 0;
        foreach (array_keys($clusterKeys) as $key) {
            $words = array_filter(explode(' ', $key), static fn (string $w): bool => mb_strlen($w) >= 3);
            if ($words !== [] && collect($words)->every(static fn (string $w): bool => str_contains($folded, ' '.mb_substr($w, 0, max(4, mb_strlen($w) - 2))))) {
                $covered++;
            }
        }
        $features = [
            'words' => $metrics['words'], 'h2_count' => $metrics['h2_count'], 'faq' => $metrics['faq'],
            'schema_types' => $metrics['schema_types'], 'has_price' => $metrics['has_price'], 'mentions_place' => $metrics['mentions_place'],
            'internal_links' => $metrics['internal_links'], 'images' => $metrics['images'],
            'coverage' => $clusterKeys !== [] ? round($covered / count($clusterKeys), 3) : null,
            'medical_schema' => array_values(array_intersect($metrics['schema_types'], ['MedicalProcedure', 'MedicalWebPage', 'Physician', 'Dentist', 'MedicalClinic', 'FAQPage'])) !== [],
        ];
        $hash = hash('sha256', $metrics['text_folded']);
        $existing = DB::table('brain_page_features')->where('digital_asset_id', $site->id)->where('url_key', SeoText::urlKey($url))->first();
        $values = ['url' => $url, 'content_hash' => $hash, 'features' => json_encode($features), 'computed_at' => now(), 'updated_at' => now()];
        if ($existing !== null) {
            DB::table('brain_page_features')->where('id', $existing->id)->update($values);
        } else {
            DB::table('brain_page_features')->insert($values + ['digital_asset_id' => $site->id, 'url_key' => SeoText::urlKey($url), 'created_at' => now()]);
        }

        $visible = preg_replace('#<(script|style|noscript|svg|template)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;

        return $features + ['text' => mb_substr(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($visible))) ?? ''), 0, 6000), 'hash' => $hash];
    }

    private function profile(DigitalAsset $site, string $url): ?WebsitePageProfile
    {
        if (! isset($this->profiles[$site->id])) {
            $this->profiles[$site->id] = WebsitePageProfile::query()->where('website_asset_id', $site->id)->get()
                ->keyBy(fn (WebsitePageProfile $p): string => SeoText::urlKey((string) $p->preferred_url))->all();
        }

        return $this->profiles[$site->id][SeoText::urlKey($url)] ?? null;
    }

    /** Places the brand serves, folded, for the "mentions place" fact. @return list<string> */
    public static function places(int $brandId): array
    {
        return DB::table('brand_service_areas')->where('brand_id', $brandId)->where('status', 'active')->get(['city_name', 'district_name'])
            ->flatMap(fn ($a): array => array_filter([$a->city_name, $a->district_name]))->map(fn ($p): string => SeoText::fold((string) $p))->unique()->values()->all();
    }
}
