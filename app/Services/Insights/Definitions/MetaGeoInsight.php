<?php

namespace App\Services\Insights\Definitions;

use App\Ai\Agents\Insights\InsightAgent;
use App\Ai\Agents\Insights\MetaGeoAgent;
use App\Models\DigitalAsset;
use App\Services\Ai\Insights\BaseInsight;
use App\Services\MetaAds\MetaGeoResultsReader;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Hangi hizmet, hangi bölge, hangi kitle dönüşüm getirdi" for one Meta ad account: reads campaign / ad set / ad names
 * with country × city results (last 90 days) and the ad sets' targeting (age, gender, interests, custom audiences).
 */
final class MetaGeoInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'meta.geo_results';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_META_GEO;
    }

    public function label(): string
    {
        return 'Hizmet × bölge × kitle: nerede dönüşüm geldi?';
    }

    public function tagStyles(): array
    {
        return [
            'winner' => ['İyi çalışıyor', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
            'waste' => ['Boşa harcıyor', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'test' => ['Denenmeli', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
        ];
    }

    public function subjectClass(): string
    {
        return DigitalAsset::class;
    }

    public function agent(): InsightAgent
    {
        return new MetaGeoAgent;
    }

    public function tokens(): array
    {
        return [8000, 1400];
    }

    public function freshDays(): int
    {
        return 7;
    }

    public function context(Model $subject): array
    {
        /** @var DigitalAsset $subject */
        $end = now()->toDateString();
        $start = now()->subDays(90)->toDateString();
        $reader = app(MetaGeoResultsReader::class);
        $rows = $reader->combinations((int) $subject->id, $start, $end);
        if ($rows === []) {
            return ['error' => 'Ülke / şehir sonuç verisi henüz yok. Önce "Veriyi getir" ile toplayın.'];
        }
        $summary = $reader->summary((int) $subject->id, $start, $end);

        return [
            'brand' => $this->brandFacts($subject->brand),
            'period' => ['start' => $start, 'end' => $end],
            'currency' => $summary['currency'],
            'countries' => array_map(fn (array $country): array => [
                'country' => $country['country'] ?: 'birden fazla ülke',
                'spend' => $country['spend'], 'leads' => $country['leads'], 'purchases' => $country['purchases'], 'messages' => $country['messages'],
            ], array_slice($summary['countries'], 0, 15)),
            'rows' => $rows,
            'adset_targeting' => $this->targeting((int) $subject->id, array_values(array_unique(array_filter(array_column($rows, 'adset_id'))))),
        ];
    }

    public function meta(Model $subject): array
    {
        /** @var DigitalAsset $subject */
        return ['brand_id' => $subject->brand_id, 'digital_asset_id' => $subject->id, 'title' => 'Meta hizmet × bölge × kitle · '.$subject->name];
    }

    /**
     * @param  list<string>  $adsetIds
     * @return list<array<string, mixed>>
     */
    private function targeting(int $assetId, array $adsetIds): array
    {
        if ($adsetIds === [] || ! Schema::hasTable('meta_adset_targeting_snapshot')) {
            return [];
        }

        return DB::table('meta_adset_targeting_snapshot')->where('digital_asset_id', $assetId)->whereIn('adset_id', array_slice($adsetIds, 0, 80))
            ->get(['adset_id', 'adset_name', 'optimization_goal', 'targeting'])
            ->unique('adset_id')
            ->map(function ($row): array {
                $targeting = json_decode((string) $row->targeting, true);
                $targeting = is_array($targeting) ? $targeting : [];
                $interests = collect((array) data_get($targeting, 'flexible_spec', []))
                    ->flatMap(fn ($spec): array => array_merge((array) data_get($spec, 'interests', []), (array) data_get($spec, 'behaviors', [])))
                    ->pluck('name')->filter()->unique()->take(12)->values()->all();

                return array_filter([
                    'adset' => $row->adset_name,
                    'goal' => $row->optimization_goal,
                    'age' => isset($targeting['age_min']) || isset($targeting['age_max']) ? ($targeting['age_min'] ?? '?').'-'.($targeting['age_max'] ?? '65+') : null,
                    'genders' => match ((array) ($targeting['genders'] ?? [])) {
                        [1] => 'erkek', [2] => 'kadın', default => null
                    },
                    'cities' => collect((array) data_get($targeting, 'geo_locations.cities', []))->pluck('name')->filter()->take(10)->values()->all() ?: null,
                    'regions' => collect((array) data_get($targeting, 'geo_locations.regions', []))->pluck('name')->filter()->take(10)->values()->all() ?: null,
                    'interests' => $interests ?: null,
                    'custom_audiences' => count((array) ($targeting['custom_audiences'] ?? [])) ?: null,
                    'advantage_audience' => data_get($targeting, 'targeting_automation.advantage_audience') ? true : null,
                ], fn ($value): bool => $value !== null);
            })->values()->all();
    }
}
