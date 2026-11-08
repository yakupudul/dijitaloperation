<?php

namespace App\Services\Marketing;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Services\Brand\BrandRowScope;
use App\Services\Brand\BrandScope;
use App\Support\Demo\DemoMenu;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * What Moximu put into each of its own channels in a month (Pazarlama, rebuilt 2026-11-08): Meta and Google Ads spend,
 * the site's Google clicks, the Business Profile's calls and directions, the social posts. Read from the stored daily
 * tables of the agency's brand (asset-bound and central rows alike, the same scope as the MCP channel-data tool), for
 * the month against the same days of the month before, so a month in progress is never compared with a whole month.
 * What the channel brought (applications, customers, ₺) comes from the sales records in MarketingFunnel.
 */
final class OwnChannels
{
    public const string TZ = 'Europe/Istanbul';

    /** Business Profile metrics: views are the four impression metrics summed. */
    private const array GBP_VIEWS = ['BUSINESS_IMPRESSIONS_MOBILE_SEARCH', 'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH', 'BUSINESS_IMPRESSIONS_MOBILE_MAPS', 'BUSINESS_IMPRESSIONS_DESKTOP_MAPS'];

    /** @return array{id: int, name: string, website: ?int}|null the agency's brand (Moximu) */
    public function brand(): ?array
    {
        return DemoMenu::agencyBrand();
    }

    /**
     * @return array{connected: bool, last_day: ?string, spend: float, spend_prev: float, messages: int, leads: int, results_prev: int, clicks: int, impressions: int,
     *     campaigns: list<array{name: string, spend: float, messages: int, leads: int, clicks: int, last_day: string}>}
     */
    public function meta(CarbonImmutable $month): array
    {
        $out = ['connected' => false, 'last_day' => null, 'spend' => 0.0, 'spend_prev' => 0.0, 'messages' => 0, 'leads' => 0, 'results_prev' => 0, 'clicks' => 0, 'impressions' => 0, 'campaigns' => []];
        $brand = $this->brand();
        if ($brand === null || ! SchemaState::hasTable('meta_geo_results_daily')) {
            return $out;
        }
        $assets = BrandScope::assetIds($brand['id']) ?: [0];
        $base = fn (): Builder => DB::table('meta_geo_results_daily')->whereIn('digital_asset_id', $assets)->where('level', 'country');
        $last = $base()->max('reporting_date');
        if ($last === null) {
            return $out;
        }
        [$from, $to, $pFrom, $pTo] = $this->bounds($month, (string) $last);
        $sums = 'coalesce(sum(spend), 0) as spend, coalesce(sum(messages), 0) as messages, coalesce(sum(leads), 0) as leads, coalesce(sum(clicks), 0) as clicks, coalesce(sum(impressions), 0) as impressions';
        $now = $base()->whereBetween('reporting_date', [$from, $to])->selectRaw($sums)->first();
        $prev = $base()->whereBetween('reporting_date', [$pFrom, $pTo])->selectRaw($sums)->first();
        $campaigns = $base()->whereBetween('reporting_date', [$from, $to])->groupBy('campaign_id', 'campaign_name')
            ->selectRaw('campaign_name, max(reporting_date) as last_day, '.$sums)->orderByRaw('sum(spend) desc')->limit(8)->get()
            ->filter(fn (object $r): bool => (float) $r->spend > 0 || (float) $r->messages + (float) $r->leads > 0)
            ->map(fn (object $r): array => ['name' => (string) $r->campaign_name, 'spend' => round((float) $r->spend, 2), 'messages' => (int) round((float) $r->messages),
                'leads' => (int) round((float) $r->leads), 'clicks' => (int) $r->clicks, 'last_day' => (string) $r->last_day])->values()->all();

        return ['connected' => true, 'last_day' => (string) $last, 'spend' => round((float) $now->spend, 2), 'spend_prev' => round((float) $prev->spend, 2),
            'messages' => (int) round((float) $now->messages), 'leads' => (int) round((float) $now->leads), 'results_prev' => (int) round((float) $prev->messages + (float) $prev->leads),
            'clicks' => (int) $now->clicks, 'impressions' => (int) $now->impressions, 'campaigns' => $campaigns];
    }

