<?php

namespace App\Services\Work;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\Suggestion;
use App\Services\Ai\AiBudget;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteSuggestionTypes;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Genel işler › Web site SEO içerikler, marka tablosu (yakup, 2026-10-06: "hangi sitede hangi içerik eksik, kümeler
 * bazında"; "havuzda her koşulda her dilde 20 içerik fikri olsun, üstüne haftalık üretim"): per operational brand's
 * website how its clusters are answered (sayfa yok / kapsam yetersiz / zayıf / yeterli), the idea pool of every
 * active language (waiting titles out of POOL), what is being read or was sent and — when nothing waits — why. The
 * same reading tells the title run (`moxdop:content:weekly-titles`) which site and language to fill. Rules only.
 */
final class ContentCoverage
{
    /** Waiting titles every active language of a site always has. */
    public const int POOL = 20;

    /** Cluster states a new or reworked article answers. */
    public const array GAP_STATES = ['no_page', 'thin_coverage'];

    public const array WEAK_STATES = ['weak_performance', 'possible_conflict', 'wrong_page'];

    /**
     * @return list<array{brand_id: int, brand: string, site: DigitalAsset, clusters: int, missing: int, weak: int, ok: int, pool: array<string, int>, waiting: int, weekly: int, reading: int, sent: int, last_title_at: ?CarbonInterface, reason: ?string}>
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
                $count = $titles[(int) $site->id] ?? ['waiting' => [], 'reading' => 0, 'sent' => 0, 'last' => null];
                $languages = ContentPlanner::siteLanguages($site);
                $pool = [];
                foreach ($languages as $language) {
                    $pool[$language] = (int) ($count['waiting'][$language] ?? 0) + ($language === $languages[0] ? (int) ($count['waiting'][''] ?? 0) : 0);
                }
                $row = [
                    'brand_id' => (int) $brand->id, 'brand' => (string) $brand->name, 'site' => $site,
                    'clusters' => array_sum($byState),
                    'missing' => array_sum(array_intersect_key($byState, array_flip(self::GAP_STATES))),
                    'weak' => array_sum(array_intersect_key($byState, array_flip(self::WEAK_STATES))),
                    'ok' => (int) ($byState['sufficient'] ?? 0),
                    'pool' => $pool, 'waiting' => array_sum($pool), 'weekly' => self::weekly($brand),
                    'reading' => $count['reading'], 'sent' => $count['sent'], 'last_title_at' => $count['last'],
                ];
                $row['reason'] = self::reason($row);
                $rows[] = $row;
            }
        }
        usort($rows, fn (array $a, array $b): int => [self::short($b), $b['missing']] <=> [self::short($a), $a['missing']]);

        return $rows;
    }

    /**
     * What the title run asks for: every active language of a site with matched clusters gets its pool back to POOL;
     * on Monday (`$weekly`) each language also gets at least the brand's weekly number of fresh ideas on top. One entry
     * per site with all its languages, so the AI reads the site's clusters once.
     *
     * @return list<array{site_id: int, wants: array<string, int>}>
     */
    public function needs(bool $weekly = false, ?int $siteId = null): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            if (($siteId !== null && (int) $row['site']->id !== $siteId) || $row['clusters'] === 0) {
                continue;
            }
            if (! $weekly && $siteId === null && Cache::has(ContentPlanner::shortRunKey((int) $row['site']->id))) {
                continue; // the last run had no evidence for more; no filler until Monday or new data
            }
            $wants = [];
            foreach ($row['pool'] as $language => $waiting) {
                $want = max(self::POOL - $waiting, $weekly ? $row['weekly'] : 0);
                if ($want > 0) {
                    $wants[(string) $language] = min(20, $want);
                }
            }
            if ($wants !== []) {
                $out[] = ['site_id' => (int) $row['site']->id, 'wants' => $wants];
            }
        }

        return $out;
    }

    public static function automaticAllowed(): bool
    {
        return AiBudget::automaticAllowed('site.weekly_content');
    }

    private static function weekly(Brand $brand): int
    {
        return max(1, min(20, (int) ($brand->weekly_content_capacity ?? 4)));
    }

    /** @param  array{clusters: int, pool: array<string, int>}  $row  a language below the pool, on a site that can be filled */
    private static function short(array $row): bool
    {
        return $row['clusters'] > 0 && min($row['pool'] ?: [self::POOL]) < self::POOL;
    }

    /** @param  array{clusters: int, missing: int, waiting: int, reading: int}  $row */
    private static function reason(array $row): ?string
    {
        return match (true) {
            $row['clusters'] === 0 => 'Kümeler bu siteyle eşleştirilmedi (onaylı küme ya da hizmet yok, veya Eşleştir çalışmadı); havuz dolamaz.',
            $row['waiting'] > 0 || $row['reading'] > 0 => null,
            default => 'Havuz boş; her dilde '.self::POOL.' fikre her sabah kendiliğinden tamamlanır (ya da "Fikir üret").',
        };
    }

    /**
     * @param  list<int>  $brandIds
     * @return array<int, array{waiting: array<string, int>, reading: int, sent: int, last: ?CarbonInterface}> waiting per title language ('' = not set: the site's main language)
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
                $out[$site] ??= ['waiting' => [], 'reading' => 0, 'sent' => 0, 'last' => null];
                $action = (array) $s->action;
                $sent = isset($action['article_write_id']) || $s->status === Suggestion::APPLIED;
                $written = is_array($action['article'] ?? null) || isset($action['article_blocked']);
                if ($sent) {
                    $out[$site]['sent'] += ($s->applied_at ?? $s->created_at)?->gte(now()->subDays(WorkDesk::DONE_DAYS)) ? 1 : 0;
                } elseif ($written) {
                    $out[$site]['reading']++;
                } elseif ($s->status === Suggestion::OPEN || ($s->status === Suggestion::SNOOZED && $s->snoozed_until?->isPast())) {
                    $language = strtolower((string) ($action['language'] ?? ''));
                    $out[$site]['waiting'][$language] = ($out[$site]['waiting'][$language] ?? 0) + 1;
                }
                if ($out[$site]['last'] === null || $s->created_at?->gt($out[$site]['last'])) {
                    $out[$site]['last'] = $s->created_at;
                }
            });

        return $out;
    }
}
