<?php

namespace App\Services\Insights\Definitions;

use App\Ai\Agents\Insights\InsightAgent;
use App\Ai\Agents\Insights\LandingFitAgent;
use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Ai\Insights\BaseInsight;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** "Reklam ↔ açılış sayfası uyumu" for one Google Ads account. */
final class LandingFitInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'google_ads.landing_fit';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_LANDING_FIT;
    }

    public function label(): string
    {
        return 'Reklam ve açılış sayfası uyumunu kontrol et';
    }

    public function tagStyles(): array
    {
        return [
            'poor' => ['Uyumsuz', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'partial' => ['Kısmen', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'good' => ['Uyumlu', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        ];
    }

    public function subjectClass(): string
    {
        return DigitalAsset::class;
    }

    public function agent(): InsightAgent
    {
        return new LandingFitAgent;
    }

    public function tokens(): array
    {
        return [6000, 1200];
    }

    public function context(Model $subject): array
    {
        /** @var DigitalAsset $subject */
        $input = app(GoogleAdsAdvisorInputCollector::class)->collect($subject);
        if (! ($input['bound'] ?? false)) {
            return ['error' => 'Google Ads hesabı bağlı değil.'];
        }
        $pages = (array) data_get($input, 'website.pages', []);
        $landing = collect($input['landing_pages'] ?? [])->sortByDesc(fn (array $row): float => (float) ($row['cost'] ?? 0))->take(10)
            ->map(function (array $row) use ($pages): array {
                $page = $pages[SeoText::urlKey((string) $row['url'])] ?? null;

                return [
                    'url' => $row['url'], 'cost' => round((float) ($row['cost'] ?? 0), 2), 'clicks' => $row['clicks'] ?? null, 'conversions' => $row['conversions'] ?? null,
                    'speed_score' => $row['speed_score'] ?? null,
                    'page' => $page === null ? null : array_intersect_key($page, array_flip(['status_code', 'title', 'h1', 'meta_description', 'noindex'])),
                ];
            })->values()->all();

        $ads = collect(data_get($input, 'ads.items', []))->filter(fn (array $ad): bool => ($ad['metrics']['cost'] ?? 0) > 0)
            ->sortByDesc(fn (array $ad): float => (float) ($ad['metrics']['cost'] ?? 0))->take(12)->values();
        $texts = [];
        if ($ads->isNotEmpty() && Schema::hasTable('google_ads_ad_daily')) {
            foreach (DB::table('google_ads_ad_daily')->whereIn('ad_id', $ads->pluck('ad_id')->all())->orderByDesc('reporting_date')->limit(400)->get(['ad_id', 'metadata']) as $row) {
                $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                if (! isset($texts[$row->ad_id]) && is_array($meta) && ! empty($meta['headlines'])) {
                    $texts[$row->ad_id] = ['headlines' => array_slice((array) $meta['headlines'], 0, 15), 'descriptions' => array_slice((array) ($meta['descriptions'] ?? []), 0, 4)];
                }
            }
        }

        return [
            'brand' => $this->brandFacts($subject->brand),
            'currency' => $input['currency'] ?? null,
            'landing_pages' => $landing,
            'ads' => $ads->map(fn (array $ad): array => ['final_urls' => $ad['final_urls'], 'cost' => round((float) ($ad['metrics']['cost'] ?? 0), 2)] + ($texts[$ad['ad_id']] ?? []))->all(),
            'top_keywords' => collect($input['keywords'] ?? [])->filter(fn (array $k): bool => filled($k['text'] ?? null))->sortByDesc(fn (array $k): float => (float) ($k['cost'] ?? 0))->take(30)
                ->map(fn (array $k): array => array_intersect_key($k, array_flip(['text', 'cost', 'conversions', 'quality_score', 'ad_relevance', 'landing_page_experience'])))->values()->all(),
        ];
    }

    public function meta(Model $subject): array
    {
        /** @var DigitalAsset $subject */
        return ['brand_id' => $subject->brand_id, 'digital_asset_id' => $subject->id, 'title' => 'Açılış sayfası uyumu · '.$subject->name];
    }
}
