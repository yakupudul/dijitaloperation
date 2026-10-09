<?php

namespace App\Services\Site;

use App\Ai\Agents\Site\ContentDiscoveryAgent;
use App\Ai\Agents\Site\WeeklyContentAgent;
use App\Ai\Agents\Site\WriteArticleAgent;
use App\Models\Brand;
use App\Models\BrandClusterPage;
use App\Models\BrandOffering;
use App\Models\Cluster;
use App\Models\ClusterQuery;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Query;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Brand\BrandFacts;
use App\Services\Compliance\BriefCompliance;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\ExternalWrites\ArticleDraft;
use App\Services\ExternalWrites\ContentComplianceGate;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Meta\MetaDesk;
use App\Services\Queries\QueryNormalizer;
use App\Services\SeoTasks\SeoText;
use App\Services\SeoTasks\SiteUrlPattern;
use App\Services\Site\Analysis\SiteAnalysisReader;
use App\Services\Work\ContentCoverage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * İçerik: "Haftalık içerik öner" (`site.weekly_content`: main services, clusters without a suitable page / with thin
 * coverage, improvable URLs, previous plans of the last 8 weeks, month / season, weekly capacity) and "Kümeler dışında
 * fırsat keşfet" (`site.content_discovery`: brand queries outside every cluster) → content suggestions with title,
 * target cluster, page type, outline, the questions people ask AI assistants and the target URL (the site's own URL
 * pattern). "Kütüphaneye ekle" puts a discovery into the shared query / cluster library; "Taslak hazırla"
 * (`site.write_article`) writes the article, which passes the compliance gate before the WordPress draft.
 */
final class ContentPlanner
{
    public const array PAGE_TYPES = ['hizmet', 'blog', 'sss', 'lokasyon'];

    /** What makes a weekly idea more than "one more article" (shown on the title card). */
    public const array ANGLES = [
        'decision' => 'Karar desteği', 'comparison' => 'Karşılaştırma', 'process' => 'Süreç', 'local' => 'Yerel',
        'expert_answer' => 'AI aramalarına net yanıt', 'objection' => 'Endişe / yanlış bilinen', 'update' => 'Mevcut sayfayı güçlendir', 'insight' => 'SEO öngörüsü',
    ];

    private const array URL_TYPES = ['hizmet' => 'service', 'blog' => 'guide', 'sss' => 'faq', 'lokasyon' => 'location'];

    private const array CLUSTER_PAGE_TYPES = ['hizmet' => 'service', 'blog' => 'guide', 'sss' => 'faq', 'lokasyon' => 'location'];

    /** Gaps read per run; the best ones (brand's own searches, demand, main services) go to the AI. */
    private const int GAP_CANDIDATES = 300;

    /** Search Console queries the site already shows for but not near the top: the strongest idea material. */
    private const int STRIKING_QUERIES = 40;

    private const array MONTHS = [1 => 'Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];

    public function __construct(
        private readonly SiteAi $ai,
        private readonly BrandMemoryService $memory,
        private readonly SiteAnalysisReader $reader,
    ) {}

    /**
     * Open pool ideas whose titles read like generated text are closed (dismissed, so the next run never repeats them)
     * and the pool tops up with evidence-based ones. Approved, written or sent ideas are left alone.
     *
     * @return int ideas closed
     */
    public static function retireStyledIdeas(): int
    {
        $closed = 0;
        Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::OPEN)->orderBy('id')
            ->chunkById(500, function (Collection $ideas) use (&$closed): void {
                $ids = $ideas->filter(fn (Suggestion $s): bool => self::styleProblem((string) $s->title) !== null)->pluck('id')->all();
                if ($ids !== []) {
                    $closed += Suggestion::query()->whereIn('id', $ids)->update(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'operator_note' => 'Başlık kalıp gibiydi; kanıta dayalı fikirle değiştirildi.', 'updated_at' => now()]);
                }
            });

