<?php

namespace App\Services\Work;

use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Ai\AiBudget;
use App\Services\BrandSetup\BrandAutofill;
use App\Services\Site\ContentPlanner;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteScope;
use App\Services\Site\SiteSuggestionTypes;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Genel işler › Web site SEO içerikler, marka tablosu (yakup, 2026-10-06: "hangi sitede hangi içerik eksik, kümeler
 * bazında"; "havuzda her koşulda her dilde 20 içerik fikri olsun, üstüne haftalık üretim"): per operational brand's
 * website how its clusters are answered (sayfa yok / kapsam yetersiz / zayıf / yeterli), the idea pool of the
 * site's main language (waiting titles out of POOL; other languages are translations of the written article), what is being read or was sent and — when nothing waits — why. The
 * same reading tells the title run (`moxdop:content:weekly-titles`) which site and language to fill. Rules only.
 */
final class ContentCoverage
{
    /** Waiting titles the main language of a site always has. */
    public const int POOL = 20;

    /** Sent articles whose result (live, clicks) the table follows. */
    public const int OUTCOME_DAYS = 120;

    /** Cluster states a new or reworked article answers. */
    public const array GAP_STATES = ['no_page', 'thin_coverage'];

    public const array WEAK_STATES = ['weak_performance', 'possible_conflict', 'wrong_page'];

    /**
     * @return list<array{brand_id: int, brand: string, site: DigitalAsset, clusters: int, missing: int, weak: int, ok: int, pool: array<string, int>, translated: list<string>, waiting: int, weekly: int, reading: int, sent: int, last_title_at: ?CarbonInterface, last_run: ?array<string, mixed>, reason: ?string}>
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
                $count = $titles[(int) $site->id] ?? ['waiting' => [], 'writing' => 0, 'reading' => 0, 'sent' => 0, 'last' => null];
                // yakup, 2026-10-07: ideas are planned in the site's main language only; other languages get the
                // written article's translation, never ideas of their own.
                $languages = ContentPlanner::siteLanguages($site);
                $pool = [$languages[0] => (int) ($count['waiting'][$languages[0]] ?? 0) + (int) ($count['waiting'][''] ?? 0)];
                $row = [
                    'brand_id' => (int) $brand->id, 'brand' => (string) $brand->name, 'site' => $site,
                    'clusters' => array_sum($byState),
                    'missing' => array_sum(array_intersect_key($byState, array_flip(self::GAP_STATES))),
                    'weak' => array_sum(array_intersect_key($byState, array_flip(self::WEAK_STATES))),
                    'ok' => (int) ($byState['sufficient'] ?? 0),
                    'pool' => $pool, 'translated' => array_slice($languages, 1), 'waiting' => array_sum($pool), 'weekly' => self::weekly($brand),
                    'writing' => $count['writing'], 'reading' => $count['reading'], 'sent' => $count['sent'], 'last_title_at' => $count['last'],
                ];
                $row['last_run'] = ContentPlanner::lastRun((int) $site->id);
                $row['readiness'] = $row['clusters'] === 0 ? SiteScope::clusterReadiness($brand, $site) : null;
                $row['services'] = $row['readiness']['services'] ?? null;
                $row['reason'] = self::reason($row);
                if ($row['services'] === 0 && ($note = BrandAutofill::note((int) $brand->id)) !== null) {
                    $row['reason'] .= ' Son tur: '.$note['text'];
                }
                $rows[] = $row;
            }
        }
        usort($rows, fn (array $a, array $b): int => [self::short($b), $b['missing']] <=> [self::short($a), $a['missing']]);

        return $rows;
    }

    /**
     * What the title run asks for: the main language of a site with matched clusters gets its pool back to POOL; on
     * Monday (`$weekly`) it also gets at least the brand's weekly number of fresh ideas on top. One entry per site.
     *
     * @return list<array{site_id: int, wants: array<string, int>}>
     */
    public function needs(bool $weekly = false, ?int $siteId = null): array
    {
        $out = [];
        foreach ($this->rows() as $row) {
            // A site without matched clusters still fills from its own Search Console searches once the brand has services.
            if (($siteId !== null && (int) $row['site']->id !== $siteId) || ($row['clusters'] === 0 && (int) $row['services'] === 0)) {
                continue;
            }
            $last = $row['last_run'];
            if (! $weekly && $siteId === null && ($last['status'] ?? null) === 'no_candidates' && Carbon::parse($last['at'])->gt(now()->subHours(20))) {
                continue; // the data had no topic left today; the next day's data may have
            }
            if (($last['status'] ?? null) === 'queued' && Carbon::parse($last['at'])->gt(now()->subHours(36))) {
                continue; // the ideas wait in the Claude queue; asking again would only queue the same work twice
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

    /**
     * Sites whose brand has approved clusters for its services but no cluster row on the site yet: Eşleştir (rules,
     * cluster ↔ page) is started for them, at most once a day each.
     *
     * @return list<int> site ids started
     */
    public function startMatching(): array
    {
        $started = [];
        foreach ($this->rows() as $row) {
            $siteId = (int) $row['site']->id;
            if ($row['clusters'] > 0 || ($row['readiness']['step'] ?? null) !== 'match' || ! Cache::add('content-pool:match:'.$siteId, true, now()->addHours(20))) {
                continue;
            }
            SiteOperations::dispatch($siteId, SiteOperations::CLUSTER_PAGES);
            $started[] = $siteId;
        }

        return $started;
    }

    /**
     * Sonuç: of the articles sent as WordPress drafts in the last OUTCOME_DAYS, how many are live on the site now (the
     * crawl found the post) and the Search Console clicks those pages got in the last 28 and 90 days.
     *
     * @param  list<int>  $siteIds
     * @return array<int, array{sent: int, live: int, clicks: int, clicks90: int}>
     */
    public function outcomes(array $siteIds): array
    {
        $out = array_fill_keys($siteIds, ['sent' => 0, 'live' => 0, 'clicks' => 0, 'clicks90' => 0]);
        foreach ($this->articleResults($siteIds) as $result) {
            $out[$result['site_id']]['sent']++;
            $out[$result['site_id']]['live'] += $result['live'] ? 1 : 0;
            $out[$result['site_id']]['clicks'] += $result['clicks'];
            $out[$result['site_id']]['clicks90'] += $result['clicks90'];
        }

        return $out;
    }

    /**
     * Every article sent in the last OUTCOME_DAYS with its angle, whether its post is live on the site and the Search
     * Console clicks of the live page in 28 and 90 days (the pool's scoring learns from it: ContentPlanner::angleWeights).
     *
     * @param  list<int>|null  $siteIds  null: every site
     * @return list<array{suggestion_id: int, site_id: int, angle: ?string, live: bool, clicks: int, clicks90: int}>
     */
    public function articleResults(?array $siteIds = null): array
    {
        $ideas = Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->whereNotNull('action->article_write_id')
            ->where('updated_at', '>=', now()->subDays(self::OUTCOME_DAYS))->get(['id', 'action'])
            ->filter(fn (Suggestion $s): bool => $siteIds === null || in_array((int) data_get($s->action, 'site_id'), $siteIds, true));
        $writes = ExternalWriteAction::query()->whereIn('id', $ideas->map(fn (Suggestion $s): int => (int) data_get($s->action, 'article_write_id'))->all() ?: [0])
            ->whereIn('status', ['succeeded', 'partial'])->get(['id', 'result'])->keyBy('id');
        $out = [];
        foreach ($ideas as $idea) {
            $write = $writes->get((int) data_get($idea->action, 'article_write_id'));
            if ($write === null) {
                continue;
            }
            $siteId = (int) data_get($idea->action, 'site_id');
            $posts = array_values(array_unique(array_filter([(int) data_get($write->result, 'post_id'), ...array_map(fn (mixed $p): int => (int) data_get($p, 'post_id'), (array) data_get($write->result, 'posts', []))])));
            $urls = $posts === [] ? [] : Page::query()->where('website_asset_id', $siteId)->whereIn('wp_post_id', $posts)->pluck('url')->all();
            $clicks = fn (int $days): int => $urls === [] ? 0 : (int) DB::table('gsc_page_daily')->whereIn('page', $urls)->where('reporting_date', '>=', now()->subDays($days)->toDateString())->sum('clicks');
            $out[] = ['suggestion_id' => (int) $idea->id, 'site_id' => $siteId, 'angle' => is_string(data_get($idea->action, 'angle')) ? (string) data_get($idea->action, 'angle') : null,
                'live' => $urls !== [], 'clicks' => $clicks(28), 'clicks90' => $clicks(90)];
        }

        return $out;
    }

    /**
     * Where a site's content line stands, in one phrase: the first step that waits (the operator's step first).
     *
     * @param  array{clusters: int, waiting: int, writing: int, reading: int, sent: int}  $row
     * @return array{label: string, tone: string}
     */
    public static function stage(array $row): array
    {
        return match (true) {
            $row['reading'] > 0 => ['label' => $row['reading'].' yazı okumanı bekliyor', 'tone' => 'amber'],
            $row['writing'] > 0 => ['label' => $row['writing'].' yazı yazılıyor', 'tone' => 'sky'],
            $row['waiting'] > 0 => ['label' => 'Başlıklar onayını bekliyor', 'tone' => 'amber'],
            $row['clusters'] === 0 => ['label' => 'Tıkalı: küme yok', 'tone' => 'rose'],
            $row['sent'] > 0 => ['label' => 'Akıyor', 'tone' => 'emerald'],
            default => ['label' => 'Tıkalı: havuz boş', 'tone' => 'rose'],
        };
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
        return ($row['clusters'] > 0 || (int) ($row['services'] ?? 0) > 0) && min($row['pool'] ?: [self::POOL]) < self::POOL;
    }

    /**
     * Why the pool is not full, or null: no service, clusters not matched, the AI not answering, or the data having no
     * new topic left; otherwise how the last automatic run went.
     *
     * @param  array{clusters: int, missing: int, waiting: int, reading: int, last_run: ?array<string, mixed>, services: ?int, readiness: ?array<string, mixed>}  $row
     */
    private static function reason(array $row): ?string
    {
        $last = $row['last_run'];

        return match (true) {
            $row['clusters'] === 0 && $row['services'] === 0 => 'Markanın etkin hizmeti yok; marka tamamlama hizmetleri sitesinden doldurunca kümeler eşleşir ve havuz dolar.',
            $row['clusters'] === 0 && in_array($row['readiness']['step'] ?? null, ['catalog', 'clusters'], true) => 'Bu markanın hizmetleri için sorgu kütüphanesinde henüz küme yok (sektörde arama verisi az); havuz şimdilik sitenin kendi Search Console aramalarından dolar.',
            $row['clusters'] === 0 && ($row['readiness']['step'] ?? null) === 'approve' => 'Bu markanın hizmetlerinin kümeleri henüz onaylanmadı (Sorgular › Kümeler); havuz şimdilik sitenin kendi Search Console aramalarından dolar.',
            $row['clusters'] === 0 => 'Kümeler bu siteyle henüz eşleşmedi; sistem eşleştirmeyi günde iki kez kendisi başlatır, ardından havuz dolar.',
            $row['waiting'] >= self::POOL => null,
            ($last['status'] ?? null) === 'no_candidates' => 'Verilerde yeni konu kalmadı: her arama ve küme için fikir var ya da yazıldı. Yeni arama verisi gelince kendiliğinden dolar.',
            ($last['status'] ?? null) === 'queued' && (int) ($last['added'] ?? 0) === 0 => 'Fikirler Claude kuyruğunda yazılıyor; Claude yanıtlayınca havuz kendiliğinden dolar.',
            $last !== null && ! in_array($last['status'] ?? 'ready', ['ready', 'no_candidates'], true) && (int) ($last['added'] ?? 0) === 0 => ($last['message'] ?? null) !== null
                ? 'Son dolum yapılamadı: '.$last['message'].' Bir sonraki dolumda yeniden denenir.'
                : 'Son dolumda AI yanıt vermedi; bir sonraki dolumda yeniden denenir.',
            $row['waiting'] > 0 || $row['reading'] > 0 => null,
            default => 'Havuz boş; günde iki kez kendiliğinden '.self::POOL.' fikre tamamlanır.',
        };
    }

    /**
     * The last automatic run in one line, e.g. "Son dolum 09.10 09:17 · 24 aday · 12 eklendi · 3 elendi (2 kalıp başlık, 1 aynı başlık zaten var)".
     *
     * @param  array<string, mixed>|null  $run
     */
    public static function runLine(?array $run): ?string
    {
        if ($run === null) {
            return null;
        }
        $dropped = collect((array) ($run['dropped'] ?? []));
        $line = 'Son dolum '.Carbon::parse((string) $run['at'])->timezone('Europe/Istanbul')->format('d.m H:i').' · '.(int) $run['candidates'].' aday';
        if (($run['status'] ?? '') === 'no_candidates') {
            return $line.' · yeni konu yok';
        }
        $line .= ' · '.(int) $run['added'].' eklendi';
        if ($dropped->sum() > 0) {
            $line .= ' · '.$dropped->sum().' elendi ('.$dropped->map(fn (int $n, string $why): string => $n.' '.$why)->implode(', ').')';
        }

        return $line.match ($run['status'] ?? 'ready') {
            'ready', 'no_candidates' => '',
            'queued' => ' · Claude kuyruğunda',
            default => ' · AI yanıt vermedi',
        };
    }

    /**
     * @param  list<int>  $brandIds
     * @return array<int, array{waiting: array<string, int>, writing: int, reading: int, sent: int, last: ?CarbonInterface}> waiting per title language ('' = not set: the site's main language)
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
                $out[$site] ??= ['waiting' => [], 'writing' => 0, 'reading' => 0, 'sent' => 0, 'last' => null];
                $action = (array) $s->action;
                $sent = isset($action['article_write_id']) || $s->status === Suggestion::APPLIED;
                $written = is_array($action['article'] ?? null) || isset($action['article_blocked']);
                if ($sent) {
                    $out[$site]['sent'] += ($s->applied_at ?? $s->created_at)?->gte(now()->subDays(WorkDesk::DONE_DAYS)) ? 1 : 0;
                } elseif ($written) {
                    $out[$site]['reading']++;
                } elseif ($s->status === Suggestion::APPROVED) {
                    $out[$site]['writing']++;
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