    /** @return array{connected: bool, last_day: ?string, cost: float, cost_prev: float, clicks: int, conversions: int} */
    public function googleAds(CarbonImmutable $month): array
    {
        $out = ['connected' => false, 'last_day' => null, 'cost' => 0.0, 'cost_prev' => 0.0, 'clicks' => 0, 'conversions' => 0];
        $brand = $this->brand();
        $table = 'google_ads_campaign_daily';
        if ($brand === null || ! SchemaState::hasTable($table)) {
            return $out;
        }
        $base = fn (): Builder => BrandRowScope::table($brand['id'], 'google_ads', $table, 'reporting_date', $month->subMonths(2)->toDateString());
        $last = $base()->max('reporting_date');
        if ($last === null) {
            return $out;
        }
        [$from, $to, $pFrom, $pTo] = $this->bounds($month, (string) $last);
        $cost = SchemaState::hasColumn($table, 'cost_amount') ? 'coalesce(sum(coalesce(cost_amount, cost_micros / 1000000.0)), 0)' : 'coalesce(sum(cost_micros) / 1000000.0, 0)';
        $now = $base()->whereBetween('reporting_date', [$from, $to])->selectRaw($cost.' as cost, coalesce(sum(clicks), 0) as clicks, coalesce(sum(conversions), 0) as conversions')->first();
        $prev = $base()->whereBetween('reporting_date', [$pFrom, $pTo])->selectRaw($cost.' as cost')->first();

        return ['connected' => true, 'last_day' => (string) $last, 'cost' => round((float) $now->cost, 2), 'cost_prev' => round((float) $prev->cost, 2),
            'clicks' => (int) $now->clicks, 'conversions' => (int) round((float) $now->conversions)];
    }

    /**
     * @return array{connected: bool, last_day: ?string, clicks: int, impressions: int, clicks_prev: int, impressions_prev: int,
     *     queries: list<array{query: string, clicks: int, impressions: int}>}
     */
    public function site(CarbonImmutable $month): array
    {
        $out = ['connected' => false, 'last_day' => null, 'clicks' => 0, 'impressions' => 0, 'clicks_prev' => 0, 'impressions_prev' => 0, 'queries' => []];
        $brand = $this->brand();
        if ($brand === null || ! SchemaState::hasTable('gsc_property_daily')) {
            return $out;
        }
        $since = $month->subMonths(2)->toDateString();
        $web = fn (Builder $q, string $table): Builder => SchemaState::hasColumn($table, 'search_type') ? $q->where(fn ($w) => $w->whereNull('search_type')->orWhere('search_type', 'web')) : $q;
        $base = fn (): Builder => $web(BrandRowScope::table($brand['id'], 'search_console', 'gsc_property_daily', 'reporting_date', $since), 'gsc_property_daily');
        $last = $base()->max('reporting_date');
        if ($last === null) {
            return $out;
        }
        [$from, $to, $pFrom, $pTo] = $this->bounds($month, (string) $last);
        $sum = fn (string $f, string $t): object => $base()->whereBetween('reporting_date', [$f, $t])->selectRaw('coalesce(sum(clicks), 0) as clicks, coalesce(sum(impressions), 0) as impressions')->first();
        $now = $sum($from, $to);
        $prev = $sum($pFrom, $pTo);
        $queries = SchemaState::hasTable('gsc_query_page_daily')
            ? $web(BrandRowScope::table($brand['id'], 'search_console', 'gsc_query_page_daily', 'reporting_date', $since), 'gsc_query_page_daily')
                ->whereBetween('reporting_date', [$from, $to])->groupBy('query')->selectRaw('query, sum(clicks) as clicks, sum(impressions) as impressions')
                ->orderByRaw('sum(clicks) desc, sum(impressions) desc')->limit(8)->get()
                ->map(fn (object $r): array => ['query' => (string) $r->query, 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions])->all()
            : [];

        return ['connected' => true, 'last_day' => (string) $last, 'clicks' => (int) $now->clicks, 'impressions' => (int) $now->impressions,
            'clicks_prev' => (int) $prev->clicks, 'impressions_prev' => (int) $prev->impressions, 'queries' => $queries];
    }

