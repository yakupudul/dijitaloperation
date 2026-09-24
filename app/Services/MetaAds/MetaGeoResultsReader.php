<?php

namespace App\Services\MetaAds;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reads meta_geo_results_daily for the audience tab (country → cities) and for the AI geo insight
 * (campaign / ad set / ad × country × city with results).
 */
final class MetaGeoResultsReader
{
    /**
     * @return array{has_data: bool, last_date: ?string, currency: ?string, countries: list<array<string, mixed>>}
     */
    public function summary(int $assetId, string $start, string $end): array
    {
        $empty = ['has_data' => false, 'last_date' => null, 'currency' => null, 'countries' => []];
        if (! Schema::hasTable(MetaGeoResults::TABLE)) {
            return $empty;
        }
        $base = fn () => DB::table(MetaGeoResults::TABLE)->where('digital_asset_id', $assetId)->whereBetween('reporting_date', [$start, $end]);
        $sums = 'sum(spend) as spend, sum(impressions) as impressions, sum(clicks) as clicks, sum(leads) as leads, sum(purchases) as purchases, sum(purchase_value) as purchase_value, sum(messages) as messages';
        $countries = $base()->where('level', 'country')->groupBy('country')->selectRaw('country, '.$sums)->get();
        if ($countries->isEmpty()) {
            return ['last_date' => DB::table(MetaGeoResults::TABLE)->where('digital_asset_id', $assetId)->max('reporting_date')] + $empty;
        }
        $regions = $base()->where('level', 'region')->groupBy('country', 'region')->selectRaw('country, region, '.$sums)->get()->groupBy('country');

        $out = [];
        foreach ($countries as $row) {
            $out[(string) $row->country] = $this->metrics($row) + ['country' => (string) $row->country, 'regions' => []];
        }
        foreach ($regions as $country => $rows) {
            $key = (string) $country;
            $out[$key] ??= ['country' => '', 'spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'leads' => 0.0, 'purchases' => 0.0, 'purchase_value' => 0.0, 'messages' => 0.0, 'results' => 0.0, 'cost_per_result' => null, 'ctr' => null, 'regions' => []];
            $out[$key]['regions'] = $rows->map(fn ($row): array => $this->metrics($row) + ['region' => (string) $row->region])
                ->sortByDesc('spend')->values()->all();
        }
        $list = array_values($out);
        usort($list, fn (array $a, array $b): int => ($a['country'] === '') <=> ($b['country'] === '') ?: $b['spend'] <=> $a['spend']);

        return [
            'has_data' => true,
            'last_date' => (string) $base()->max('reporting_date'),
            'currency' => $base()->whereNotNull('currency')->value('currency'),
            'countries' => $list,
        ];
    }

    /**
     * Rows for the AI: campaign / ad set / ad × country × city with spend and results, most spend first.
     *
     * @return list<array<string, mixed>>
     */
    public function combinations(int $assetId, string $start, string $end, int $limit = 150): array
    {
        if (! Schema::hasTable(MetaGeoResults::TABLE)) {
            return [];
        }

        return DB::table(MetaGeoResults::TABLE)->where('digital_asset_id', $assetId)->where('level', 'region')
            ->whereBetween('reporting_date', [$start, $end])
            ->groupBy('campaign_name', 'adset_id', 'adset_name', 'ad_name', 'country', 'region')
            ->selectRaw('campaign_name, adset_id, adset_name, ad_name, country, region, sum(spend) as spend, sum(clicks) as clicks, sum(leads) as leads, sum(purchases) as purchases, sum(purchase_value) as purchase_value, sum(messages) as messages')
            ->orderByDesc('spend')->limit($limit)->get()
            ->map(fn ($row): array => array_filter([
                'campaign' => $row->campaign_name,
                'adset_id' => $row->adset_id,
                'adset' => $row->adset_name,
                'ad' => $row->ad_name,
                'country' => $row->country !== '' ? $row->country : null,
                'city' => $row->region,
                'spend' => round((float) $row->spend, 2),
                'clicks' => (int) $row->clicks,
                'leads' => round((float) $row->leads, 1),
                'purchases' => round((float) $row->purchases, 1),
                'purchase_value' => round((float) $row->purchase_value, 2),
                'messages' => round((float) $row->messages, 1),
            ], fn ($value): bool => $value !== null && $value !== 0.0 && $value !== 0 && $value !== ''))
            ->all();
    }

    /** @return array<string, mixed> */
    private function metrics(object $row): array
    {
        $spend = round((float) $row->spend, 2);
        $results = (float) $row->leads + (float) $row->purchases + (float) $row->messages;

        return [
            'spend' => $spend,
            'impressions' => (int) $row->impressions,
            'clicks' => (int) $row->clicks,
            'leads' => round((float) $row->leads, 1),
            'purchases' => round((float) $row->purchases, 1),
            'purchase_value' => round((float) $row->purchase_value, 2),
            'messages' => round((float) $row->messages, 1),
            'results' => round($results, 1),
            'cost_per_result' => $results > 0 ? round($spend / $results, 2) : null,
            'ctr' => (int) $row->impressions > 0 ? round((int) $row->clicks / (int) $row->impressions * 100, 2) : null,
        ];
    }
}