        return $closed;
    }

    /**
     * yakup (2026-10-07): other languages get no ideas of their own. Open ideas planned in a language other than their
     * site's main one (no article yet) are closed; the main-language idea's article is translated instead.
     */
    public static function retireOtherLanguageIdeas(): int
    {
        $closed = 0;
        $mains = [];
        Suggestion::query()->where('action_type', SiteSuggestionTypes::CONTENT)->where('status', Suggestion::OPEN)->orderBy('id')
            ->chunkById(500, function (Collection $ideas) use (&$closed, &$mains): void {
                $ids = $ideas->filter(function (Suggestion $s) use (&$mains): bool {
                    $action = (array) $s->action;
                    $language = strtolower((string) ($action['language'] ?? ''));
                    if ($language === '' || is_array($action['article'] ?? null)) {
                        return false;
                    }
                    $siteId = (int) ($action['site_id'] ?? 0);
                    if (! array_key_exists($siteId, $mains)) {
                        $site = DigitalAsset::query()->find($siteId);
                        $mains[$siteId] = $site !== null ? self::siteLanguages($site)[0] : null;
                    }

                    return $mains[$siteId] !== null && $language !== $mains[$siteId];
                })->pluck('id')->all();
                if ($ids !== []) {
                    $closed += Suggestion::query()->whereIn('id', $ids)->update(['status' => Suggestion::DISMISSED, 'resolved_at' => now(),
                        'operator_note' => 'Diğer diller için ayrı fikir üretilmez; ana dildeki yazı yazılınca bu dile çevrilir.', 'updated_at' => now()]);
                }
            });

        return $closed;
    }

    /**
     * A title that reads like generated text, or null: two-part titles (dash, colon, slash), labels in brackets
     * ("(güncelleme)"), "kapsamlı rehber", "hizmet sayfası" and over-long titles never reach the pool.
     */
    public static function styleProblem(string $title): ?string
    {
        $folded = SeoText::fold($title);

        return match (true) {
            mb_strlen($title) > 70 => 'çok uzun',
            (bool) preg_match('/\s[—–-]\s|—|:|\s\/\s|[()\[\]]/u', $title) => 'iki parçalı başlık',
            (bool) preg_match('/kapsamli rehber|rehberi?$|hizmet sayfasi|lokasyon sayfasi|nedir kimlere|hakkinda her sey|bilmeniz gereken|\bfaq\b|ultimate guide|complete guide|everything you need/u', $folded) => 'kalıp ifade',
            default => null,
        };
    }

    /**
     * İçerik fikir havuzu (yakup, 2026-10-09 "konuyu veri seçsin"): the topics come from rules, the AI only writes them.
     * Candidates are the site's Search Console queries at position 4–20 without a page of their own, clusters without a
     * suitable page or with thin coverage, weak pages to strengthen and the questions people ask AI assistants, each with
     * its numbers as evidence, scored (demand × service weight × what earlier articles of the angle brought) and mixed
     * (about 60% new, 25% updates, 15% AI questions). The AI writes one title, angle and outline per candidate; what a
     * rule drops is asked once more with the reason, and every run leaves its counts and reasons on the content line
     * (`lastRun`). Ideas are only planned in the site's main language (yakup, 2026-10-07).
     *
     * @param  Collection<int, BrandClusterPage>|null  $only  "Konu üret": just these rows, one item
     * @param  array<string, int>|null  $wants  ideas per language; null: the brand's weekly number in the main language
     * @return array{status: string, added: int}
     */
    public function weekly(DigitalAsset $site, ?Collection $only = null, ?array $wants = null): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'added' => 0];
        }
        $main = self::siteLanguages($site)[0];
        $requested = collect($wants ?? [])->filter(fn ($n, $l): bool => $l === $main && (int) $n > 0)->map(fn ($n): int => min(20, (int) $n))->all();
        $explicit = $requested !== [] && $only === null;
        $capacity = $only !== null ? 1 : ($requested[$main] ?? max(1, min(20, (int) ($brand->weekly_content_capacity ?? 4))));
        $sitePages = $this->sitePages($site);
        $previous = $this->previousIdeas($brand);
        $candidates = $this->candidates($brand, $site, $only, $previous, $sitePages, $main);
        if ($candidates === []) {
            $this->logRun($site, ['status' => 'no_candidates', 'candidates' => 0, 'asked' => 0, 'added' => 0, 'dropped' => []]);

            return ['status' => 'ready', 'added' => 0];
        }
        $chosen = $only !== null ? array_slice($candidates, 0, 1) : self::mix($candidates, $capacity + (int) ceil($capacity / 4));
        $taken = $previous->map(fn (Suggestion $s): string => SeoText::fold((string) $s->title))->all();
        $added = 0;
        $dropped = [];
        $pending = $chosen;
        $notes = [];
        $promptVersion = null;
        $status = 'ready';
        $message = null;
        for ($round = 0; $round < 2 && $pending !== [] && $added < $capacity; $round++) {
            $result = $this->ai->run(new WeeklyContentAgent, $this->pack($brand, $pending, $notes, $previous, $sitePages, $main), 240, $only === null ? 'weekly'.($round > 0 ? '-again' : '') : null);
            if ($result['status'] !== 'ready') {
                $status = $result['status'];
                $message = $result['message'] ?? null;
                break;
            }
            $promptVersion = $result['prompt_version_id'];
            $byId = collect((array) ($result['data']['items'] ?? []))->filter(fn ($i): bool => is_array($i) && is_int($i['candidate_id'] ?? null))->keyBy('candidate_id');
            $retry = [];
            $notes = [];
            foreach ($pending as $candidate) {
                if ($added >= $capacity) {
                    break;
                }
                $item = $byId->get($candidate['id']);
                $reason = $item === null ? 'AI bu konuyu yazmadı' : $this->storeCandidate($brand, $site, $sitePages, $candidate, $item, $taken, $explicit ? $main : null, $promptVersion);
                if ($reason === null) {
                    $added++;

                    continue;
                }
                if ($round === 0 && $item !== null && $reason !== 'aynı başlık zaten var') {
                    $retry[] = $candidate;
                    $notes[$candidate['id']] = ['rejected_title' => (string) ($item['title'] ?? ''), 'why' => $reason];

                    continue;
                }
                $dropped[$reason] = ($dropped[$reason] ?? 0) + 1;
            }
            $pending = $retry;
        }
        foreach ($pending as $candidate) {
            if ($status !== 'ready' && isset($notes[$candidate['id']])) {
                $dropped[$notes[$candidate['id']]['why']] = ($dropped[$notes[$candidate['id']]['why']] ?? 0) + 1;
            }
        }
        $this->logRun($site, ['status' => $status, 'candidates' => count($candidates), 'asked' => count($chosen), 'added' => $added, 'dropped' => $dropped, 'message' => $message]);

        return ['status' => $added > 0 ? 'ready' : $status, 'added' => $added];
    }

    public static function lastRunKey(int $siteId): string
    {
        return 'content-pool:last-run:'.$siteId;
    }

    /**
     * The last pool run of a site: when, how many candidates the data gave, how many were asked, added and why the rest
     * were dropped (shown on the content line instead of a silent pause).
     *
     * @return array{at: string, status: string, candidates: int, asked: int, added: int, dropped: array<string, int>}|null
     */
    public static function lastRun(int $siteId): ?array
    {
        $run = Cache::get(self::lastRunKey($siteId));

        return is_array($run) ? $run : null;
    }

    /** @param  array{status: string, candidates: int, asked: int, added: int, dropped: array<string, int>}  $run */
    private function logRun(DigitalAsset $site, array $run): void
    {
        arsort($run['dropped']);
        Cache::put(self::lastRunKey((int) $site->id), ['at' => now()->toIso8601String()] + $run, now()->addDays(30));
    }

    /**
     * The brand's ideas of the last 180 days (any status) and every idea still waiting, approved or snoozed: their titles,
     * clusters, queries and pages are never planned again.
     *
     * @return Collection<int, Suggestion>
     */
    private function previousIdeas(Brand $brand): Collection
    {
        return Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)
            ->where(fn ($q) => $q->where('created_at', '>=', now()->subDays(180))->orWhereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED]))
            ->orderByDesc('id')->limit(2000)->get(['id', 'title', 'status', 'action', 'cluster_id', 'page_id', 'created_at']);
    }

    /**
     * Aday konular, best first. Each: id, source (query | cluster | update | ai_question), kind, cluster, query, target
     * page, score, evidence and the texts its numbers may come from.
     *
     * @param  Collection<int, BrandClusterPage>|null  $only
     * @param  Collection<int, Suggestion>  $previous
     * @param  Collection<int, Page>  $sitePages
     * @return list<array<string, mixed>>
     */
    private function candidates(Brand $brand, DigitalAsset $site, ?Collection $only, Collection $previous, Collection $sitePages, string $main): array
    {
        $usedKeys = $previous->map(fn (Suggestion $s): ?string => data_get($s->action, 'candidate'))->filter()->flip()->all();
        $usedQueries = $previous->map(fn (Suggestion $s): ?string => is_string(data_get($s->action, 'query')) ? SeoText::fold((string) data_get($s->action, 'query')) : null)->filter()->flip()->all();
        // Clusters already answered by a recent idea go last (rotation), not out: a cluster can carry more than one article.
        $recentClusters = $previous->filter(fn (Suggestion $s): bool => $s->cluster_id !== null && ($s->created_at?->gt(now()->subWeeks(8)) ?? false))
            ->pluck('cluster_id')->map(fn ($id): int => (int) $id)->flip()->all();
        $updatedPages = $previous->pluck('page_id')->filter()->map(fn ($id): int => (int) $id)->flip()->all();
        $inMain = fn ($q) => $q->where(fn ($l) => $l->whereNull('language')->orWhere('language', $main));
        $rows = $only ?? $inMain(BrandClusterPage::query()->with(['cluster.mainQuery', 'cluster.service.primaryName', 'page:id,url,title,path'])->where('brand_id', $brand->id)
            ->where('website_asset_id', $site->id)->whereIn('state', ['no_page', 'thin_coverage', 'weak_performance'])->where('excluded', false))
            ->orderBy('id')->limit(self::GAP_CANDIDATES)->get();
        $clusterIds = $rows->pluck('cluster_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $volumes = $this->clusterVolumes($clusterIds);
        $brandSearch = $only !== null ? [] : $this->brandSearch($site);
        $queries = $this->clusterQueries($clusterIds);
        $mainServices = BrandOffering::query()->where('brand_id', $brand->id)->where('priority', 'main')->whereNotNull('service_catalog_item_id')->pluck('service_catalog_item_id')->map(fn ($id): int => (int) $id)->flip()->all();
        $paid = collect($only !== null ? [] : $this->paidResults($brand))->groupBy('service_id')->map(fn (Collection $r): float => (float) $r->sum('results'))->all();
        $angleWeights = $only !== null ? [] : self::angleWeights();
        $weight = function (?int $serviceId) use ($mainServices, $paid): float {
            return 1.0 + ($serviceId !== null && isset($mainServices[$serviceId]) ? 1.0 : 0.0) + ($serviceId !== null && isset($paid[$serviceId]) ? 0.5 : 0.0);
        };
        $number = fn (float $n, int $d = 0): string => number_format($n, $d, ',', '.');
        $paidLine = fn (?int $serviceId): array => $serviceId !== null && isset($paid[$serviceId])
            ? [['kind' => 'paid', 'value' => 'Reklamda bu hizmet 30 günde '.$number($paid[$serviceId]).' sonuç getirdi', 'source' => 'Google Ads / Meta']] : [];
        $out = [];
        $seenQueries = [];

        // 1) Search Console: searches the site is shown for at position 4–20 without a page of their own.
        if ($only === null) {
            $striking = $this->strikingQueries($site);
            $clusterOfQuery = $this->queryClusters(array_column($striking, 'query'), $clusterIds);
            foreach ($striking as $s) {
                $folded = SeoText::fold($s['query']);
                if (isset($usedQueries[$folded]) || isset($usedKeys['q:'.$folded]) || mb_strlen($folded) < 4) {
                    continue;
                }
                $page = $this->pageFor($s['query'], $sitePages);
                if ($page !== null && isset($updatedPages[(int) $page->id])) {
                    continue;
                }
                $row = isset($clusterOfQuery[$folded]) ? $rows->firstWhere('cluster_id', $clusterOfQuery[$folded]) : null;
                $serviceId = $row?->cluster?->service_id !== null ? (int) $row->cluster->service_id : null;
                $angle = $page !== null ? 'update' : (self::isQuestion($s['query']) ? 'expert_answer' : null);
                $seenQueries[$folded] = true;
                $out[] = [
                    'key' => 'q:'.$folded, 'source' => 'query', 'kind' => $page !== null ? 'update' : 'new', 'row' => $row, 'query' => $s['query'], 'page' => $page, 'angle_hint' => $angle,
                    'score' => 1.5 * log10(1 + $s['impressions']) * ($s['position'] <= 10 ? 1.3 : 1.0) * $weight($serviceId) * ($angleWeights[$angle ?? 'decision'] ?? 1.0),
                    'evidence' => [['kind' => 'query', 'value' => '«'.$s['query'].'» 28 günde '.$number($s['impressions']).' gösterim, '.$number($s['clicks']).' tıklama'
                        .($s['position'] !== null ? ', ortalama '.$number((float) $s['position'], 1).'. sıra' : '').($page !== null ? ' · cevaplayan sayfa: '.SeoText::urlPath((string) $page->url) : ' · kendi sayfası yok'), 'source' => 'Search Console'],
                        ...$paidLine($serviceId)],
                ];
            }
        }

        // 2) Clusters without a suitable page / with thin coverage, 3) weak pages, 4) AI-assistant questions of the clusters.
        foreach ($rows as $row) {
            $cluster = $row->cluster;
            if ($cluster === null) {
                continue;
            }
            $serviceId = $cluster->service_id !== null ? (int) $cluster->service_id : null;
            $volume = $volumes[(int) $row->cluster_id] ?? 0;
            $search = $brandSearch[(int) $row->cluster_id] ?? null;
            $demand = $weight($serviceId) * log10(1 + $volume) + 1.5 * log10(1 + ($search['impressions'] ?? 0));
            $rotation = isset($recentClusters[(int) $row->cluster_id]) ? 1 : 0;
            $parts = array_filter([
                $volume > 0 ? 'sorgu kütüphanesinde '.$number($volume).' gösterim' : null,
                $search !== null && $search['impressions'] > 0 ? 'sitede 28 günde '.$number($search['impressions']).' gösterim'.($search['position'] !== null ? ', '.$number((float) $search['position'], 1).'. sıra' : '') : null,
            ]);
            $clusterLine = ['kind' => 'cluster', 'value' => $cluster->name.' · '.($parts !== [] ? implode(' · ', $parts).' · ' : '').$row->stateLabel(), 'source' => 'küme'];
            $mainQuery = (string) ($cluster->mainQuery?->text ?? ($queries[(int) $row->cluster_id][0] ?? ''));
            $isUpdate = $row->state !== 'no_page' && $row->page !== null;
            if ($isUpdate && isset($updatedPages[(int) $row->page_id])) {
                continue;
            }
            $key = ($isUpdate ? 'p:'.$row->page_id.':' : 'c:').$row->cluster_id;
            if (! isset($usedKeys[$key])) {
                $out[] = [
                    'key' => $key, 'source' => $isUpdate ? 'update' : 'cluster', 'kind' => $isUpdate ? 'update' : 'new', 'row' => $row, 'query' => $mainQuery !== '' ? $mainQuery : null,
                    'page' => $isUpdate ? $row->page : null, 'angle_hint' => $isUpdate ? 'update' : null,
                    'tier' => $rotation, 'score' => $demand * ($isUpdate ? ($row->state === 'weak_performance' ? 0.7 : 0.85) : 1.0) * ($angleWeights[$isUpdate ? 'update' : 'decision'] ?? 1.0),
                    'evidence' => [$clusterLine, ...($isUpdate ? [['kind' => 'page', 'value' => SeoText::urlPath((string) $row->page->url).' · '.$row->stateLabel()
                        .(filled($row->reason) ? ' · '.mb_substr((string) $row->reason, 0, 120) : ''), 'source' => 'site']] : []), ...$paidLine($serviceId)],
                ];
            }
            if ($only !== null || $row->state !== 'no_page') {
                continue;
            }
            $question = collect(ClusterAudit::aiQuestions($cluster, $brand))->first(fn (string $q): bool => ! isset($usedQueries[SeoText::fold($q)]) && ! isset($usedKeys['a:'.SeoText::fold($q)]));
            if ($question !== null && $demand > 0) {
                $out[] = [
                    'key' => 'a:'.SeoText::fold($question), 'source' => 'ai_question', 'kind' => 'new', 'row' => $row, 'query' => $question, 'page' => null, 'angle_hint' => 'expert_answer',
                    'tier' => $rotation, 'score' => 0.8 * $demand * ($angleWeights['expert_answer'] ?? 1.0),
                    'evidence' => [['kind' => 'ai_question', 'value' => 'AI asistanlarında sorulan: «'.$question.'»', 'source' => 'AI aramaları'], $clusterLine],
                ];
            }
        }
        // Clusters a recent idea already answers go last (rotation; yakup, 2026-10-07: pools stuck on the same clusters).
        usort($out, fn (array $a, array $b): int => [$a['tier'] ?? 0, $b['score']] <=> [$b['tier'] ?? 0, $a['score']]);
        foreach ($out as $i => &$candidate) {
            $candidate['id'] = $i + 1;
            $candidate['queries'] = $candidate['row'] !== null ? ($queries[(int) $candidate['row']->cluster_id] ?? []) : [];
        }
        unset($candidate);

        return $out;
    }

    /**
     * Karışım: about 60% new pages, 25% updates of existing pages and 15% answers to AI-assistant questions, best first in
     * each; a short share is filled from the best of the rest.
     *
     * @param  list<array<string, mixed>>  $candidates  best first
     * @return list<array<string, mixed>>
     */
    public static function mix(array $candidates, int $count): array
    {
        $groups = ['new' => [], 'update' => [], 'ai' => []];
        foreach ($candidates as $c) {
            $groups[$c['source'] === 'ai_question' ? 'ai' : ($c['kind'] === 'update' ? 'update' : 'new')][] = $c;
        }
        $quota = ['update' => (int) round($count * 0.25), 'ai' => (int) round($count * 0.15)];
        $quota['new'] = max(0, $count - $quota['update'] - $quota['ai']);
        $chosen = [];
        foreach ($quota as $group => $n) {
            foreach (array_slice($groups[$group], 0, $n) as $c) {
                $chosen[$c['id']] = $c;
            }
        }
        foreach ($candidates as $c) {
            if (count($chosen) >= $count) {
                break;
            }
            $chosen[$c['id']] ??= $c;
        }
        $chosen = array_values($chosen);
        usort($chosen, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return array_slice($chosen, 0, $count);
    }

    /**
     * What earlier articles of each angle brought (live pages' Search Console clicks in 28 days against the average of
     * all), 0.7–1.5; an angle with fewer than 3 live articles stays 1.0.
     *
     * @return array<string, float>
     */
    public static function angleWeights(): array
    {
        return Cache::remember('content-pool:angle-weights', now()->addHours(12), function (): array {
            $clicks = [];
            foreach (app(ContentCoverage::class)->articleResults() as $result) {
                if ($result['live'] && $result['angle'] !== null) {
                    $clicks[$result['angle']][] = $result['clicks'];
                }
            }
            $all = array_merge(...array_values($clicks ?: [[]]));
            $average = $all !== [] ? array_sum($all) / count($all) : 0.0;
            $out = [];
            foreach ($clicks as $angle => $list) {
                if (count($list) >= 3 && $average > 0) {
                    $out[$angle] = round(max(0.7, min(1.5, (array_sum($list) / count($list)) / $average)), 2);
                }
            }

            return $out;
        });
    }

    /** A search phrased as a question (Turkish or English). */
    public static function isQuestion(string $query): bool
    {
        return (bool) preg_match('/\b(ne|neden|nasil|nasıl|nedir|kac|kaç|hangi|mi|mı|mu|mü|mudur|midir|zararli|zararlı|olur|yapilir|yapılır|what|how|why|when|which|can|does|is)\b|\?$/u', mb_strtolower($query));
    }

    /**
     * The site page that already answers a search: its title or path holds every word of the search longer than two
     * letters (at least two such words).
     *
     * @param  Collection<int, Page>  $sitePages
     */
    private function pageFor(string $query, Collection $sitePages): ?Page
    {
        $words = array_values(array_filter(explode(' ', SeoText::fold($query)), fn (string $w): bool => mb_strlen($w) > 2));
        if (count($words) < 2) {
            return null;
        }

        return $sitePages->first(function (Page $p) use ($words): bool {
            $text = SeoText::fold((string) $p->title.' '.str_replace(['-', '/'], ' ', (string) $p->path));
            foreach ($words as $word) {
                if (! str_contains($text, $word)) {
                    return false;
                }
            }

            return true;
        });
    }

    /**
     * Folded search text => cluster id, for the given clusters.
     *
     * @param  list<string>  $texts
     * @param  list<int>  $clusterIds
     * @return array<string, int>
     */
    private function queryClusters(array $texts, array $clusterIds): array
    {
        if ($texts === [] || $clusterIds === []) {
            return [];
        }
        $wanted = collect($texts)->mapWithKeys(fn (string $t): array => [SeoText::fold($t) => true])->all();
        $out = [];
        DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds)
            ->whereIn(DB::raw('lower(q.text)'), array_map(fn (string $t): string => mb_strtolower($t), $texts))->get(['cq.cluster_id', 'q.text'])
            ->each(function (object $r) use (&$out, $wanted): void {
                $folded = SeoText::fold((string) $r->text);
                if (isset($wanted[$folded])) {
                    $out[$folded] ??= (int) $r->cluster_id;
                }
            });

        return $out;
    }

    /**
     * DATA_JSON of one writing round: the brand, the month, the chosen candidates (with what a rule found wrong in a
     * previous title of the same candidate), earlier titles and the site pages.
     *
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<int, array{rejected_title: string, why: string}>  $notes
     * @param  Collection<int, Suggestion>  $previous
     * @param  Collection<int, Page>  $sitePages
     * @return array<string, mixed>
     */
    private function pack(Brand $brand, array $candidates, array $notes, Collection $previous, Collection $sitePages, string $main): array
    {
        $clusterIds = collect($candidates)->map(fn (array $c): ?int => $c['row']?->cluster_id !== null ? (int) $c['row']->cluster_id : null)->filter()->unique()->values()->all();

        return [
            'brand' => $this->memory->contextFor($brand, [], $clusterIds)['profile'],
            'language' => $main,
            'month' => self::MONTHS[(int) now()->month].' '.now()->year,
            'brand_facts' => array_intersect_key(app(BrandFacts::class)->forPrompt($brand), array_flip(['questions', 'objections', 'praise', 'experts', 'seasonality', 'voice'])),
            'candidates' => array_map(function (array $c) use ($brand, $notes): array {
                $row = $c['row'];

                return array_filter([
                    'candidate_id' => $c['id'], 'kind' => $c['kind'], 'source' => $c['source'], 'query' => $c['query'], 'angle_hint' => $c['angle_hint'],
                    'cluster' => $row?->cluster?->name, 'service' => (string) ($row?->cluster?->service?->primaryName?->raw_label ?? ''),
                    'queries' => $c['queries'], 'gaps' => $row !== null ? array_column((array) $row->gaps, 'text') : [],
                    'ai_questions' => $row?->cluster !== null ? ClusterAudit::aiQuestions($row->cluster, $brand) : [],
                    'service_areas' => $row?->cluster !== null ? ClusterAudit::serviceAreas($row->cluster, $brand) : [],
                    'page_url' => $c['page']?->url, 'page_title' => $c['page']?->title,
                    'evidence' => array_column($c['evidence'], 'value'),
                    'previous_attempt' => $notes[$c['id']] ?? null,
                ], fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);
            }, $candidates),
            'previous_titles' => $previous->take(150)->pluck('title')->values()->all(),
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title, 'category' => $p->category])->values()->all(),
        ];
    }

    /**
     * Stores the AI's idea for one candidate, or says in plain Turkish why it was not stored.
     *
     * @param  array<string, mixed>  $candidate
     * @param  array<string, mixed>  $item
     * @param  Collection<int, Page>  $sitePages
     * @param  list<string>  $taken
     */
    private function storeCandidate(Brand $brand, DigitalAsset $site, Collection $sitePages, array $candidate, array $item, array &$taken, ?string $language, ?int $promptVersion): ?string
    {
        $title = trim((string) ($item['title'] ?? ''));
        if (($style = self::styleProblem($title)) !== null) {
            return $style === 'çok uzun' ? 'başlık çok uzun' : 'kalıp başlık ('.$style.')';
        }
        $row = $candidate['row'];
        $page = $candidate['page'];
        $item['kind'] = $candidate['kind'];
        $item['target_url'] = $page?->url;
        $numbers = implode(' ', [(string) $candidate['query'], ...$candidate['queries'], ...array_column($candidate['evidence'], 'value'), (string) $row?->cluster?->name,
            ...($row !== null ? array_column((array) $row->gaps, 'text') : [])]);

        return $this->storeItem($brand, $site, $sitePages, $item, $taken, [
            'cluster_id' => $row?->cluster_id !== null ? (int) $row->cluster_id : null, 'out_of_cluster' => false, 'language' => $language,
            'angle' => in_array($item['angle'] ?? null, array_keys(self::ANGLES), true) ? $item['angle'] : ($candidate['angle_hint'] ?? null),
            'evidence' => $candidate['evidence'], 'service' => (string) ($row?->cluster?->service?->primaryName?->raw_label ?? ''),
            'candidate' => $candidate['key'], 'source' => $candidate['source'], 'query' => $candidate['query'], 'number_text' => $numbers,
            'priority' => $candidate['score'] >= 6 ? 1 : ($candidate['score'] >= 3 ? 2 : 3),
        ], $promptVersion);
    }

    /**
     * The site's own Search Console numbers per cluster (28 days).
     *
     * @return array<int, array{impressions: int, clicks: int, position: ?float}>
     */
    private function brandSearch(DigitalAsset $site): array
    {
        $out = [];
        foreach ($this->reader->clusters($site, 28) as $row) {
            $out[(int) $row['cluster_id']] = ['impressions' => (int) $row['impressions'], 'clicks' => (int) $row['clicks'], 'position' => $row['position']];
        }

        return $out;
    }

    /**
     * Queries the site is seen for but not near the top (position 4–20, at least 30 impressions in 28 days), most seen
     * first: the reader already searches this, the brand answers it weakly.
     *
     * @return list<array{query: string, impressions: int, clicks: int, position: ?float}>
     */
    private function strikingQueries(DigitalAsset $site): array
    {
        return collect($this->reader->queries($site, 28))
            ->filter(fn (array $q): bool => $q['position'] !== null && $q['position'] >= 4 && $q['position'] <= 20 && $q['impressions'] >= 30)
            ->sortByDesc('impressions')->take(self::STRIKING_QUERIES)
            ->map(fn (array $q): array => ['query' => (string) $q['query'], 'impressions' => (int) $q['impressions'], 'clicks' => (int) $q['clicks'], 'position' => $q['position']])
            ->values()->all();
    }

    /**
     * Services that bring the brand results on Google Ads / Meta (30 days): where content pays off first.
     *
     * @return list<array{service_id: int, service: string, channel: string, result_type: string, results: float}>
     */
    private function paidResults(Brand $brand): array
    {
        if (! Schema::hasTable('ad_service_stats')) {
            return [];
        }
        $rows = DB::table('ad_service_stats')->where('brand_id', $brand->id)->whereNotNull('service_id')->where('results', '>', 0)->orderByDesc('results')->limit(20)
            ->get(['service_id', 'channel', 'result_type', 'results']);
        $names = MetaDesk::serviceNames($rows->pluck('service_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());

        return $rows->map(fn (object $r): array => ['service_id' => (int) $r->service_id, 'service' => $names[(int) $r->service_id] ?? '', 'channel' => (string) $r->channel,
            'result_type' => (string) $r->result_type, 'results' => (float) $r->results])->all();
    }

    /** @return array<int, int> cluster id => impressions of its visible queries in the query library */
    private function clusterVolumes(array $clusterIds): array
    {
        return DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds ?: [0])->where('q.hidden', false)
            ->groupBy('cq.cluster_id')->selectRaw('cq.cluster_id, sum(q.impressions) as volume')->pluck('volume', 'cluster_id')
            ->mapWithKeys(fn ($v, $id): array => [(int) $id => (int) $v])->all();
    }

    /** @return array{status: string, added: int} */
    public function discover(DigitalAsset $site): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'added' => 0];
        }
        $services = SiteScope::offerings($brand)->filter(fn (BrandOffering $o): bool => $o->service_catalog_item_id !== null)
            ->mapWithKeys(fn (BrandOffering $o): array => [(int) $o->service_catalog_item_id => $o->displayName()]);
        $queries = DB::table('brand_queries as bq')->join('queries as q', 'q.id', '=', 'bq.query_id')
            ->where('bq.brand_id', $brand->id)->whereNull('bq.target_area_id')->where('q.hidden', false)->whereNotIn('q.id', ClusterQuery::query()->select('query_id'))
            ->orderByDesc('bq.impressions_28d')->limit(150)->get(['q.id', 'q.text', 'q.service_id', 'bq.impressions_28d', 'bq.clicks_28d']);
        if ($queries->isEmpty()) {
            return ['status' => 'no_queries', 'added' => 0];
        }
        $clusterNames = Cluster::query()->where('sector_id', $brand->sector_id)->whereIn('service_id', $services->keys())->orderBy('id')->limit(200)->pluck('name')->all();
        $sitePages = $this->sitePages($site);
        $result = $this->ai->run(new ContentDiscoveryAgent, [
            'brand' => $this->memory->contextFor($brand, [], [])['profile'],
            'services' => $services->map(fn (string $name, int $id): array => ['id' => $id, 'name' => $name])->values()->all(),
            'existing_clusters' => $clusterNames,
            'queries' => $queries->map(fn (object $q): array => ['id' => (int) $q->id, 'text' => (string) $q->text, 'impressions_28d' => (int) $q->impressions_28d, 'clicks_28d' => (int) $q->clicks_28d])->all(),
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values()->all(),
        ], 240);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status'], 'added' => 0];
        }
        $known = $queries->pluck('text', 'id')->all();
        $taken = Suggestion::query()->where('brand_id', $brand->id)->where('action_type', SiteSuggestionTypes::CONTENT)->pluck('title')->map(fn ($t): string => SeoText::fold((string) $t))->all();
        $added = 0;
        foreach (array_slice((array) ($result['data']['items'] ?? []), 0, 10) as $item) {
            if (! is_array($item)) {
                continue;
            }
            $ids = array_values(array_unique(array_filter((array) ($item['query_ids'] ?? []), fn ($id): bool => is_int($id) && isset($known[$id]))));
            $newQueries = array_values(array_slice(array_filter(array_map(fn ($t): string => mb_substr(trim((string) $t), 0, 200), (array) ($item['new_queries'] ?? [])), fn (string $t): bool => mb_strlen($t) >= 3), 0, 5));
            if ($ids === []) {
                continue; // an opportunity must rest on at least one real query of the brand
            }
            $serviceId = is_int($item['service_id'] ?? null) && $services->has($item['service_id']) ? $item['service_id'] : null;
            $stored = $this->storeItem($brand, $site, $sitePages, $item + ['kind' => 'new', 'target_url' => null], $taken, [
                'cluster_id' => null, 'out_of_cluster' => true, 'query_ids' => $ids, 'new_queries' => $newQueries, 'service_id' => $serviceId,
                'evidence' => array_map(fn (int $id): array => ['kind' => 'query', 'value' => (string) $known[$id], 'source' => 'Search Console'], array_slice($ids, 0, 5)),
                'service' => $serviceId !== null ? (string) $services->get($serviceId) : '',
            ], $result['prompt_version_id']) === null;
            $added += $stored ? 1 : 0;
        }

        return ['status' => 'ready', 'added' => $added];
    }

    /**
     * "Kütüphaneye ekle": a discovered opportunity becomes a (not yet approved) cluster of the shared sector + service
     * library with its real queries and the new ones as "önerilen".
     */
    public function addToLibrary(Suggestion $suggestion): Cluster
    {
        $action = (array) $suggestion->action;
        $brand = Brand::query()->find($suggestion->brand_id);
        $serviceId = $action['service_id'] ?? null;
        if (! ($action['out_of_cluster'] ?? false) || ! is_int($serviceId) || $brand?->sector_id === null) {
            throw ValidationException::withMessages(['library' => 'Kütüphaneye eklemek için önerinin bir hizmeti olmalı.']);
        }
        if (is_int($action['library_cluster_id'] ?? null) && Cluster::query()->whereKey($action['library_cluster_id'])->exists()) {
            throw ValidationException::withMessages(['library' => 'Bu fırsat zaten kütüphanede.']);
        }

        return DB::transaction(function () use ($suggestion, $action, $brand, $serviceId): Cluster {
            $free = Query::query()->whereIn('id', (array) ($action['query_ids'] ?? []))->whereNotIn('id', ClusterQuery::query()->select('query_id'))->pluck('id')->map(fn ($id): int => (int) $id)->all();
            $pageType = (string) ($action['page_type'] ?? 'blog');
            $cluster = Cluster::query()->create([
                'sector_id' => $brand->sector_id, 'service_id' => $serviceId, 'name' => mb_substr((string) $suggestion->title, 0, 200),
                'intent' => in_array($pageType, ['blog', 'sss'], true) ? 'informational' : 'commercial', 'main_query_id' => $free[0] ?? null,
                'page_type' => self::CLUSTER_PAGE_TYPES[$pageType] ?? 'other', 'subtopics' => array_values(array_slice((array) ($action['outline'] ?? []), 0, 12)),
                'reasoning' => mb_substr((string) $suggestion->reason, 0, 1000), 'approved' => false, 'locked' => false,
            ]);
            $now = now();
            $members = array_map(fn (int $id): array => ['cluster_id' => $cluster->id, 'query_id' => $id, 'is_suggested' => false, 'created_at' => $now, 'updated_at' => $now], $free);
            $normalizer = app(QueryNormalizer::class);
            foreach ((array) ($action['new_queries'] ?? []) as $text) {
                $normalized = $normalizer->normalize((string) $text);
                $hash = QueryNormalizer::hash($normalized);
                if (mb_strlen($normalized) < 3 || Query::query()->where('text_hash', $hash)->exists()) {
                    continue;
                }
                $query = Query::query()->create(['text' => $normalized, 'text_hash' => $hash, 'sector_id' => $brand->sector_id, 'service_id' => $serviceId, 'assignment' => 'ai', 'is_suggested' => true]);
                $members[] = ['cluster_id' => $cluster->id, 'query_id' => $query->id, 'is_suggested' => true, 'created_at' => $now, 'updated_at' => $now];
            }
            ClusterQuery::query()->insert($members);
            $suggestion->forceFill(['action' => array_merge($action, ['library_cluster_id' => $cluster->id])])->save();

            return $cluster;
        });
    }

    /**
     * Rakipler "Yeni içerik olarak ekle": a competitor suggestion without a page of ours becomes an İçerik plan item
     * (new page of the cluster's page type, target URL from the site's URL pattern); the competitor suggestion is
     * approved and points to it. Idempotent.
     */
    public function fromCompetitor(Suggestion $competitor, User $user): Suggestion
    {
        if ($competitor->action_type !== SiteSuggestionTypes::COMPETITOR || $competitor->page_id !== null) {
            throw ValidationException::withMessages(['content' => 'Yalnız sayfası olmayan rakip önerisi yeni içerik olur.']);
        }
        $existing = is_int(data_get($competitor->action, 'content_suggestion_id')) ? Suggestion::query()->find(data_get($competitor->action, 'content_suggestion_id')) : null;
        if ($existing !== null) {
            return $existing;
        }
        $site = DigitalAsset::query()->find((int) data_get($competitor->evidence, 'website_asset_id'));
        $brand = Brand::query()->find($competitor->brand_id);
        if ($site === null || $brand === null) {
            throw ValidationException::withMessages(['content' => 'Site bulunamadı.']);
        }
        $cluster = $competitor->cluster_id !== null ? Cluster::query()->with('service.primaryName')->find($competitor->cluster_id) : null;
        $pageType = array_search((string) $cluster?->page_type, self::CLUSTER_PAGE_TYPES, true) ?: 'blog';
        $folded = SeoText::fold((string) $competitor->title);
        $sitePages = $this->sitePages($site);
        $pattern = new SiteUrlPattern($sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'path' => (string) $p->path, 'cms_type' => $p->wp_post_type])->all());
        $target = $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify((string) $competitor->title), (string) ($cluster?->service?->primaryName?->raw_label ?? ''));

        return DB::transaction(function () use ($competitor, $user, $brand, $site, $cluster, $pageType, $folded, $target): Suggestion {
            $content = Suggestion::query()->firstOrCreate(['brand_id' => $brand->id, 'fingerprint' => hash('sha256', implode('|', [$brand->id, 'content', $folded]))], [
                'channel' => 'search', 'decision_key' => 'site.content', 'material_hash' => hash('sha256', $folded),
                'title' => $competitor->title, 'reason' => $competitor->reason, 'priority' => $competitor->priority ?? 3,
                'evidence' => array_map(fn (string $url): array => ['kind' => 'url', 'value' => $url, 'source' => 'rakip'], array_slice((array) data_get($competitor->evidence, 'competitor_urls', []), 0, 5)),
                'action_type' => SiteSuggestionTypes::CONTENT, 'target_type' => 'site', 'target_id' => $site->id, 'page_id' => null, 'cluster_id' => $cluster?->id,
                'prompt_version_id' => $competitor->prompt_version_id, 'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(),
                'action' => ['site_id' => (int) $site->id, 'kind' => 'new', 'page_type' => $pageType, 'target_url' => $target,
                    'outline' => array_values(array_filter((array) ($cluster?->subtopics ?? []), 'is_string')), 'questions' => [], 'out_of_cluster' => false,
                    'from_suggestion_id' => (int) $competitor->id, 'week' => now()->format('o-\WW')],
            ]);
            $competitor->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(),
                'action' => array_merge((array) $competitor->action, ['content_suggestion_id' => (int) $content->id])])->save();
            $this->memory->recordDecision($competitor, 'onaylandı', 'yeni içerik');

            return $content;
        });
    }

    /**
     * İçerik fikirleri "AI ile üret" (blueprint §5.7): an idea row without a page becomes an İçerik plan item (title,
     * page type, outline, AI questions, target URL from the site's URL pattern; an extra idea also carries its angle,
     * target queries and the main idea's page to link to; the stored SEO analizi recipe goes along) and its article
     * is written at once. Review and the WordPress draft (ADR-064) stay in the İçerik tab. Idempotent per title.
     *
     * @return array{status: string, suggestion_id?: int, message?: string}
     */
    public function produce(ContentIdeaSubject $subject): array
    {
        if ($subject->row->page_id !== null) {
            return ['status' => 'has_page'];
        }
        $brand = $subject->brand();
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational'];
        }
        $site = $subject->site;
        $title = $subject->title();
        $folded = SeoText::fold($title);
        $pageType = array_search($subject->type(), self::CLUSTER_PAGE_TYPES, true) ?: 'blog';
        $sitePages = $this->sitePages($site);
        $pattern = new SiteUrlPattern($sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'path' => (string) $p->path, 'cms_type' => $p->wp_post_type])->all());
        $outline = $subject->idea !== null ? array_values((array) $subject->idea->outline) : array_values(array_filter((array) $subject->cluster->subtopics, 'is_string'));
        $suggestion = Suggestion::query()->firstOrCreate(['brand_id' => $brand->id, 'fingerprint' => hash('sha256', implode('|', [$brand->id, 'content', $folded]))], [
            'channel' => 'search', 'decision_key' => 'site.content', 'material_hash' => hash('sha256', $folded),
            'title' => mb_substr($title, 0, 160), 'reason' => mb_substr((string) ($subject->idea?->angle ?? $subject->cluster->user_need ?? ''), 0, 240), 'priority' => 2,
            'evidence' => [['kind' => 'cluster', 'value' => $subject->cluster->name.' · Sayfa yok', 'source' => 'İçerik fikirleri']],
            'action_type' => SiteSuggestionTypes::CONTENT, 'target_type' => 'site', 'target_id' => $site->id, 'page_id' => null, 'cluster_id' => $subject->cluster->id,
            'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => ['site_id' => (int) $site->id, 'kind' => 'new', 'page_type' => $pageType,
                'target_url' => $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify(ForbiddenTerms::forBrand($brand)->scrub($title)), (string) ($subject->cluster->service?->primaryName?->raw_label ?? '')),
                'outline' => array_slice($outline, 0, 12), 'questions' => array_slice(ClusterAudit::aiQuestions($subject->cluster, $brand), 0, 10), 'out_of_cluster' => false, 'week' => now()->format('o-\WW')],
        ]);
        $suggestion->forceFill(['action' => array_merge((array) $suggestion->action, array_filter([
            'angle' => $subject->idea?->angle, 'target_queries' => $subject->idea !== null ? array_column((array) $subject->idea->target_queries, 'text') : null,
            'main_page_url' => $subject->mainPage()?->url, 'recipe' => data_get($subject->row->recipe, 'steps') ?: null,
            'recipe_seo' => array_filter(['seo_title' => data_get($subject->row->recipe, 'seo_title'), 'meta_description' => data_get($subject->row->recipe, 'meta_description')]) ?: null,
        ], fn (mixed $v): bool => $v !== null && $v !== []) + $subject->params())])->save();

        return ['suggestion_id' => (int) $suggestion->id] + $this->writeArticle($suggestion);
    }

    /**
     * "Taslak hazırla": the article (validated, compliance-checked) is stored on the suggestion for review. With a
     * language of the site other than the written article's, the same plan is written in that language as a
     * translation (`action.translations.{lang}`), sent with the source as linked Polylang drafts (ADR-076).
     *
     * @return array{status: string, message?: string}
     */
    public function writeArticle(Suggestion $suggestion, ?string $language = null): array
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        $brand = Brand::query()->with('customer', 'sectorCategory')->find($suggestion->brand_id);
        if ($suggestion->action_type !== SiteSuggestionTypes::CONTENT || $site === null) {
            return ['status' => 'not_applicable'];
        }
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational'];
        }
        $sitePages = $this->sitePages($site);
        $cluster = $suggestion->cluster_id !== null ? Cluster::query()->with(['mainQuery', 'clusterQueries.searchQuery'])->find($suggestion->cluster_id) : null;
        $context = $this->memory->contextFor($brand, $suggestion->page_id !== null ? [(int) $suggestion->page_id] : [], $cluster !== null ? [(int) $cluster->id] : []);
        $sourceLanguage = self::articleLanguage($suggestion, $site);
        $language = $language !== null && in_array($language, self::siteLanguages($site), true) ? $language : $sourceLanguage;
        $translation = $language !== $sourceLanguage && is_array($action['article'] ?? null);
        if ($translation) {
            return $this->translate($suggestion, $site, $brand, $cluster, $sitePages, $language);
        }
        $terms = ForbiddenTerms::forBrand($brand);
        // Every input the writer copies loses its forbidden phrases first ("En iyi … seçerken" → "… seçerken"); repeated lines go once.
        $scrub = fn (array $lines): array => array_values(array_unique(array_filter(array_map(fn ($line): string => $terms->scrub((string) $line), $lines), fn (string $l): bool => $l !== '')));
        $text = fn (mixed $value): ?string => ($clean = $terms->scrub(trim((string) $value))) !== '' ? $clean : null;
        $questions = $scrub((array) ($action['questions'] ?? []));
        $angle = $text($action['angle'] ?? null);
        $reason = $text($suggestion->reason);
        $recipe = isset($action['recipe']) ? $scrub(array_map(fn (array $s): string => trim(($s['where'] ?? '') !== '' ? $s['where'].': '.$s['action'] : (string) ($s['action'] ?? '')), (array) $action['recipe'])) : [];
        $recipeSeo = (array) ($action['recipe_seo'] ?? []);
        $input = [
            'plan' => ['title' => $terms->scrub((string) $suggestion->title), 'page_type' => $action['page_type'] ?? 'blog', 'outline' => $scrub((array) ($action['outline'] ?? [])),
                'questions' => $questions, 'target_url' => $action['target_url'] ?? null, 'kind' => ($action['kind'] ?? null) === 'update' ? 'update' : 'new']
                + array_filter(['reason' => $reason !== $angle ? $reason : null, 'angle' => $angle, 'target_queries' => $scrub((array) ($action['target_queries'] ?? [])), 'main_page_url' => $action['main_page_url'] ?? null,
                    'recipe' => $recipe, 'seo_title' => $text($recipeSeo['seo_title'] ?? null), 'meta_description' => $text($recipeSeo['meta_description'] ?? null)],
                    fn (mixed $v): bool => $v !== null && $v !== []),
            'cluster' => $cluster !== null ? array_filter(['name' => $cluster->name, 'main_query' => $text($cluster->mainQuery?->text), 'subtopics' => $scrub(array_filter((array) $cluster->subtopics, 'is_string')),
                'queries' => $scrub($cluster->clusterQueries->filter(fn (ClusterQuery $q): bool => $q->searchQuery !== null && ! $q->searchQuery->hidden)
                    ->sortByDesc(fn (ClusterQuery $q): int => (int) $q->searchQuery->impressions)->map(fn (ClusterQuery $q): string => (string) $q->searchQuery->text)->take(30)->all()),
                // Questions already in the plan are not sent twice.
                'ai_questions' => array_values(array_diff($scrub(ClusterAudit::aiQuestions($cluster, $brand)), $questions)), 'service_areas' => ClusterAudit::serviceAreas($cluster, $brand) ?: null,
                'benchmarks' => app(ClusterBenchmarks::class)->for($cluster, (int) $brand->id) ?: null],
                fn (mixed $v): bool => $v !== null && $v !== []) : null,
            'brand' => $context['profile'], 'notes' => $context['notes'], 'standards' => $context['standards'], 'related_pages' => [...$context['pages'], ...$context['related_pages']],
            'language' => $language,
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values()->all(),
            'forbidden' => $terms->phrases(),
        ];
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        foreach ($context['pages'] as $page) {
            $evidence->addNumbersFrom(['s' => $page['summary'], 'f' => $page['facts']]);
        }
        unset($action['article'], $action['article_blocked'], $action['article_blocked_draft']);
        $action['language'] = $language;
        $outcome = $this->writeWithFix($input, $evidence, $action, $cluster, $brand, $language, 'suggestion-'.$suggestion->id, 'article-'.$suggestion->id);
        if ($outcome['status'] === 'invalid' || $outcome['status'] !== 'ready' && ! isset($outcome['article'])) {
            return array_intersect_key($outcome, ['status' => 1, 'message' => 1]);
        }
        $article = $outcome['article'];
        if ($outcome['status'] === 'blocked') {
            // Kept to read and fix by hand; never sent while blocked.
            $suggestion->forceFill(['action' => $action + ['article_blocked' => $outcome['message'], 'article_blocked_draft' => $article]])->save();

            return ['status' => 'blocked', 'message' => $outcome['message']];
        }
        $warnings = $terms->warnings(implode(' . ', [$article['title'], $article['meta_title'], $article['meta_description'], strip_tags($article['html'])]));
        $action['article_warnings'] = $warnings !== [] ? 'Uyarı (yasaklı ifade, uyar): «'.implode('», «', $warnings).'»' : null;
        $action['article_seo'] = ArticleSeoCheck::check($article, $input['cluster']['main_query'] ?? null, (array) ($input['cluster']['service_areas'] ?? []), SiteScope::origin($site));
        $others = array_values(array_diff(self::siteLanguages($site), [$language]));
        $translate = ($action['kind'] ?? null) !== 'update' ? $others : [];
        unset($action['translations'], $action['translations_blocked']);
        $suggestion->forceFill(['action' => array_merge($action, ['article' => $article,
            'article_note' => match (true) {
                $translate !== [] => 'Sitenin diğer dillerine ('.implode(', ', array_map('strtoupper', $translate)).') bu yazının çevirisi hazırlanıyor.',
                $others !== [] => 'Sitede başka dil de var ('.implode(', ', $others).'): Genel işler › içerik kutusundan o dile çevrilebilir.',
                default => null,
            }])])->save();
        // yakup, 2026-10-07: other languages get no ideas of their own; the written article is translated into them.
        foreach ($translate as $other) {
            SiteOperations::dispatch((int) $site->id, SiteOperations::WRITE_ARTICLE, ['suggestion_id' => (int) $suggestion->id, 'language' => $other]);
        }

        return ['status' => 'ready'];
    }

    /**
     * The other-language version of a written article: a faithful translation of it (same headings, facts and links),
     * never a new article. Stored under `action.translations.{lang}` (or `translations_blocked` when it breaks a sector
     * rule); only that key is written, under a row lock, so translations of several languages can finish together.
     *
     * @param  Collection<int, Page>  $sitePages
     * @return array{status: string, message?: string}
     */
    private function translate(Suggestion $suggestion, DigitalAsset $site, Brand $brand, ?Cluster $cluster, Collection $sitePages, string $language): array
    {
        $source = (array) data_get($suggestion->action, 'article');
        $terms = ForbiddenTerms::forBrand($brand);
        $input = [
            'translate_from' => array_intersect_key($source, array_flip(['title', 'meta_title', 'meta_description', 'excerpt', 'html'])) + ['language' => (string) ($source['language'] ?? self::articleLanguage($suggestion, $site))],
            'language' => $language,
            'site_pages' => $sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'title' => $p->title])->values()->all(),
            'forbidden' => $terms->phrases(),
        ];
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all());
        $evidence->addNumbersFrom(['t' => (string) ($source['title'] ?? ''), 'h' => strip_tags((string) ($source['html'] ?? ''))]);
        $outcome = $this->writeWithFix($input, $evidence, ['kind' => data_get($suggestion->action, 'kind'), 'page_type' => data_get($suggestion->action, 'page_type')],
            $cluster, $brand, $language, 'suggestion-'.$suggestion->id.'-'.$language, 'translate-'.$suggestion->id.'-'.$language);
        if ($outcome['status'] === 'invalid' || $outcome['status'] !== 'ready' && ! isset($outcome['article'])) {
            return array_intersect_key($outcome, ['status' => 1, 'message' => 1]);
        }
        $blocked = $outcome['status'] === 'blocked';
        DB::transaction(function () use ($suggestion, $language, $outcome, $blocked): void {
            $fresh = Suggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
            $action = (array) $fresh->action;
            unset($action['translations'][$language], $action['translations_blocked'][$language]);
            $key = $blocked ? 'translations_blocked' : 'translations';
            $action[$key] = array_merge((array) ($action[$key] ?? []), [$language => $blocked ? $outcome['message'] : $outcome['article']]);
            $fresh->forceFill(['action' => $action])->save();
            $suggestion->setRawAttributes($fresh->getAttributes(), true);
        });

        return $blocked ? ['status' => 'blocked', 'message' => (string) $outcome['message']] : ['status' => 'ready'];
    }

    /**
     * At most two writes: when the first one breaks a sector rule, the second gets the offending phrases to rewrite.
     * Each call is named ($slot + attempt) so a run that waits for Claude gets the same answer back even when the
     * site's pages or notes changed meanwhile.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $action
     * @return array{status: string, message?: string, article?: array<string, mixed>, violations: list<array<string, mixed>>}
     */
    private function writeWithFix(array $input, SiteEvidence $evidence, array $action, ?Cluster $cluster, Brand $brand, string $language, string $reference, string $slot): array
    {
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $outcome = $this->writeOnce($input, $evidence, $action, $cluster, $brand, $language, $reference, $slot.'-'.$attempt);
            if ($outcome['status'] !== 'blocked' || $outcome['violations'] === [] || $attempt === 2) {
                break;
            }
            $input['fix'] = ContentComplianceGate::forPrompt($outcome['violations']);
        }

        return $outcome;
    }

    /** The language the article of this idea is (or will be) written in: the operator's pick, else the site's main language. */
    public static function articleLanguage(Suggestion $suggestion, DigitalAsset $site): string
    {
        $picked = data_get($suggestion->action, 'language');

        return is_string($picked) && $picked !== '' ? $picked : (SiteScope::primaryLanguage($site) ?? 'tr');
    }

    /** @return list<string> the languages the site's pages use (its main language first), plus the asset's own setting */
    public static function siteLanguages(DigitalAsset $site): array
    {
        $primary = SiteScope::primaryLanguage($site) ?? 'tr';
        $declared = array_map(fn ($l): string => strtolower(substr((string) $l, 0, 2)), array_filter((array) ($site->languages ?? []), 'is_string'));

        return array_values(array_unique([$primary, ...SiteScope::languages($site), ...$declared]));
    }

    /**
     * One write: the agent's article, grounded and checked (sector rules, copy check).
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $action
     * @return array{status: string, message?: string, article?: array<string, mixed>, violations: list<array<string, mixed>>}
     */
    private function writeOnce(array $input, SiteEvidence $evidence, array $action, ?Cluster $cluster, Brand $brand, string $language, string $reference, ?string $slot = null): array
    {
        $result = $this->ai->run(new WriteArticleAgent, $input, 600, $slot);
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status'], 'violations' => []];
        }
        $data = $result['data'];
        $html = $this->groundedHtml((string) ($data['html'] ?? ''), $evidence);
        $main = (string) ($action['main_page_url'] ?? '');
        if ($html !== '' && $main !== '' && ! str_contains($html, 'href="'.$main.'"') && ! str_contains($html, "href='".$main."'")) {
            // An extra idea always links to its main idea's page (blueprint §4.1).
            $html .= '<p>İlgili: <a href="'.e($main).'">'.e((string) ($cluster?->name ?? $main)).'</a></p>';
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($html === '' || mb_strlen($title) < 5 || ! $evidence->grounded($title)) {
            return ['status' => 'invalid', 'message' => 'AI makalesi doğrulanamadı.', 'violations' => []];
        }
        $article = [
            'title' => mb_substr($title, 0, 200), 'slug' => SeoText::slugify((string) ($data['slug'] ?? $title)) ?: SeoText::slugify($title), 'html' => $html,
            // The approved SEO analysis title / description win over the writer's own.
            'meta_title' => mb_substr(trim((string) ($input['plan']['seo_title'] ?? $data['meta_title'] ?? '')), 0, 120),
            'meta_description' => mb_substr(trim((string) ($input['plan']['meta_description'] ?? $data['meta_description'] ?? '')), 0, 320),
            'excerpt' => mb_substr(trim((string) ($data['excerpt'] ?? '')), 0, 300), 'language' => $language,
            // A new article is always a post (operator decision 2026-10-03); only rewriting an existing non-blog page stays a page.
            'post_type' => ($action['kind'] ?? null) === 'update' && ($action['page_type'] ?? 'blog') !== 'blog' ? 'page' : 'post', 'reference' => $reference,
        ];
        $violations = ContentComplianceGate::blocking(app(ContentComplianceGate::class)->violations($brand, ArticleDraft::fromArray($article)));
        if ($violations !== []) {
            return ['status' => 'blocked', 'message' => ContentComplianceGate::summary($violations), 'article' => $article, 'violations' => $violations];
        }
        $copy = $cluster !== null ? CopyCheck::check($html, ClusterBenchmarks::otherBrandTexts((int) $cluster->id, (int) $brand->id)) : ['ok' => true];
        if (! $copy['ok']) {
            return ['status' => 'blocked', 'message' => CopyCheck::message($copy), 'article' => $article, 'violations' => []];
        }

        return ['status' => 'ready', 'article' => $article, 'violations' => []];
    }

    /**
     * Admin approval: the prepared article goes to WordPress as a draft (existing rich draft path, undoable), with its
     * written translations as linked drafts (ADR-076). A translation written after the source was sent goes on its own.
     */
    public function sendDraft(Suggestion $suggestion, User $user): int
    {
        $action = (array) $suggestion->action;
        $site = DigitalAsset::query()->find($action['site_id'] ?? null);
        if (! is_array($action['article'] ?? null) || $site === null) {
            throw ValidationException::withMessages(['write' => 'Önce "Taslak hazırla".']);
        }
        $sent = array_values((array) ($action['sent_languages'] ?? []));
        $pending = array_filter((array) ($action['translations'] ?? []), fn ($article, $lang): bool => is_array($article) && ! in_array($lang, $sent, true), ARRAY_FILTER_USE_BOTH);
        $drafts = array_map(fn (array $article): ArticleDraft => ArticleDraft::fromArray($article), array_values($pending));
        if (! isset($action['article_write_id'])) {
            $source = ArticleDraft::fromArray($action['article']);
            $write = app(ExternalWriteService::class)->requestArticleDrafts($user, $site, $source, $drafts);
            $action = array_merge($action, ['article_write_id' => $write->id, 'sent_languages' => [$source->language ?? self::articleLanguage($suggestion, $site), ...array_keys($pending)]]);
        } elseif ($drafts !== []) {
            $write = app(ExternalWriteService::class)->requestArticleDrafts($user, $site, $drafts[0], array_slice($drafts, 1));
            $action = array_merge($action, ['sent_languages' => [...$sent, ...array_keys($pending)],
                'translation_write_ids' => [...(array) ($action['translation_write_ids'] ?? []), (int) $write->id]]);
        } else {
            throw ValidationException::withMessages(['write' => 'Bu yazı zaten gönderildi; gönderilecek yeni dil yok.']);
        }
        $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'action' => $action])->save();
        $this->memory->recordDecision($suggestion, 'onaylandı', 'WordPress taslağı');

        return (int) $write->id;
    }

    /**
     * @param  Collection<int, Page>  $sitePages
     * @param  array<string, mixed>  $item
     * @param  list<string>  $taken  folded titles already planned
     * @param  array<string, mixed>  $extra  number_text: data the idea's numbers may come from (its queries, evidence)
     * @return string|null why it was not stored (plain Turkish), null when stored
     */
    private function storeItem(Brand $brand, DigitalAsset $site, Collection $sitePages, array $item, array &$taken, array $extra, ?int $promptVersionId): ?string
    {
        $compliance = BriefCompliance::forBrand($brand);
        preg_match_all('/\d+(?:[.,]\d+)?/u', (string) ($extra['number_text'] ?? ''), $dataNumbers);
        $evidence = new SiteEvidence($sitePages->pluck('url')->map(fn ($u): string => (string) $u)->all(), $dataNumbers[0]);
        $title = trim((string) ($item['title'] ?? ''));
        $folded = SeoText::fold($title);
        $pageType = in_array($item['page_type'] ?? null, self::PAGE_TYPES, true) ? $item['page_type'] : 'blog';
        $problem = match (true) {
            mb_strlen($title) < 5 => 'başlık boş',
            in_array($folded, $taken, true) => 'aynı başlık zaten var',
            ! $compliance->isCompliant($title) => 'sektörde yasaklı ifade',
            ! $evidence->grounded($title) => 'veride olmayan sayı ya da adres',
            default => null,
        };
        if ($problem !== null) {
            return $problem;
        }
        $lines = fn (mixed $list, int $max): array => array_values(array_slice($compliance->filter(array_values(array_filter(array_map(fn ($l): string => mb_substr(trim((string) $l), 0, 200), (array) $list),
            fn (string $l): bool => $l !== '' && $evidence->grounded($l)))), 0, $max));
        $kind = ($item['kind'] ?? 'new') === 'update' ? 'update' : 'new';
        $target = null;
        $pageId = null;
        if ($kind === 'update') {
            $page = $sitePages->first(fn (Page $p): bool => SeoText::urlKey((string) $p->url) === SeoText::urlKey((string) ($item['target_url'] ?? '')));
            if ($page === null) {
                return 'güncellenecek sayfa sitede yok'; // an update must name a real page of the site
            }
            [$target, $pageId] = [(string) $page->url, (int) $page->id];
        } else {
            $pattern = new SiteUrlPattern($sitePages->map(fn (Page $p): array => ['url' => (string) $p->url, 'path' => (string) $p->path, 'cms_type' => $p->wp_post_type])->all());
            $target = $pattern->targetUrl(SiteScope::origin($site), self::URL_TYPES[$pageType], SeoText::slugify($title), (string) ($extra['service'] ?? ''));
        }
        $reason = trim((string) ($item['reason'] ?? ''));
        $fingerprint = hash('sha256', implode('|', [$brand->id, 'content', $folded]));
        if (Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->exists()) {
            return 'aynı başlık zaten var';
        }
        Suggestion::query()->create([
            'brand_id' => $brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => $fingerprint, 'material_hash' => hash('sha256', $folded),
            'title' => mb_substr($title, 0, 160), 'reason' => mb_substr($evidence->grounded($reason) ? $reason : '', 0, 240), 'priority' => (int) ($extra['priority'] ?? 3),
            'evidence' => $extra['evidence'] ?? [['kind' => 'none', 'value' => 'veri yok', 'source' => '']],
            'action_type' => SiteSuggestionTypes::CONTENT, 'target_type' => $pageId !== null ? 'page' : 'site', 'target_id' => $pageId ?? $site->id,
            'page_id' => $pageId, 'cluster_id' => $extra['cluster_id'] ?? null, 'prompt_version_id' => $promptVersionId, 'status' => Suggestion::OPEN,
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => array_filter([
                'site_id' => (int) $site->id, 'kind' => $kind, 'page_type' => $pageType, 'target_url' => $target,
                'outline' => $lines($item['outline'] ?? [], 12), 'questions' => $lines($item['questions'] ?? [], 10),
                'out_of_cluster' => (bool) ($extra['out_of_cluster'] ?? false), 'query_ids' => $extra['query_ids'] ?? null, 'new_queries' => $extra['new_queries'] ?? null,
                'service_id' => $extra['service_id'] ?? null, 'week' => now()->format('o-\WW'),
                'language' => $extra['language'] ?? null, 'angle' => $extra['angle'] ?? null,
                'candidate' => $extra['candidate'] ?? null, 'source' => $extra['source'] ?? null, 'query' => $extra['query'] ?? null,
            ], fn (mixed $v): bool => $v !== null),
        ]);
        $taken[] = $folded;

        return null;
    }

    /**
     * The real searches of each cluster, most impressions first (what the article must answer).
     *
     * @param  list<int>  $clusterIds
     * @return array<int, list<string>>
     */
    private function clusterQueries(array $clusterIds, int $per = 8): array
    {
        $out = [];
        DB::table('cluster_queries as cq')->join('queries as q', 'q.id', '=', 'cq.query_id')->whereIn('cq.cluster_id', $clusterIds ?: [0])->where('q.hidden', false)
            ->orderBy('cq.cluster_id')->orderByDesc('q.impressions')->orderBy('q.id')->get(['cq.cluster_id', 'q.text'])
            ->each(function (object $row) use (&$out, $per): void {
                if (count($out[(int) $row->cluster_id] ?? []) < $per) {
                    $out[(int) $row->cluster_id][] = (string) $row->text;
                }
            });

        return $out;
    }

    /** @return Collection<int, Page> */
    private function sitePages(DigitalAsset $site): Collection
    {
        return Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->orderBy('path')->limit(300)->get(['id', 'url', 'path', 'title', 'category', 'wp_post_type']);
    }

    /** Article HTML with scripts removed, links only to site pages, and blocks with invented numbers dropped. */
    private function groundedHtml(string $html, SiteEvidence $evidence): string
    {
        $html = (string) preg_replace('#<(script|style|iframe)\b[^>]*>.*?</\1>#is', '', $html);
        $html = (string) preg_replace_callback('#<a\b[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', fn (array $m): string => $evidence->knowsUrl($m[1]) ? $m[0] : $m[2], $html);
        $html = (string) preg_replace_callback('#<(p|li|h[2-6]|td)\b[^>]*>.*?</\1>#is', fn (array $m): string => $evidence->grounded(strip_tags($m[0])) ? $m[0] : '', $html);

        return trim(strip_tags($html)) === '' ? '' : trim($html);
    }
}
