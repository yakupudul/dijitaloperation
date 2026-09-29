<?php

namespace App\Services\Analyst\Meta;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\LeadOutcome;
use App\Services\Advisor\MetaAds\MetaAdsAdvisorInputCollector;
use App\Services\Advisor\MetaAds\MetaAdsAdvisorRuleEngine;
use App\Services\Compliance\ComplianceChecker;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\MetaAds\MetaAdsSpecialistBindingResolver;
use App\Services\MetaAds\MetaGeoResults;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stored-data facts of a brand's Meta ad accounts for the Meta analyst (no provider calls, no AI). One entry per
 * Meta Ads asset of the brand with a real ad-account binding; accounts are never summed across currencies.
 *
 * Reuses the Meta Ads advisor: its input collector (campaigns, ad sets, ads, creatives, typed results, pixels,
 * breakdowns, change history) and its rule engine (creative fatigue, saturation, learning, spend without results,
 * pixel health, placements, landing pages, change impact) are candidate facts. On top: 28-day vs previous 28-day
 * windows, objective fit, CBO / ABO budgets, learning-limited ad sets, region results vs the brand's service
 * areas, age / gender, video hold, live sector-compliance check of the ad texts and lead outcomes (ADR-074).
 */
final class MetaFacts
{
    public const int WINDOW_DAYS = 28;

    public const int MAX_ADS = 40;

    public const int MAX_REGIONS = 25;

    /** Objectives that serve a lead-generation business (clinic appointments). */
    public const array LEAD_OBJECTIVES = ['OUTCOME_LEADS', 'LEAD_GENERATION', 'MESSAGES', 'CONVERSIONS', 'OUTCOME_SALES', 'OUTCOME_ENGAGEMENT'];

    /** Optimization goals that optimize for a lead / conversation / conversion. */
    public const array LEAD_GOALS = ['LEAD_GENERATION', 'QUALITY_LEAD', 'OFFSITE_CONVERSIONS', 'CONVERSATIONS', 'VALUE', 'QUALITY_CALL'];

    /** @var array<int, array<string, mixed>> brand id => analysis (per request) */
    private array $memo = [];

    public function __construct(
        private readonly MetaAdsSpecialistBindingResolver $bindings,
        private readonly MetaAdsAdvisorInputCollector $collector,
        private readonly MetaAdsAdvisorRuleEngine $rules,
    ) {}

