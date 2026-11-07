<?php

namespace App\Services\Marketing;

use App\Models\DigitalAsset;
use App\Models\SalesLead;
use App\Models\SocialPost;
use App\Support\Demo\DemoMenu;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The agency's own channels on the Pazarlama screen (Aşama 4): the site (Search Console), our ads (Meta, Google Ads)
 * and social media, read from the stored daily tables of the agency's brand for one month against the month before.
 * Only numbers that change a decision: the social tab measures applications and "Instagram'da gördüm" records, not
 * followers (the decision doc, 2026-11-07).
 */
final class OwnChannels
{
    public const string TZ = 'Europe/Istanbul';

    /** @return array{id: int, name: string, website: ?int}|null the agency's brand (Moximu) */
    public function brand(): ?array
    {
        return DemoMenu::agencyBrand();
    }

    /**
     * @return array{connected: bool, clicks: ?int, impressions: ?int, clicks_prev: ?int, impressions_prev: ?int, last_day: ?string,
     *     queries: list<array{query: string, clicks: int, impressions: int}>, applications: int, applications_prev: int, brand: ?string}
     */
    public function site(CarbonImmutable $month): array
    {
        [$from, $to, $pFrom, $pTo] = $this->bounds($month);
        $brand = $this->brand();
        $assets = $brand !== null ? $this->assetIds($brand['id']) : [];
        $out = ['connected' => false, 'clicks' => null, 'impressions' => null, 'clicks_prev' => null, 'impressions_prev' => null, 'last_day' => null, 'queries' => [],
            'applications' => $this->applications('web', $from, $to), 'applications_prev' => $this->applications('web', $pFrom, $pTo), 'brand' => $brand['name'] ?? null];
        if ($assets === [] || ! SchemaState::hasTable('gsc_property_daily')) {
            return $out;
        }
        $base = fn () => DB::table('gsc_property_daily')->whereIn('digital_asset_id', $assets);
        $last = $base()->max('reporting_date');
        if ($last === null) {
            return $out;
        }
        $sum = fn (string $f, string $t): object => $base()->whereBetween('reporting_date', [$f, $t])->selectRaw('coalesce(sum(clicks), 0) as clicks, coalesce(sum(impressions), 0) as impressions')->first();
        $this_ = $sum($from->toDateString(), $to->toDateString());
        $prev = $sum($pFrom->toDateString(), $pTo->toDateString());
        $queries = SchemaState::hasTable('gsc_query_page_daily')
            ? DB::table('gsc_query_page_daily')->whereIn('digital_asset_id', $assets)->whereBetween('reporting_date', [$from->toDateString(), $to->toDateString()])
                ->groupBy('query')->selectRaw('query, sum(clicks) as clicks, sum(impressions) as impressions')->orderByRaw('sum(clicks) desc, sum(impressions) desc')->limit(8)->get()
                ->map(fn (object $r): array => ['query' => (string) $r->query, 'clicks' => (int) $r->clicks, 'impressions' => (int) $r->impressions])->all()
            : [];

        return array_merge($out, ['connected' => true, 'clicks' => (int) $this_->clicks, 'impressions' => (int) $this_->impressions, 'clicks_prev' => (int) $prev->clicks,
            'impressions_prev' => (int) $prev->impressions, 'last_day' => (string) $last, 'queries' => $queries]);
    }

