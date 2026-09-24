<?php

namespace App\Services\Ai\Insights;

use App\Models\Brand;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/** Defaults shared by the insight definitions. */
abstract class BaseInsight implements InsightDefinition
{
    public function tokens(): array
    {
        return [4000, 900];
    }

    public function freshDays(): int
    {
        return 30;
    }

    public function tagStyles(): array
    {
        return [
            'high' => ['Öncelikli', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'medium' => ['Orta', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'low' => ['Düşük', 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300'],
        ];
    }

    public function meta(Model $subject): array
    {
        return [
            'brand_id' => isset($subject->brand_id) ? (int) $subject->brand_id : null,
            'digital_asset_id' => isset($subject->digital_asset_id) ? (int) $subject->digital_asset_id : null,
            'title' => $this->label(),
        ];
    }

    /**
     * What the brand sells and where (context for every insight about a brand).
     *
     * @return array{name: ?string, sector: ?string, services: list<string>, areas: list<string>}
     */
    protected function brandFacts(?Brand $brand): array
    {
        if ($brand === null) {
            return ['name' => null, 'sector' => null, 'services' => [], 'areas' => []];
        }
        try {
            $services = $brand->offerings()->where('status', 'active')->with('primaryName')->limit(40)->get()
                ->map(fn ($offering): string => (string) ($offering->primaryName?->raw_label ?? ''))->filter()->values()->all();
        } catch (Throwable) {
            $services = [];
        }
        try {
            $areas = $brand->serviceAreas()->where('status', 'active')->limit(30)->get()
                ->map(fn ($area): string => trim(implode(' / ', array_filter([$area->city_name, $area->district_name]))))->filter()->values()->all();
        } catch (Throwable) {
            $areas = [];
        }

        return ['name' => $brand->name, 'sector' => $brand->sector, 'services' => $services, 'areas' => $areas];
    }
}