    /**
     * @return array{connected: bool, last_day: ?string, views: int, calls: int, directions: int, website: int, views_prev: int, actions_prev: int,
     *     keywords: list<array{keyword: string, impressions: int}>, keywords_month: ?string}
     */
    public function gbp(CarbonImmutable $month): array
    {
        $out = ['connected' => false, 'last_day' => null, 'views' => 0, 'calls' => 0, 'directions' => 0, 'website' => 0, 'views_prev' => 0, 'actions_prev' => 0, 'keywords' => [], 'keywords_month' => null];
        $brand = $this->brand();
        if ($brand === null || ! SchemaState::hasTable('gbp_performance_daily')) {
            return $out;
        }
        $base = fn (): Builder => BrandRowScope::table($brand['id'], 'google_business_profile', 'gbp_performance_daily', 'reporting_date', $month->subMonths(2)->toDateString());
        $last = $base()->max('reporting_date');
        if ($last === null) {
            return $out;
        }
        [$from, $to, $pFrom, $pTo] = $this->bounds($month, (string) $last);
        $metrics = fn (string $f, string $t): array => $base()->whereBetween('reporting_date', [$f, $t])->groupBy('metric')->selectRaw('metric, sum(value) as value')
            ->pluck('value', 'metric')->map(fn ($v): int => (int) $v)->all();
        $now = $metrics($from, $to);
        $prev = $metrics($pFrom, $pTo);
        $views = fn (array $m): int => array_sum(array_intersect_key($m, array_flip(self::GBP_VIEWS)));
        $actions = fn (array $m): int => ($m['CALL_CLICKS'] ?? 0) + ($m['BUSINESS_DIRECTION_REQUESTS'] ?? 0) + ($m['WEBSITE_CLICKS'] ?? 0);
        $keywords = [];
        $keywordsMonth = null;
        if (SchemaState::hasTable('gbp_search_keywords_monthly')) {
            $kw = fn (): Builder => BrandRowScope::table($brand['id'], 'google_business_profile', 'gbp_search_keywords_monthly');
            $keywordsMonth = $kw()->where('month_start', '<=', $to)->max('month_start');
            if ($keywordsMonth !== null) {
                $keywords = $kw()->where('month_start', $keywordsMonth)->groupBy('search_keyword')->selectRaw('search_keyword, sum(coalesce(impressions, threshold, 0)) as impressions')
                    ->orderByRaw('sum(coalesce(impressions, threshold, 0)) desc')->limit(8)->get()
                    ->map(fn (object $r): array => ['keyword' => (string) $r->search_keyword, 'impressions' => (int) $r->impressions])->all();
            }
        }

        return ['connected' => true, 'last_day' => (string) $last, 'views' => $views($now), 'calls' => $now['CALL_CLICKS'] ?? 0, 'directions' => $now['BUSINESS_DIRECTION_REQUESTS'] ?? 0,
            'website' => $now['WEBSITE_CLICKS'] ?? 0, 'views_prev' => $views($prev), 'actions_prev' => $actions($prev),
            'keywords' => $keywords, 'keywords_month' => $keywordsMonth !== null ? substr((string) $keywordsMonth, 0, 7) : null];
    }

    /**
     * @return array{accounts: int, published: int, published_prev: int, drafts: int, drafts_week: int, by_pillar: array<string, int>,
     *     next: list<array{title: string, kind: string, at: CarbonImmutable, status: string}>}
     */
    public function social(CarbonImmutable $month): array
    {
        $from = $month->setTimezone(self::TZ)->startOfMonth();
        $to = $from->endOfMonth();
        $published = fn (CarbonImmutable $f, CarbonImmutable $t) => SocialPost::query()->where('status', SocialPost::PUBLISHED)->whereBetween('publish_at', [$f->utc(), $t->utc()]);
        $now = CarbonImmutable::now(self::TZ);

        return [
            'accounts' => SocialAccount::query()->where('enabled', true)->count(),
            'published' => $published($from, $to)->count(),
            'published_prev' => $published($from->subMonth(), $from->subMonth()->endOfMonth())->count(),
            'drafts' => SocialPost::query()->where('status', SocialPost::DRAFT)->where('publish_at', '>=', $now->utc())->count(),
            'drafts_week' => SocialPost::query()->where('status', SocialPost::DRAFT)->whereBetween('publish_at', [$now->utc(), $now->addDays(7)->utc()])->count(),
            'by_pillar' => $published($from, $to)->get(['pillar'])->countBy(fn (SocialPost $p): string => (string) $p->pillar)->sortDesc()->all(),
            'next' => SocialPost::query()->whereIn('status', [SocialPost::DRAFT, SocialPost::APPROVED])->where('publish_at', '>=', $now->utc())->orderBy('publish_at')->limit(4)
                ->get(['title', 'kind', 'publish_at', 'status'])
                ->map(fn (SocialPost $p): array => ['title' => (string) $p->title, 'kind' => (string) $p->kind, 'at' => CarbonImmutable::instance($p->publish_at)->setTimezone(self::TZ), 'status' => (string) $p->status])->all(),
        ];
    }

    /**
     * The month's days with data and the same days of the month before: a month in progress (or one whose data stops
     * on the 4th) is compared with the 1st..4th of the previous month, never with a whole month.
     *
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function bounds(CarbonImmutable $month, string $lastDay): array
    {
        $from = $month->setTimezone(self::TZ)->startOfMonth();
        $to = $from->endOfMonth();
        $last = CarbonImmutable::parse($lastDay, self::TZ);
        $end = $last->lt($to) && $last->gte($from) ? $last : $to;
        $days = (int) $from->diffInDays($end) + 1;
        $pFrom = $from->subMonth();
        $pTo = $pFrom->addDays($days - 1)->min($pFrom->endOfMonth());

        return [$from->toDateString(), $to->toDateString(), $pFrom->toDateString(), $pTo->toDateString()];
    }
}
