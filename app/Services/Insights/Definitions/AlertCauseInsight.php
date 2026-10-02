<?php

namespace App\Services\Insights\Definitions;

use App\Ai\Agents\Insights\AlertCauseAgent;
use App\Ai\Agents\Insights\InsightAgent;
use App\Models\AssetAlert;
use App\Models\DigitalAsset;
use App\Services\Ai\Insights\BaseInsight;
use App\Services\SeoTasks\SeoPlanInputCollector;
use App\Support\Ai\AiRouteKeys;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/** "Olası neden" for one alert, from the brand's daily numbers across channels. */
final class AlertCauseInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'alerts.cause';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_ALERT_CAUSE;
    }

    public function label(): string
    {
        return 'Olası nedeni bul';
    }

    public function tagStyles(): array
    {
        return [
            'likely' => ['Büyük ihtimalle', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'possible' => ['Olabilir', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'ruled_out' => ['Değil', 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-300'],
        ];
    }

    public function subjectClass(): string
    {
        return AssetAlert::class;
    }

    public function agent(): InsightAgent
    {
        return new AlertCauseAgent;
    }

    public function tokens(): array
    {
        return [8000, 900];
    }

    public function freshDays(): int
    {
        return 7;
    }

    public function context(Model $subject): array
    {
        /** @var AssetAlert $subject */
        $to = CarbonImmutable::now()->subDay()->toDateString();
        $from = CarbonImmutable::now()->subDays(42)->toDateString();
        $assets = DigitalAsset::query()->where('brand_id', $subject->brand_id ?? DigitalAsset::query()->whereKey($subject->digital_asset_id)->value('brand_id'))->get();
        if ($assets->isEmpty()) {
            $assets = DigitalAsset::query()->whereKey($subject->digital_asset_id)->get();
        }
        $ids = $assets->pluck('id')->all();
        $seo = app(SeoPlanInputCollector::class);
        $series = [];
        foreach ($assets->where('type', 'website') as $site) {
            $series['website_'.$site->id] = $this->safely(fn (): array => $this->daily($seo->scopeGa4(DB::table('ga4_property_daily'), $site), $from, $to, ['sessions', 'keyEvents'], 'ga4_property_daily'));
            $series['google_search_'.$site->id] = $this->safely(fn (): array => $this->daily($seo->scopeGsc(DB::table('gsc_property_daily'), $site, 'gsc_property_daily'), $from, $to, ['clicks', 'impressions'], 'gsc_property_daily'));
        }
        $series['google_ads'] = $this->safely(fn (): array => $this->daily(DB::table('google_ads_campaign_daily')->whereIn('digital_asset_id', $ids), $from, $to, ['cost_amount', 'clicks', 'conversions'], 'google_ads_campaign_daily'));
        $series['meta_ads'] = $this->safely(fn (): array => $this->daily(DB::table('meta_account_daily')->whereIn('digital_asset_id', $ids), $from, $to, ['spend', 'clicks'], 'meta_account_daily'));

        return [
            'alert' => ['title' => $subject->title, 'message' => $subject->message, 'kind' => $subject->kind, 'data' => $subject->data,
                'first_detected' => $subject->first_detected_at?->toDateString()],
            'brand' => $this->brandFacts($assets->first()?->brand),
            'daily' => array_filter($series),
            'uptime_failures' => Schema::hasTable('uptime_checks') ? DB::table('uptime_checks')->whereIn('digital_asset_id', $ids)->where('ok', false)
                ->where('checked_at', '>=', $from)->orderByDesc('checked_at')->limit(30)->get(['checked_at', 'status_code', 'error'])->map(fn ($r): array => (array) $r)->all() : [],
            'chart_notes' => Schema::hasTable('chart_annotations') ? DB::table('chart_annotations')->where(fn ($q) => $q->where('brand_id', $assets->first()?->brand_id)->orWhereNull('brand_id'))
                ->whereBetween('starts_on', [$from, $to])->orderBy('starts_on')->limit(40)->get(['starts_on', 'kind', 'title', 'note'])->map(fn ($r): array => (array) $r)->all() : [],
            'other_open_alerts' => AssetAlert::query()->open()->whereIn('digital_asset_id', $ids)->whereKeyNot($subject->id)->limit(10)->get(['title', 'message', 'first_detected_at'])
                ->map(fn (AssetAlert $a): array => ['title' => $a->title, 'message' => $a->message, 'since' => $a->first_detected_at?->toDateString()])->all(),
        ];
    }

    public function meta(Model $subject): array
    {
        /** @var AssetAlert $subject */
        return ['brand_id' => $subject->brand_id, 'digital_asset_id' => $subject->digital_asset_id, 'title' => 'Uyarı nedeni · '.$subject->title];
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, array<string, float>>
     */
    private function daily(Builder $query, string $from, string $to, array $columns, string $table): array
    {
        $columns = array_values(array_filter($columns, fn (string $c): bool => Schema::hasColumn($table, $c)));
        if ($columns === []) {
            return [];
        }
        $select = implode(', ', array_map(fn (string $c): string => 'SUM("'.$c.'") as "'.$c.'"', $columns));
        $out = [];
        foreach ($query->whereBetween('reporting_date', [$from, $to])->groupBy('reporting_date')->orderBy('reporting_date')->selectRaw('reporting_date, '.$select)->get() as $row) {
            foreach ($columns as $c) {
                $out[$c][substr((string) $row->reporting_date, 0, 10)] = round((float) $row->{$c}, 2);
            }
        }

        return $out;
    }

    /** @param callable(): array<string, mixed> $read @return array<string, mixed> */
    private function safely(callable $read): array
    {
        try {
            return $read();
        } catch (Throwable) {
            return [];
        }
    }
}
