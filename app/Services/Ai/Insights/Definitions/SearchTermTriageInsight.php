<?php

namespace App\Services\Ai\Insights\Definitions;

use App\Ai\Agents\Insights\InsightAgent;
use App\Ai\Agents\Insights\SearchTermTriageAgent;
use App\Models\DigitalAsset;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\Ai\Insights\BaseInsight;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Database\Eloquent\Model;

/** "Alakasız arama terimleri" for one Google Ads account (last 30 days). */
final class SearchTermTriageInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'google_ads.search_term_triage';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_SEARCH_TERM_TRIAGE;
    }

    public function label(): string
    {
        return 'Alakasız arama terimlerini bul (negatif önerisi)';
    }

    public function tagStyles(): array
    {
        return [
            'exclude' => ['Negatif ekle', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'review' => ['Sen karar ver', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'keep' => ['Kalsın', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        ];
    }

    public function subjectClass(): string
    {
        return DigitalAsset::class;
    }

    public function agent(): InsightAgent
    {
        return new SearchTermTriageAgent;
    }

    public function tokens(): array
    {
        return [7000, 1200];
    }

    public function freshDays(): int
    {
        return 14;
    }

    public function context(Model $subject): array
    {
        /** @var DigitalAsset $subject */
        $input = app(GoogleAdsAdvisorInputCollector::class)->collect($subject);
        if (! ($input['bound'] ?? false)) {
            return ['error' => 'Google Ads hesabı bağlı değil.'];
        }
        $terms = collect($input['search_terms'] ?? [])->sortByDesc(fn (array $row): float => (float) ($row['cost'] ?? 0))->take(200)
            ->map(fn (array $row): array => array_intersect_key($row, array_flip(['term', 'search_term', 'cost', 'clicks', 'conversions', 'impressions'])))->values()->all();

        return [
            'brand' => $this->brandFacts($subject->brand),
            'period' => $input['period'] ?? null,
            'currency' => $input['currency'] ?? null,
            'search_terms' => $terms,
            'existing_negatives' => collect($input['negatives'] ?? [])->pluck('text')->filter()->unique()->values()->take(400)->all(),
        ];
    }

    public function meta(Model $subject): array
    {
        /** @var DigitalAsset $subject */
        return ['brand_id' => $subject->brand_id, 'digital_asset_id' => $subject->id, 'title' => 'Arama terimi incelemesi · '.$subject->name];
    }
}
