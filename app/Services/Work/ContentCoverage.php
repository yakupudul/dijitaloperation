<?php

namespace App\Services\Work;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Ai\AiBudget;
use App\Services\Site\SiteSuggestionTypes;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Genel işler › Web site SEO içerikler, marka tablosu (yakup, 2026-10-06: "hangi sitede hangi içerik eksik, kümeler
 * bazında"): per operational brand's website how its clusters are answered (sayfa yok / kapsam yetersiz / zayıf /
 * yeterli), how many titles wait, are being read or were sent, and — when nothing waits — why, in plain words. The
 * same reading decides which sites the Monday title run (`moxdop:content:weekly-titles`) starts for. Rules only.
 */
final class ContentCoverage
{
    /** Cluster states a new or reworked article answers (the weekly title run works from these). */
    public const array GAP_STATES = ['no_page', 'thin_coverage'];

    public const array WEAK_STATES = ['weak_performance', 'possible_conflict', 'wrong_page'];

    /**
     * @return list<array{brand_id: int, brand: string, site: DigitalAsset, clusters: int, missing: int, weak: int, ok: int, waiting: int, capacity: int, reading: int, sent: int, last_title_at: ?CarbonInterface, reason: ?string}>
     */
    public function rows(?int $brandId = null): array
    {
        $brands = Brand::query()->operational()->when($brandId !== null, fn ($q) => $q->whereKey($brandId))->orderBy('name')->get(['id', 'name', 'weekly_content_capacity']);
        $sites = DigitalAsset::query()->where('type', 'website')->whereIn('brand_id', $brands->pluck('id')->all() ?: [0])->orderBy('id')->get()->groupBy('brand_id');
        $states = BrandClusterPage::query()->whereIn('brand_id', $brands->pluck('id')->all() ?: [0])->where('excluded', false)
            ->selectRaw('website_asset_id, state, count(distinct cluster_id) as n')->groupBy('website_asset_id', 'state')->get()
            ->groupBy('website_asset_id')->map(fn (Collection $rows): array => $rows->mapWithKeys(fn ($r): array => [(string) $r->state => (int) $r->n])->all());
        $titles = $this->titleCounts($brands->pluck('id')->all());
        $rows = [];
        foreach ($brands as $brand) {
            foreach ($sites->get($brand->id, collect()) as $site) {
                $byState = $states->get($site->id, []);
                $count = $titles[(int) $site->id] ?? ['waiting' => 0, 'reading' => 0, 'sent' => 0, 'last' => null];
                $row = [
                    'brand_id' => (int) $brand->id, 'brand' => (string) $brand->name, 'site' => $site,
                    'clusters' => array_sum($byState),
                    'missing' => array_sum(array_intersect_key($byState, array_flip(self::GAP_STATES))),
                    'weak' => array_sum(array_intersect_key($byState, array_flip(self::WEAK_STATES))),
                    'ok' => (int) ($byState['sufficient'] ?? 0),
                    'waiting' => $count['waiting'], 'capacity' => self::capacity($brand), 'reading' => $count['reading'], 'sent' => $count['sent'],
                    'last_title_at' => $count['last'],
                ];
                $row['reason'] = self::reason($row);
                $rows[] = $row;
            }
        }
        usort($rows, fn (array $a, array $b): int => [$b['missing'] > 0 && $b['waiting'] === 0, $b['missing']] <=> [$a['missing'] > 0 && $a['waiting'] === 0, $a['missing']]);

        return $rows;
    }

    /**
     * Sites the Monday title run starts for: the brand is operational, the site has clusters without a (good) page
     * and fewer titles wait than the brand's weekly capacity — the stock is topped up, never piled up.
     *
     * @return list<int>
     */
    public function sitesNeedingTitles(?int $siteId = null): array
    {
        return collect($this->rows())
            ->filter(fn (array $row): bool => ($siteId === null || (int) $row['site']->id === $siteId) && $row['missing'] > 0 && $row['waiting'] < $row['capacity'])
            ->map(fn (array $row): int => (int) $row['site']->id)->values()->all();
    }

    public static function automaticAllowed(): bool
    {
        return AiBudget::automaticAllowed('site.weekly_content');
    }

    private static function capacity(Brand $brand): int
    {
        return max(1, min(20, (int) ($brand->weekly_content_capacity ?? 4)));
    }

    /** @param  array{clusters: int, missing: int, waiting: int, reading: int}  $row */
    private static function reason(array $row): ?string
    {
        return match (true) {
            $row['waiting'] > 0 || $row['reading'] > 0 => null,
            $row['clusters'] === 0 => 'Kümeler bu siteyle eşleştirilmedi (onaylı küme ya da hizmet yok, veya Eşleştir çalışmadı).',
            $row['missing'] === 0 => 'Kümelerin hepsinin uygun sayfası var; yeni başlık gerekmiyor.',
            default => 'Eksik küme var; başlıklar pazartesi kendiliğinden üretilir (ya da sitenin İçerik sekmesinden şimdi).',
        };
    }

    /**
     * @param  list<int>  $brandIds
     * @return array<int, array{waiting: int, reading: int, sent: int, last: ?CarbonInterface}>
     */
    private function titleCounts(array $brandIds): array
    {
        $out = [];
        Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->whereIn('brand_id', $brandIds ?: [0])
            ->where(fn ($q) => $q->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK, Suggestion::SNOOZED, Suggestion::APPLIED]))
            ->where('created_at', '>=', now()->subDays(180))
            ->get(['id', 'status', 'action', 'created_at', 'applied_at', 'snoozed_until'])
            ->each(function (Suggestion $s) use (&$out): void {
                $site = (int) data_get($s->action, 'site_id');
                $out[$site] ??= ['waiting' => 0, 'reading' => 0, 'sent' => 0, 'last' => null];
                $action = (array) $s->action;
                $sent = isset($action['article_write_id']) || $s->status === Suggestion::APPLIED;
                $written = is_array($action['article'] ?? null) || isset($action['article_blocked']);
                if ($sent) {
                    $out[$site]['sent'] += ($s->applied_at ?? $s->created_at)?->gte(now()->subDays(WorkDesk::DONE_DAYS)) ? 1 : 0;
                } elseif ($written) {
                    $out[$site]['reading']++;
                } elseif ($s->status === Suggestion::OPEN || ($s->status === Suggestion::SNOOZED && $s->snoozed_until?->isPast())) {
                    $out[$site]['waiting']++;
                }
                if ($out[$site]['last'] === null || $s->created_at?->gt($out[$site]['last'])) {
                    $out[$site]['last'] = $s->created_at;
                }
            });

        return $out;
    }
}