    /** @return list<DigitalAsset> the brand's Meta Ads assets with a real ad-account binding */
    public function assets(Brand $brand): array
    {
        return DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'meta_ads')->orderBy('id')->get()
            ->filter(fn (DigitalAsset $asset): bool => $this->bindings->resolve((string) $asset->id)->isReal())->values()->all();
    }

    /** One-line reason when the channel has no data, else null. */
    public function missing(Brand $brand): ?string
    {
        $analysis = $this->analyze($brand);
        if ($analysis['accounts'] === []) {
            return 'Veri yok: Meta reklam hesabı bağlı değil.';
        }
        foreach ($analysis['accounts'] as $account) {
            if ($account['totals']['cur']['spend'] > 0 || $account['totals']['prev']['spend'] > 0) {
                return null;
            }
        }

        return 'Veri yok: son '.(self::WINDOW_DAYS * 2).' günde Meta harcaması yok.';
    }

    /**
     * @return array{accounts: array<int, array<string, mixed>>, leads: array<string, mixed>, currencies: list<string>}
     */
    public function analyze(Brand $brand): array
    {
        if (isset($this->memo[$brand->id])) {
            return $this->memo[$brand->id];
        }
        $areas = $brand->serviceAreas()->where('status', 'active')->get()
            ->flatMap(fn ($area): array => array_filter([SeoText::fold((string) $area->city_name), SeoText::fold((string) $area->district_name)]))
            ->unique()->values()->all();
        $rules = app(SectorPackRegistry::class)->rulesForBrand($brand);
        $accounts = [];
        foreach ($this->assets($brand) as $asset) {
            try {
                $accounts[(int) $asset->id] = $this->account($asset, $areas, $rules);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
        $accounts = array_filter($accounts);
        $currencies = array_values(array_unique(array_filter(array_map(fn (array $a): ?string => $a['currency'], $accounts))));
        $period = $accounts !== [] ? reset($accounts)['period'] : null;

        return $this->memo[$brand->id] = [
            'accounts' => $accounts,
            'currencies' => $currencies,
            'leads' => $this->leads($brand, $period, $accounts, $currencies),
        ];
    }

    /**
     * @param  list<string>  $areas  folded city / district names of the brand's active service areas
     * @return array<string, mixed>|null
     */
    private function account(DigitalAsset $asset, array $areas, Collection $rules): ?array
    {
        $input = $this->collector->collect($asset);
        if (! ($input['bound'] ?? false)) {
            return null;
        }
        $end = CarbonImmutable::parse($input['period']['end']);
        $cur = [$end->subDays(self::WINDOW_DAYS - 1)->toDateString(), $end->toDateString()];
        $prev = [$end->subDays(self::WINDOW_DAYS * 2 - 1)->toDateString(), $end->subDays(self::WINDOW_DAYS)->toDateString()];
        $last7 = $end->subDays(6)->toDateString();
        $prev7 = [$end->subDays(13)->toDateString(), $end->subDays(7)->toDateString()];
        $accountId = (string) $this->bindings->resolve((string) $asset->id)->accountId;
        $scoped = fn (string $table): Builder => DB::table($table)->where('digital_asset_id', $asset->id)->where('account_id', $accountId);
        $evaluation = $this->rules->evaluate($input);
        $advisor = $evaluation['items'];
        $fatigued = [];
        foreach ($advisor as $item) {
            if ($item['rule_id'] === 'creative-fatigue' && isset($item['evidence']['ad_id'])) {
                $fatigued[(string) $item['evidence']['ad_id']] = true;
            }
        }

        // Campaign windows (spend, impressions, clicks, link clicks, 7-day frequency) + results from the collector.
        $blank = fn (): array => ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'link_clicks' => 0, 'results' => 0.0];
        $campaigns = [];
        $totals = ['cur' => $blank() + ['conv_spend' => 0.0], 'prev' => $blank() + ['conv_spend' => 0.0]];
        $freq = ['w' => 0.0, 'n' => 0];
        foreach ($scoped('meta_campaign_daily')->whereBetween('reporting_date', [$prev[0], $cur[1]])->get(['campaign_id', 'reporting_date', 'spend', 'impressions', 'clicks', 'frequency', 'metadata']) as $row) {
            $id = (string) $row->campaign_id;
            $date = substr((string) $row->reporting_date, 0, 10);
            $window = $date >= $cur[0] ? 'cur' : 'prev';
            $meta = is_string($row->metadata) ? (array) json_decode($row->metadata, true) : [];
            $campaigns[$id] ??= ['cur' => $blank(), 'prev' => $blank(), 'fw' => 0.0, 'fn' => 0];
            $campaigns[$id][$window]['spend'] += (float) $row->spend;
            $campaigns[$id][$window]['impressions'] += (int) $row->impressions;
            $campaigns[$id][$window]['clicks'] += (int) $row->clicks;
            $campaigns[$id][$window]['link_clicks'] += (int) ($meta['inline_link_clicks'] ?? 0);
            $frequency = is_numeric($row->frequency) ? (float) $row->frequency : null;
            if ($date >= $last7 && $frequency !== null && (int) $row->impressions > 0) {
                $campaigns[$id]['fw'] += $frequency * (int) $row->impressions;
                $campaigns[$id]['fn'] += (int) $row->impressions;
                $freq['w'] += $frequency * (int) $row->impressions;
                $freq['n'] += (int) $row->impressions;
            }
        }
        foreach ($input['campaign_daily'] ?? [] as $id => $dates) {
            foreach ($dates as $date => $day) {
                if ($date >= $prev[0] && isset($campaigns[(string) $id])) {
                    $campaigns[(string) $id][$date >= $cur[0] ? 'cur' : 'prev']['results'] += (float) ($day['conversions'] ?? 0);
                }
            }
        }

        // Ad sets per campaign (ABO budgets, learning).
        $learningCfg = (array) config('moxdop-advisor.meta_ads.learning', []);
        $adsets = [];
        $setsPerCampaign = [];
        foreach ($input['adsets'] ?? [] as $id => $set) {
            $campaignId = (string) ($set['campaign_id'] ?? '');
            $active = strtoupper((string) $set['effective_status']) === 'ACTIVE';
            $goal = (string) ($set['optimization_goal'] ?? '');
            $learning = $active && in_array($goal, (array) config('moxdop-advisor.meta_ads.conversion_goals', []), true)
                && $set['spend_7d'] >= (float) ($learningCfg['min_spend_7d'] ?? 150) && $set['results_7d'] < (float) ($learningCfg['low_weekly_results'] ?? 15);
            $cpr = $set['results'] > 0 ? $set['spend'] / $set['results'] : null;
            $adsets[(string) $id] = [
                'name' => (string) $set['name'], 'campaign_id' => $campaignId, 'status' => $active ? 'aktif' : mb_strtolower((string) ($set['effective_status'] ?? '')),
                'optimization_goal' => $goal ?: null, 'daily_budget' => $set['daily_budget'], 'lifetime_budget' => $set['lifetime_budget'],
                'spend' => round($set['spend'], 2), 'results' => round($set['results'], 1), 'cpr' => $cpr !== null ? round($cpr, 2) : null,
                'spend_7d' => round($set['spend_7d'], 2), 'results_7d' => round($set['results_7d'], 1), 'learning_limited' => $learning,
                'needed_daily' => $learning && $cpr !== null ? round($cpr * (int) ($learningCfg['weekly_results_target'] ?? 50) / 7, 2) : null,
            ];
            if ($active) {
                $setsPerCampaign[$campaignId] = ($setsPerCampaign[$campaignId] ?? 0) + 1;
            }
        }

        $conversionObjectives = (array) config('moxdop-advisor.meta_ads.conversion_objectives', []);
        $campaignRows = [];
        foreach ($campaigns as $id => $window) {
            $campaign = $input['campaigns'][$id] ?? ['name' => 'Kampanya '.$id, 'objective' => null, 'optimization_goal' => null, 'result_type' => null, 'conversion' => false,
                'effective_status' => null, 'daily_budget' => null, 'lifetime_budget' => null, 'frequency_7d' => null];
            $isConversion = (bool) ($campaign['conversion'] ?? false);
            foreach (['cur', 'prev'] as $w) {
                foreach (['spend', 'impressions', 'clicks', 'link_clicks'] as $k) {
                    $totals[$w][$k] += $window[$w][$k];
                }
                if ($isConversion) {
                    $totals[$w]['results'] += $window[$w]['results'];
                    $totals[$w]['conv_spend'] += $window[$w]['spend'];
                }
            }
            $cbo = ($campaign['daily_budget'] ?? null) !== null || ($campaign['lifetime_budget'] ?? null) !== null;
            $objective = (string) ($campaign['objective'] ?? '');
            $goal = (string) ($campaign['optimization_goal'] ?? '');
            $campaignRows[$id] = [
                'name' => (string) $campaign['name'], 'objective' => $objective ?: null, 'optimization_goal' => $goal ?: null,
                'result_type' => $campaign['result_type'] ?? null,
                'objective_fit' => match (true) {
                    $isConversion && str_contains((string) ($campaign['result_type'] ?? ''), 'purchase') => 'satış (lead değil)',
                    $isConversion && ($goal === '' || in_array($goal, self::LEAD_GOALS, true)) => 'lead/dönüşüm',
                    $isConversion => 'hedef lead, optimizasyon '.mb_strtolower($goal),
                    in_array($objective, $conversionObjectives, true) || in_array($objective, self::LEAD_OBJECTIVES, true) => 'etkileşim',
                    default => 'üst huni',
                },
                'status' => strtoupper((string) ($campaign['effective_status'] ?? 'ACTIVE')) === 'ACTIVE' ? 'aktif' : mb_strtolower((string) $campaign['effective_status']),
                'budget' => $cbo ? 'CBO' : 'ABO', 'daily_budget' => $campaign['daily_budget'] ?? null,
                'active_adsets' => $setsPerCampaign[$id] ?? 0,
                'cur' => $window['cur'], 'prev' => $window['prev'],
                'frequency_7d' => $window['fn'] > 0 ? round($window['fw'] / $window['fn'], 2) : null,
            ];
        }
        uasort($campaignRows, fn (array $a, array $b): int => $b['cur']['spend'] <=> $a['cur']['spend']);

        // Ads: 28-day totals, last 7 vs previous 7 days link CTR and frequency, creative text, video hold.
        $video = [];
        if (Schema::hasTable('meta_video_engagement_daily')) {
            foreach ($scoped('meta_video_engagement_daily')->whereBetween('reporting_date', $cur)->groupBy('ad_id', 'metric_type')
                ->get(['ad_id', 'metric_type', DB::raw('sum(metric_value) as v')]) as $row) {
                $video[(string) $row->ad_id][(string) $row->metric_type] = (float) $row->v;
            }
        }
        $ads = [];
        foreach ($input['ads'] ?? [] as $id => $ad) {
            $sum = fn (string $from, string $to): array => $this->window($ad['daily'], $from, $to);
            $now = $sum($cur[0], $cur[1]);
            if ($now['spend'] <= 0 && $now['impressions'] <= 0) {
                continue;
            }
            $w7 = $sum($last7, $cur[1]);
            $p7 = $sum($prev7[0], $prev7[1]);
            $creative = $input['creatives'][$ad['creative_id'] ?? ''] ?? [];
            $campaign = $campaignRows[(string) ($ad['campaign_id'] ?? '')] ?? null;
            $v = $video[(string) $id] ?? [];
            $plays = (float) ($v['video_play_actions'] ?? 0);
            $ads[(string) $id] = [
                'name' => (string) $ad['name'], 'campaign' => $campaign['name'] ?? null, 'objective' => $campaign['objective'] ?? null,
                'adset' => $adsets[(string) ($ad['adset_id'] ?? '')]['name'] ?? null, 'campaign_id' => $ad['campaign_id'], 'adset_id' => $ad['adset_id'],
                'status' => strtoupper((string) ($ad['effective_status'] ?? 'ACTIVE')) === 'ACTIVE' ? 'aktif' : mb_strtolower((string) $ad['effective_status']),
                'spend' => round($now['spend'], 2), 'impressions' => $now['impressions'], 'link_clicks' => $now['link_clicks'], 'results' => round($now['results'], 1),
                'cpr' => $now['results'] > 0 ? round($now['spend'] / $now['results'], 2) : null,
                'ctr' => $now['impressions'] > 0 ? round($now['link_clicks'] / $now['impressions'] * 100, 2) : null,
                'ctr_7d' => $w7['impressions'] > 0 ? round($w7['link_clicks'] / $w7['impressions'] * 100, 2) : null,
                'ctr_prev_7d' => $p7['impressions'] > 0 ? round($p7['link_clicks'] / $p7['impressions'] * 100, 2) : null,
                'frequency_7d' => $w7['frequency'] !== null ? round($w7['frequency'], 2) : null,
                'frequency_prev_7d' => $p7['frequency'] !== null ? round($p7['frequency'], 2) : null,
                'fatigue' => isset($fatigued[(string) $id]),
                'title' => filled($creative['title'] ?? null) ? mb_substr((string) $creative['title'], 0, 80) : null,
                'body' => filled($creative['body'] ?? null) ? mb_substr((string) $creative['body'], 0, 160) : null,
                'cta' => $creative['cta'] ?? null, 'link' => filled($creative['link_url'] ?? null) ? (string) $creative['link_url'] : null,
                'video_plays' => $plays > 0 ? (int) $plays : null,
                'thruplay_pct' => $plays > 0 && $now['impressions'] > 0 ? round((float) ($v['video_thruplay_watched_actions'] ?? 0) / $now['impressions'] * 100, 1) : null,
                'hold25_pct' => $plays > 0 ? round((float) ($v['video_p25_watched_actions'] ?? 0) / $plays * 100, 1) : null,
                'compliance' => $this->compliance(trim(($creative['title'] ?? '').' . '.($creative['body'] ?? ''), ' .'), $rules),
            ];
        }
        uasort($ads, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return [
            'asset_id' => (int) $asset->id, 'account_id' => $accountId, 'name' => (string) ($input['account']['name'] ?? $asset->name),
            'currency' => $input['currency'] ?? null,
            'period' => ['cur' => $cur, 'prev' => $prev, 'last7' => $last7],
            'totals' => $totals, 'frequency_7d' => $freq['n'] > 0 ? round($freq['w'] / $freq['n'], 2) : null,
            'actions_level' => $input['actions_level'] ?? 'none',
            'campaigns' => $campaignRows, 'adsets' => $adsets, 'ads' => array_slice($ads, 0, self::MAX_ADS, true),
            'regions' => $this->regions($asset, $accountId, $cur, $areas, $scoped),
            'placements' => $this->placements($input),
            'demographics' => $this->demographics($scoped, $cur),
            'sources' => $input['conversion_sources'] ?? ['available' => false, 'items' => []],
            'tracking' => $this->tracking($input, $advisor, $totals),
            'advisor' => $advisor,
            'purchase_value' => $this->purchaseValue($asset, $accountId, $cur),
            'end' => $cur[1],
        ];
    }

    /**
     * Region (il) results: meta_geo_results_daily (with leads) or the region breakdown (spend / clicks only).
     *
     * @param  array{0: string, 1: string}  $cur
     * @param  list<string>  $areas
     * @return list<array<string, mixed>>
     */
    private function regions(DigitalAsset $asset, string $accountId, array $cur, array $areas, callable $scoped): array
    {
        $rows = [];
        if (Schema::hasTable(MetaGeoResults::TABLE)) {
            foreach (DB::table(MetaGeoResults::TABLE)->where('digital_asset_id', $asset->id)->where('account_id', $accountId)->where('level', 'region')
                ->whereBetween('reporting_date', $cur)->groupBy('region')
                ->selectRaw('region, sum(spend) as spend, sum(clicks) as clicks, sum(impressions) as impressions, sum(leads) as leads, sum(messages) as messages, sum(purchases) as purchases')
                ->get() as $row) {
                $results = (float) $row->leads + (float) $row->messages + (float) $row->purchases;
                $rows[] = ['region' => (string) $row->region, 'spend' => round((float) $row->spend, 2), 'clicks' => (int) $row->clicks, 'impressions' => (int) $row->impressions,
                    'results' => round($results, 1), 'cpr' => $results > 0 ? round((float) $row->spend / $results, 2) : null, 'source' => 'geo'];
            }
        }
        if ($rows === [] && Schema::hasTable('meta_analysis_breakdown_daily')) {
            $sums = [];
            foreach ($scoped('meta_analysis_breakdown_daily')->where('breakdown_type', 'region')->whereBetween('reporting_date', $cur)->get(['breakdown_key', 'spend', 'clicks', 'impressions']) as $row) {
                $region = (string) (((array) json_decode((string) $row->breakdown_key, true))['region'] ?? '');
                if ($region === '') {
                    continue;
                }
                $sums[$region] ??= ['region' => $region, 'spend' => 0.0, 'clicks' => 0, 'impressions' => 0, 'results' => null, 'cpr' => null, 'source' => 'breakdown'];
                $sums[$region]['spend'] += (float) $row->spend;
                $sums[$region]['clicks'] += (int) $row->clicks;
                $sums[$region]['impressions'] += (int) $row->impressions;
            }
            $rows = array_map(fn (array $r): array => ['spend' => round($r['spend'], 2)] + $r, array_values($sums));
        }
        $total = array_sum(array_column($rows, 'spend'));
        foreach ($rows as $i => $row) {
            $folded = SeoText::fold(preg_replace('/\b(province|ili|il)\b/iu', '', $row['region']) ?? $row['region']);
            $rows[$i]['in_area'] = $areas === [] ? null : collect($areas)->contains(fn (string $a): bool => $a !== '' && (str_contains(' '.$folded.' ', ' '.$a.' ') || str_contains(' '.$a.' ', ' '.$folded.' ')));
            $rows[$i]['share'] = $total > 0 ? round($row['spend'] / $total * 100, 1) : null;
        }
        usort($rows, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return array_slice($rows, 0, self::MAX_REGIONS);
    }

    /** @return list<array<string, mixed>> */
    private function placements(array $input): array
    {
        $rows = $input['breakdowns']['placement'] ?? [];
        $total = array_sum(array_column($rows, 'spend'));
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['label' => $row['label'], 'spend' => round($row['spend'], 2), 'share' => $total > 0 ? round($row['spend'] / $total * 100, 1) : null,
                'ctr' => $row['impressions'] > 0 ? round($row['clicks'] / $row['impressions'] * 100, 2) : null,
                'cpc' => $row['clicks'] > 0 ? round($row['spend'] / $row['clicks'], 2) : null, 'impressions' => $row['impressions'], 'clicks' => $row['clicks']];
        }
        usort($out, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        return array_slice($out, 0, 10);
    }

    /**
     * @param  array{0: string, 1: string}  $cur
     * @return list<array<string, mixed>>
     */
    private function demographics(callable $scoped, array $cur): array
    {
        if (! Schema::hasTable('meta_analysis_breakdown_daily')) {
            return [];
        }
        $sums = [];
        foreach ($scoped('meta_analysis_breakdown_daily')->where('breakdown_type', 'demographic')->whereBetween('reporting_date', $cur)->get(['breakdown_key', 'spend', 'clicks', 'impressions']) as $row) {
            $key = (array) json_decode((string) $row->breakdown_key, true);
            foreach (['age' => 'yaş', 'gender' => 'cinsiyet'] as $dimension => $label) {
                $value = (string) ($key[$dimension] ?? '');
                if ($value === '') {
                    continue;
                }
                $id = $dimension.'-'.$value;
                $sums[$id] ??= ['label' => $label.' '.$value, 'dimension' => $dimension, 'value' => $value, 'spend' => 0.0, 'clicks' => 0, 'impressions' => 0];
                $sums[$id]['spend'] += (float) $row->spend;
                $sums[$id]['clicks'] += (int) $row->clicks;
                $sums[$id]['impressions'] += (int) $row->impressions;
            }
        }
        $out = [];
        foreach (['age', 'gender'] as $dimension) {
            $group = array_filter($sums, fn (array $r): bool => $r['dimension'] === $dimension);
            $total = array_sum(array_column($group, 'spend'));
            foreach ($group as $id => $row) {
                $out[$id] = ['label' => $row['label'], 'spend' => round($row['spend'], 2), 'share' => $total > 0 ? round($row['spend'] / $total * 100, 1) : null,
                    'ctr' => $row['impressions'] > 0 ? round($row['clicks'] / $row['impressions'] * 100, 2) : null];
            }
        }

        return $out;
    }

    /**
     * Measurement problems: the advisor's pixel-health issues, conversion spend without any result data and
     * conversion spend without a pixel on the account.
     *
     * @param  list<array<string, mixed>>  $advisor
     * @return list<array{issue: string, fix: string, subject: string}>
     */
    private function tracking(array $input, array $advisor, array $totals): array
    {
        $issues = [];
        foreach ($advisor as $item) {
            if ($item['rule_id'] === 'pixel-health') {
                foreach ((array) ($item['evidence']['issues'] ?? []) as $issue) {
                    $issues[] = ['subject' => (string) ($issue['action'] ?? 'Piksel'), 'issue' => (string) ($issue['issue'] ?? ''), 'fix' => (string) ($issue['fix'] ?? '')];
                }
            }
        }
        if ($totals['cur']['conv_spend'] > 0 && ($input['actions_level'] ?? 'none') === 'none') {
            $issues[] = ['subject' => 'Hesap', 'issue' => 'Dönüşüm kampanyaları harcıyor ama hiç sonuç (eylem) verisi gelmiyor.',
                'fix' => 'Events Manager → Veri kaynakları: piksel + Conversions API olaylarının (Lead / Contact / Schedule) geldiğini Test olayları ile doğrula.'];
        }
        $sources = $input['conversion_sources'] ?? ['available' => false, 'items' => []];
        $pixels = array_filter($sources['items'], fn (array $s): bool => $s['type'] === 'PIXEL');
        if ($sources['available'] && $pixels === [] && $totals['cur']['conv_spend'] > 0) {
            $issues[] = ['subject' => 'Hesap', 'issue' => 'Hesapta piksel / veri kümesi görünmüyor.', 'fix' => 'Events Manager\'da veri kümesini (piksel) hesaba paylaş ve siteye kur; CAPI ile sunucu olayı da gönder.'];
        }

        return $issues;
    }

    /** @return list<string> rule labels the text breaks (sector compliance, source meta_ad) */
    private function compliance(string $text, Collection $rules): array
    {
        if ($text === '' || $rules->isEmpty()) {
            return [];
        }

        return array_values(array_unique(array_map(static fn (array $hit): string => (string) $hit['rule']->label.' ('.$hit['matched'].')',
            app(ComplianceChecker::class)->checkText($text, $rules, 'meta_ad'))));
    }

    /** @param  array{0: string, 1: string}  $cur */
    private function purchaseValue(DigitalAsset $asset, string $accountId, array $cur): float
    {
        if (! Schema::hasTable(MetaGeoResults::TABLE)) {
            return 0.0;
        }

        return round((float) DB::table(MetaGeoResults::TABLE)->where('digital_asset_id', $asset->id)->where('account_id', $accountId)
            ->where('level', 'country')->whereBetween('reporting_date', $cur)->sum('purchase_value'), 2);
    }

    /**
     * Lead outcomes (ADR-074) in the current window: Meta form leads (by campaign / form label) and all sources.
     *
     * @param  array<string, mixed>|null  $period
     * @param  array<int, array<string, mixed>>  $accounts
     * @param  list<string>  $currencies
     * @return array<string, mixed>
     */
    private function leads(Brand $brand, ?array $period, array $accounts, array $currencies): array
    {
        $end = $period !== null ? CarbonImmutable::parse($period['cur'][1]) : CarbonImmutable::now(config('app.timezone'))->subDay();
        $from = $end->subDays(self::WINDOW_DAYS - 1)->startOfDay();
        $rows = LeadOutcome::query()->where('brand_id', $brand->id)->whereBetween('lead_received_at', [$from, $end->endOfDay()])
            ->get(['lead_source', 'status', 'campaign_label']);
        $summary = function ($rows): array {
            $total = $rows->count();
            $unmarked = $rows->where('status', LeadOutcome::STATUS_NEW)->count();
            $qualified = $rows->whereIn('status', LeadOutcome::QUALIFIED)->count();
            $marked = $total - $unmarked;

            return ['total' => $total, 'marked' => $marked, 'unmarked' => $unmarked, 'qualified' => $qualified, 'junk' => $rows->where('status', 'junk')->count(),
                'qualified_rate' => $marked > 0 ? round($qualified / $marked * 100, 1) : null];
        };
        $meta = $rows->where('lead_source', 'meta_lead_form');
        $spend = count($currencies) <= 1 ? array_sum(array_map(fn (array $a): float => $a['totals']['cur']['spend'], $accounts)) : null;
        $metaSummary = $summary($meta);
        $metaSummary['cost_per_qualified'] = $spend !== null && $metaSummary['qualified'] > 0 ? round($spend / $metaSummary['qualified'], 2) : null;
        $metaSummary['cost_per_lead'] = $spend !== null && $metaSummary['total'] > 0 ? round($spend / $metaSummary['total'], 2) : null;
        $byLabel = [];
        foreach ($meta->groupBy(fn ($r): string => (string) ($r->campaign_label ?: 'Etiketsiz')) as $label => $group) {
            $byLabel[(string) $label] = $summary($group);
        }
        uasort($byLabel, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return ['from' => $from->toDateString(), 'to' => $end->toDateString(), 'meta' => $metaSummary, 'all' => $summary($rows), 'by_label' => array_slice($byLabel, 0, 10, true)];
    }

    /** @return array{spend: float, impressions: int, link_clicks: int, results: float, frequency: ?float} */
    private function window(array $daily, string $from, string $to): array
    {
        $sum = ['spend' => 0.0, 'impressions' => 0, 'link_clicks' => 0, 'results' => 0.0, 'frequency' => null];
        $weighted = 0.0;
        $weight = 0;
        foreach ($daily as $date => $day) {
            if ($date < $from || $date > $to) {
                continue;
            }
            $sum['spend'] += $day['spend'];
            $sum['impressions'] += $day['impressions'];
            $sum['link_clicks'] += $day['link_clicks'];
            $sum['results'] += $day['results'];
            if ($day['frequency'] !== null && $day['impressions'] > 0) {
                $weighted += $day['frequency'] * $day['impressions'];
                $weight += $day['impressions'];
            }
        }
        $sum['frequency'] = $weight > 0 ? $weighted / $weight : null;

        return $sum;
    }

    public static function slug(string $text): string
    {
        return Str::limit(Str::slug(SeoText::fold($text)) ?: substr(hash('sha256', $text), 0, 8), 40, '');
    }

    public static function money(float|int|null $amount, ?string $currency): string
    {
        if ($amount === null) {
            return '—';
        }
        $symbol = match ($currency) {
            'TRY' => '₺', 'USD' => '$', 'EUR' => '€', default => '',
        };
        $formatted = number_format((float) $amount, abs((float) $amount) < 100 && floor((float) $amount) != (float) $amount ? 2 : 0, ',', '.');

        return $symbol !== '' ? $symbol.$formatted : $formatted.($currency ? ' '.$currency : '');
    }

    public static function deltaPct(float|int $current, float|int $previous): ?int
    {
        return $previous > 0 ? (int) round(($current - $previous) / $previous * 100) : null;
    }
}
