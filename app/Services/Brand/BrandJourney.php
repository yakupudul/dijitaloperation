<?php

namespace App\Services\Brand;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\BrandQuery;
use App\Models\Cluster;
use App\Models\ServiceCatalogItem;
use App\Models\Suggestion;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Work\ContentCoverage;
use App\Services\Work\WorkDesk;

/**
 * Yolculuk (yakup, 2026-10-07: "entegrasyondan sorguya, hizmete, kümeye, içerik fikirlerine yolculuk"): one line per
 * brand from its searches to the articles sent, each stage counted from the stored data (rules, no AI): searches the
 * brand is seen for → those that belong to one of its services → the services' approved clusters → clusters matched to
 * its site (and how many lack a page) → ideas waiting in the pool → articles sent in 30 days. The first stage that is
 * empty breaks the chain and says why and where it is fixed; searches that belong to a service the brand does not offer
 * are listed as a hint.
 */
final class BrandJourney
{
    /**
     * @return array{stages: list<array{key: string, label: string, value: int, detail: string}>, broken: ?array{key: string, reason: string, fix: string}, foreign: list<array{service: string, queries: int}>}
     */
    public function for(Brand $brand): array
    {
        $serviceIds = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->whereNotNull('service_catalog_item_id')
            ->pluck('service_catalog_item_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $searches = BrandQuery::query()->where('brand_id', $brand->id)->count();
        $byService = BrandQuery::query()->join('queries', 'queries.id', '=', 'brand_queries.query_id')->where('brand_queries.brand_id', $brand->id)
            ->where('queries.hidden', false)->whereNotNull('queries.service_id')
            ->selectRaw('queries.service_id as service_id, count(*) as n')->groupBy('queries.service_id')->pluck('n', 'service_id')->map(fn ($n): int => (int) $n);
        $assigned = (int) $byService->only($serviceIds)->sum();
        $clusters = Cluster::query()->whereIn('service_id', $serviceIds ?: [0])->where('approved', true)->count();
        $pending = Cluster::query()->whereIn('service_id', $serviceIds ?: [0])->where('approved', false)->count();
        $states = BrandClusterPage::query()->where('brand_id', $brand->id)->where('excluded', false)
            ->selectRaw('state, count(distinct cluster_id) as n')->groupBy('state')->pluck('n', 'state')->map(fn ($n): int => (int) $n);
        $matched = (int) $states->sum();
        $gaps = (int) $states->only(ContentCoverage::GAP_STATES)->sum();
        [$waiting, $sent] = $this->ideas($brand);

        $stages = [
            ['key' => 'searches', 'label' => 'Sorgu', 'value' => $searches, 'detail' => 'markanın görüldüğü aramalar'],
            ['key' => 'assigned', 'label' => 'Hizmete atanan', 'value' => $assigned, 'detail' => count($serviceIds).' hizmet'],
            ['key' => 'clusters', 'label' => 'Küme', 'value' => $clusters, 'detail' => $pending > 0 ? $pending.' küme onay bekliyor' : 'onaylı'],
            ['key' => 'matched', 'label' => 'Sitede eşleşen', 'value' => $matched, 'detail' => $gaps.' kümenin sayfası yok ya da zayıf'],
            ['key' => 'pool', 'label' => 'Havuzda fikir', 'value' => $waiting, 'detail' => 'onay bekleyen başlık'],
            ['key' => 'sent', 'label' => 'Yazılan', 'value' => $sent, 'detail' => 'son 30 gün'],
        ];

        return ['stages' => $stages, 'broken' => $this->broken($searches, $serviceIds, $assigned, $clusters, $pending, $matched, $waiting), 'foreign' => $this->foreign($byService->except($serviceIds)->all())];
    }

    /**
     * @param  list<int>  $serviceIds
     * @return array{key: string, reason: string, fix: string}|null
     */
    private function broken(int $searches, array $serviceIds, int $assigned, int $clusters, int $pending, int $matched, int $waiting): ?array
    {
        return match (true) {
            $searches === 0 => ['key' => 'searches', 'reason' => 'Markanın arama verisi yok: Search Console bağlı değil ya da veri henüz gelmedi.', 'fix' => 'varliklar'],
            $serviceIds === [] => ['key' => 'assigned', 'reason' => 'Markada etkin hizmet yok; aramalar hiçbir hizmete bağlanamıyor (gece "Marka tamamlama" doldurur).', 'fix' => 'ayarlar'],
            $assigned === 0 => ['key' => 'assigned', 'reason' => 'Aramaların hiçbiri markanın hizmetlerine atanmadı; hizmetlerin eşleştirme ifadeleri eksik olabilir.', 'fix' => 'ayarlar'],
            $clusters === 0 => ['key' => 'clusters', 'reason' => $pending > 0 ? $pending.' küme onay bekliyor; onaylanınca siteyle eşleşir.' : 'Hizmetlerin henüz kümesi yok; günlük kümeleme yeni sorgularla kurar.', 'fix' => 'ozet'],
            $matched === 0 => ['key' => 'matched', 'reason' => 'Kümeler siteyle eşleştirilmedi; site ekranında "Eşleştir" çalışmalı.', 'fix' => 'ozet'],
            $waiting === 0 => ['key' => 'pool', 'reason' => 'Havuzda bekleyen fikir yok; her sabah kanıtı olan kümelerden tamamlanır (nedeni Onarım masası › Diğer işler › İçerik ve büyüme tablosunda).', 'fix' => 'ozet'],
            default => null,
        };
    }

    /**
     * Content ideas of the brand: waiting in the pool (open, not written yet) and sent to the site in the last 30 days.
     * Counted once per brand, whatever the number of sites.
     *
     * @return array{0: int, 1: int}
     */
    private function ideas(Brand $brand): array
    {
        $waiting = 0;
        $sent = 0;
        Suggestion::query()->where('brand_id', $brand->id)->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where(fn ($q) => $q->where('status', Suggestion::OPEN)->orWhere(fn ($a) => $a->whereIn('status', [Suggestion::APPROVED, Suggestion::APPLIED])->where('updated_at', '>=', now()->subDays(WorkDesk::DONE_DAYS))))
            ->get(['status', 'action', 'applied_at', 'created_at'])
            ->each(function (Suggestion $s) use (&$waiting, &$sent): void {
                $action = (array) $s->action;
                if (isset($action['article_write_id']) || $s->status === Suggestion::APPLIED) {
                    $sent += ($s->applied_at ?? $s->created_at)?->gte(now()->subDays(WorkDesk::DONE_DAYS)) ? 1 : 0;
                } elseif ($s->status === Suggestion::OPEN && ! is_array($action['article'] ?? null) && ! isset($action['article_blocked'])) {
                    $waiting++;
                }
            });

        return [$waiting, $sent];
    }

    /**
     * Searches of the brand that belong to services it does not offer, most first (at most 5).
     *
     * @param  array<int, int>  $counts  service id => searches
     * @return list<array{service: string, queries: int}>
     */
    private function foreign(array $counts): array
    {
        arsort($counts);
        $top = array_slice($counts, 0, 5, true);
        $names = ServiceCatalogItem::query()->with('primaryName')->whereIn('id', array_keys($top) ?: [0])->get()
            ->mapWithKeys(fn (ServiceCatalogItem $s): array => [(int) $s->id => (string) ($s->primaryName?->raw_label ?? '')]);

        return array_values(array_filter(array_map(fn (int $id, int $n): array => ['service' => (string) ($names[$id] ?? ''), 'queries' => $n], array_keys($top), $top), fn (array $r): bool => $r['service'] !== '' && $r['queries'] >= 5));
    }
}
