<?php

namespace App\Services\Ads;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\OfferingPage;
use App\Services\Gbp\GbpDailyWorkspace;
use App\Services\Gbp\GbpStandardInput;
use App\Services\Meta\MetaCampaignServices;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\Analysis\SiteAnalysisReader;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hizmet karnesi (brand page): every brand service across four channels over the last 30 days. Web sitesi: the
 * service's own pages and their search clicks / key events. Google Ads (keyword → hizmet) and Meta (campaign → hizmet):
 * cost per result against the other brands' median for the same service and result type. İşletme Profili: whether the
 * service is listed on the brand's profiles. Yarıştaki yeri: the brand's rank by cost among the brands advertising the
 * service. A few rule-built notes on top. No AI; nothing is written anywhere.
 */
class BrandServiceScorecard
{
    public function __construct(
        private readonly MetaCampaignServices $services,
        private readonly SiteAnalysisReader $site,
        private readonly GbpDailyWorkspace $gbp,
        private readonly GbpStandardInput $gbpInput,
    ) {}

    /** @return array{rows: list<array<string, mixed>>, notes: list<array<string, string>>, ready: bool} */
    public function scorecard(Brand $brand): array
    {
        $offerings = $this->services->offerings($brand);
        if ($offerings === []) {
            return ['rows' => [], 'notes' => [], 'ready' => false];
        }
        $city = AdServiceStats::city($brand);
        $serviceIds = array_values(array_unique(array_filter(array_column($offerings, 'service_id'))));
        $hasStats = Schema::hasTable('ad_service_stats');
        $costs = ['meta' => $hasStats ? AdServiceStats::brandCosts('meta', $serviceIds) : [], 'google_ads' => $hasStats ? AdServiceStats::brandCosts('google_ads', $serviceIds) : []];
        $own = $hasStats ? DB::table('ad_service_stats')->where('brand_id', $brand->id)->groupBy('channel', 'brand_offering_id', 'result_type')
            ->selectRaw('channel, brand_offering_id, result_type, sum(spend) as spend, sum(results) as results')->get()
            ->groupBy(fn ($r): string => $r->channel.'|'.$r->brand_offering_id) : collect();
        $currency = $hasStats ? (string) DB::table('ad_campaign_stats')->where('brand_id', $brand->id)->value('currency') : '';
        $web = $this->website($brand);
        $gbp = $this->profileServices($brand);

        $rows = [];
        foreach ($offerings as $offering) {
            $meta = $this->channel($own->get('meta|'.$offering['id']), $costs['meta'], $offering['service_id'], (int) $brand->id, $city);
            $google = $this->channel($own->get('google_ads|'.$offering['id']), $costs['google_ads'], $offering['service_id'], (int) $brand->id, $city);
            $listed = $gbp === null ? null : $this->listed($offering['names'], $gbp);
            $rows[] = [
                'id' => $offering['id'], 'name' => $offering['name'], 'main' => $offering['main'],
                'web' => $web[$offering['id']] ?? ['state' => 'none', 'pages' => 0, 'clicks' => 0, 'key_events' => 0.0],
                'google_ads' => $google,
                'meta' => $meta,
                'gbp' => ['state' => $gbp === null ? 'na' : ($listed ? 'around' : 'none')],
                'rank' => $this->rank($meta ?? $google, $meta !== null ? $costs['meta'] : $costs['google_ads'], $offering['service_id'], (int) $brand->id),
            ];
        }

        return ['rows' => $rows, 'notes' => $this->notes($rows), 'ready' => true, 'currency' => $currency];
    }

    /**
     * One channel of one service: the main result type's cost against the others' median.
     *
     * @param  Collection<int, object>|null  $rows
     * @return array{state: string, type: string, spend: float, results: float, cost: ?float, average: ?array<string, mixed>, diff: ?float}|null null: no ads for the service
     */
    private function channel($rows, array $costs, ?int $serviceId, int $brandId, string $city): ?array
    {
        if ($rows === null || $rows->isEmpty()) {
            return null;
        }
        $main = $rows->sortByDesc(fn ($r): float => (float) $r->results)->first();
        $type = (string) $main->result_type;
        $spend = (float) $main->spend;
        $results = (float) $main->results;
        $cost = $results > 0 ? round($spend / $results, 2) : null;
        $average = $serviceId !== null ? AdServiceStats::average($costs, $serviceId, $type, $brandId, $city) : null;
        $verdict = $cost === null && $spend > 0 ? 'worse' : AdServiceStats::verdict($cost, $average['median'] ?? null);

        return ['state' => $verdict ?? 'around', 'type' => $type, 'spend' => round($spend, 2), 'results' => $results, 'cost' => $cost, 'average' => $average,
            'diff' => $average !== null && $cost !== null ? round(($cost - $average['median']) / $average['median'] * 100, 1) : null, 'compared' => $average !== null];
    }