    /**
     * @return array{meta: array{connected: bool, spend: ?float, messages: ?int, leads: ?int, spend_prev: ?float, messages_prev: ?int, leads_prev: ?int, last_day: ?string, cost: ?float},
     *     google: array{connected: bool, cost: ?float, clicks: ?int, conversions: ?int, cost_prev: ?float, last_day: ?string},
     *     applications: array{meta: int, meta_prev: int, google_ads: int, google_ads_prev: int}}
     */
    public function ads(CarbonImmutable $month): array
    {
        [$from, $to, $pFrom, $pTo] = $this->bounds($month);
        $brand = $this->brand();
        $assets = $brand !== null ? $this->assetIds($brand['id']) : [];
        $meta = ['connected' => false, 'spend' => null, 'messages' => null, 'leads' => null, 'spend_prev' => null, 'messages_prev' => null, 'leads_prev' => null, 'last_day' => null, 'cost' => null];
        $google = ['connected' => false, 'cost' => null, 'clicks' => null, 'conversions' => null, 'cost_prev' => null, 'last_day' => null];
        if ($assets !== [] && SchemaState::hasTable('meta_geo_results_daily')) {
            $base = fn () => DB::table('meta_geo_results_daily')->whereIn('digital_asset_id', $assets)->where('level', 'country');
            $last = $base()->max('reporting_date');
            if ($last !== null) {
                $sum = fn (string $f, string $t): object => $base()->whereBetween('reporting_date', [$f, $t])
                    ->selectRaw('coalesce(sum(spend), 0) as spend, coalesce(sum(messages), 0) as messages, coalesce(sum(leads), 0) as leads')->first();
                $this_ = $sum($from->toDateString(), $to->toDateString());
                $prev = $sum($pFrom->toDateString(), $pTo->toDateString());
                $results = (int) round((float) $this_->messages + (float) $this_->leads);
                $meta = ['connected' => true, 'spend' => round((float) $this_->spend, 2), 'messages' => (int) round((float) $this_->messages), 'leads' => (int) round((float) $this_->leads),
                    'spend_prev' => round((float) $prev->spend, 2), 'messages_prev' => (int) round((float) $prev->messages), 'leads_prev' => (int) round((float) $prev->leads),
                    'last_day' => (string) $last, 'cost' => $results > 0 ? round((float) $this_->spend / $results, 2) : null];
            }
        }
        if ($assets !== [] && SchemaState::hasTable('google_ads_campaign_daily')) {
            $base = fn () => DB::table('google_ads_campaign_daily')->whereIn('digital_asset_id', $assets);
            $last = $base()->max('reporting_date');
            if ($last !== null) {
                $sum = fn (string $f, string $t): object => $base()->whereBetween('reporting_date', [$f, $t])
                    ->selectRaw('coalesce(sum(cost_amount), 0) as cost, coalesce(sum(clicks), 0) as clicks, coalesce(sum(conversions), 0) as conversions')->first();
                $this_ = $sum($from->toDateString(), $to->toDateString());
                $prev = $sum($pFrom->toDateString(), $pTo->toDateString());
                $google = ['connected' => true, 'cost' => round((float) $this_->cost, 2), 'clicks' => (int) $this_->clicks, 'conversions' => (int) $this_->conversions, 'cost_prev' => round((float) $prev->cost, 2), 'last_day' => (string) $last];
            }
        }

        return ['meta' => $meta, 'google' => $google, 'applications' => [
            'meta' => $this->applications('meta', $from, $to), 'meta_prev' => $this->applications('meta', $pFrom, $pTo),
            'google_ads' => $this->applications('google_ads', $from, $to), 'google_ads_prev' => $this->applications('google_ads', $pFrom, $pTo),
        ]];
    }

    /**
     * @return array{published: int, published_prev: int, by_pillar: array<string, int>, drafts: int, applications: int, applications_prev: int, seen: int, seen_prev: int}
     */
    public function social(CarbonImmutable $month): array
    {
        [$from, $to, $pFrom, $pTo] = $this->bounds($month);
        $published = fn (CarbonImmutable $f, CarbonImmutable $t) => SocialPost::query()->where('status', SocialPost::PUBLISHED)->whereBetween('publish_at', [$f->utc(), $t->utc()]);
        $seen = fn (CarbonImmutable $f, CarbonImmutable $t): int => SalesLead::query()->relevant()->where('heard_from', 'instagram')->whereBetween('created_at', [$f->utc(), $t->utc()])->count();

        return [
            'published' => $published($from, $to)->count(), 'published_prev' => $published($pFrom, $pTo)->count(),
            'by_pillar' => $published($from, $to)->get(['pillar'])->countBy(fn (SocialPost $p): string => (string) $p->pillar)->sortDesc()->all(),
            'drafts' => SocialPost::query()->where('status', SocialPost::DRAFT)->where('publish_at', '>=', now())->count(),
            'applications' => $this->applications('social', $from, $to), 'applications_prev' => $this->applications('social', $pFrom, $pTo),
            'seen' => $seen($from, $to), 'seen_prev' => $seen($pFrom, $pTo),
        ];
    }

    /** Records opened in the span whose channel is $channel (every Tür; the funnel tab filters by Tür). */
    private function applications(string $channel, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return SalesLead::query()->relevant()->whereBetween('created_at', [$from->utc(), $to->utc()])
            ->where(fn ($q) => $q->whereNull('won_at')->orWhereColumn('won_at', '>=', 'created_at'))
            ->get(['id', 'source', 'heard_from'])->filter(fn (SalesLead $l): bool => MarketingFunnel::channel($l) === $channel)->count();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: CarbonImmutable, 3: CarbonImmutable} this month and the one before */
    private function bounds(CarbonImmutable $month): array
    {
        $from = $month->setTimezone(self::TZ)->startOfMonth();

        return [$from, $from->endOfMonth(), $from->subMonth(), $from->subMonth()->endOfMonth()];
    }

    /** @return list<int> */
    private function assetIds(int $brandId): array
    {
        return DigitalAsset::query()->where('brand_id', $brandId)->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }
}