    /** @return array{rank: int, of: int}|null the brand's place by cost (cheapest first) among the brands with numbers */
    private function rank(?array $channel, array $costs, ?int $serviceId, int $brandId): ?array
    {
        if ($channel === null || $serviceId === null) {
            return null;
        }
        $pool = $costs[$serviceId.'|'.$channel['type']] ?? [];
        usort($pool, fn (array $a, array $b): int => $a['cost'] <=> $b['cost']);
        $position = array_search($brandId, array_column($pool, 'brand_id'), true);

        return $position === false || count($pool) < 2 ? null : ['rank' => $position + 1, 'of' => count($pool)];
    }

    /**
     * Web sitesi per service: pages linked to the service (OfferingPage) and their 30-day search clicks / key events.
     *
     * @return array<int, array{state: string, pages: int, clicks: int, key_events: float}>
     */
    private function website(Brand $brand): array
    {
        $links = OfferingPage::query()->join('pages', 'pages.id', '=', 'offering_pages.page_id')
            ->join('brand_offerings', 'brand_offerings.id', '=', 'offering_pages.brand_offering_id')->where('brand_offerings.brand_id', $brand->id)
            ->get(['offering_pages.brand_offering_id', 'pages.website_asset_id', 'pages.url']);
        $traffic = [];
        foreach ($links->pluck('website_asset_id')->unique() as $siteId) {
            $site = DigitalAsset::query()->find($siteId);
            if ($site !== null) {
                foreach ($this->site->pages($site, AdServiceStats::DAYS) as $page) {
                    $traffic[$siteId][$page['path']] = $page;
                }
            }
        }
        $out = [];
        foreach ($links as $link) {
            $page = $traffic[$link->website_asset_id][SiteAnalysisReader::path((string) $link->url)] ?? null;
            $entry = $out[(int) $link->brand_offering_id] ?? ['state' => 'none', 'pages' => 0, 'clicks' => 0, 'key_events' => 0.0];
            $entry['pages']++;
            $entry['clicks'] += (int) ($page['clicks'] ?? 0);
            $entry['key_events'] += (float) ($page['key_events'] ?? 0);
            $entry['state'] = $entry['clicks'] > 0 ? 'around' : 'worse';
            $out[(int) $link->brand_offering_id] = $entry;
        }

        return $out;
    }

    /** @return list<string>|null service and category labels of the brand's Business Profiles; null when none has data */
    private function profileServices(Brand $brand): ?array
    {
        $labels = null;
        foreach (DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'google_business_profile')->pluck('id') as $assetId) {
            $resource = $this->gbp->resource((int) $assetId);
            if ($resource === null) {
                continue;
            }
            $services = $this->gbpInput->services((int) $resource->id);
            if ($services['available']) {
                $labels = array_merge($labels ?? [], $services['labels']);
            }
        }

        return $labels;
    }

    /** @param  list<string>  $names  @param  list<string>  $labels */
    private function listed(array $names, array $labels): bool
    {
        foreach ($labels as $label) {
            foreach ($names as $name) {
                if (mb_strlen($name) >= 3 && (SeoText::containsPhrase($label, $name) || SeoText::containsPhrase($name, $label))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Up to three notes: the dearest service against its average, a main service with no ads, a service with no page.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{kind: string, title: string, body: string, cta: string, tab: string}>
     */
    private function notes(array $rows): array
    {
        $notes = [];
        $dearest = null;
        foreach ($rows as $row) {
            foreach (['meta' => 'Meta', 'google_ads' => 'Google Ads'] as $channel => $label) {
                $c = $row[$channel];
                if ($c !== null && $c['diff'] !== null && $c['diff'] >= AdServiceStats::AROUND_PCT && ($dearest === null || $c['diff'] > $dearest['diff'])) {
                    $dearest = ['diff' => $c['diff'], 'row' => $row, 'label' => $label, 'channel' => $channel];
                }
            }
        }
        if ($dearest !== null) {
            $c = $dearest['row'][$dearest['channel']];
            $notes[] = ['kind' => 'Maliyet yüksek', 'title' => $dearest['row']['name'].' · '.$dearest['label'],
                'body' => (AdServiceStats::TYPE_LABELS[$c['type']] ?? '').' başı maliyet, aynı hizmeti yapan '.$c['average']['brands'].' markanın ortancasından %'.number_format($c['diff'], 0, ',', '.').' yüksek.',
                'cta' => 'Kampanyalara bak', 'tab' => $dearest['channel']];
        }
        foreach ($rows as $row) {
            if ($row['main'] && $row['meta'] === null && $row['google_ads'] === null) {
                $notes[] = ['kind' => 'Fırsat', 'title' => $row['name'], 'body' => 'Ana hizmet; son 30 günde Meta’da da Google Ads’te de reklamı yok.', 'cta' => 'Dijital varlıklara bak', 'tab' => 'varliklar'];

                break;
            }
        }
        foreach ($rows as $row) {
            if ($row['web']['state'] === 'none') {
                $notes[] = ['kind' => 'Sayfa yok', 'title' => $row['name'], 'body' => 'Sitede bu hizmete bağlı sayfa yok; reklam ve arama trafiği genel sayfalara düşer.', 'cta' => 'Bilgi dosyasına bak', 'tab' => 'dosya'];

                break;
            }
        }

        return array_slice($notes, 0, 3);
    }
}
