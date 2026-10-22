<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\SiteScore;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Repair\RepairDesk;
use App\Services\Repair\SiteAudit;
use App\Services\Repair\WebHealthAudit;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Web sitesi sağlığı (Aşama 7, 2026-10-22): every open finding of a site in four layers — teknik (index, sitemap,
 * links, speed, headers), SEO (titles, descriptions, image alts, internal links), AEO (AI answer engines: robots.txt,
 * llms.txt, business schema, question-answer sections) and kurgu (service pages that are too short, pages competing for
 * one subject, the conversion path, missing topics). Each layer is worth 25 points; its open findings take points off
 * (a share of the pages for per-page findings, a fixed amount by priority for site-wide ones). The score is stored each
 * night and read next to the sector's median and leader (never their names). Each problem type gets one "Hepsini onar":
 * the desk rows of the type that the system can write go through the desk's approval (Akış, verification after it).
 */
final class SiteHealthScore
{
    public const array LAYERS = ['teknik' => 'Teknik', 'seo' => 'SEO', 'aeo' => 'AI cevap motorları', 'kurgu' => 'Kurgu'];

    public const array LAYER_HINTS = [
        'teknik' => 'Google sayfaları bulabiliyor, açabiliyor ve dizine alabiliyor mu.',
        'seo' => 'Google\'da görünen başlık, açıklama, görsel açıklaması ve iç bağlantılar.',
        'aeo' => 'ChatGPT, Claude, Perplexity ve Google AI siteyi okuyup alıntılayabiliyor mu.',
        'kurgu' => 'Hizmet sayfaları yeterli mi, aynı konuda yarışan sayfa var mı, ziyaretçi talebe ulaşıyor mu.',
    ];

    public const int LAYER_POINTS = 25;

    /** Service and location pages under this many words are "ana sayfayı güçlendir" work. */
    public const int THIN_WORDS = 150;

    public const array SERVICE_CATEGORIES = ['hizmet', 'lokasyon'];

    /** Findings that are still the operator's or the system's to do. */
    public const array OPEN = [Suggestion::OPEN, Suggestion::RECHECK];

    /** Per-page finding types => [layer, weight of the share of pages it touches]. */
    private const array PER_PAGE = [
        SiteAudit::TYPE => ['seo', 0.7],
        ImageAlts::TYPE => ['seo', 0.3],
        'thin' => ['kurgu', 0.5],
        'faq' => ['aeo', 0.25],
    ];

    /** AEO findings: crawlers shut out weigh most. */
    private const array AEO_PENALTY = ['web_health.aeo.robots' => 0.35, 'web_health.aeo.llms' => 0.2, 'web_health.aeo.schema' => 0.2];

    private const array AEO_TITLES = ['robots' => 'AI tarayıcıları engelli', 'llms' => 'llms.txt yok', 'schema' => 'İşletme bilgisi (schema) yok'];

    /** Site-wide findings take this much of their layer by priority (1 = critical). */
    private const array PRIORITY_PENALTY = [1 => 0.2, 2 => 0.12, 3 => 0.06];

    private const array LAYER_OF_TYPE = [
        'wrong_intent' => 'kurgu', 'missing_topic' => 'kurgu', 'service_location' => 'kurgu', 'conversion' => 'kurgu', 'duplicate_content' => 'kurgu',
        ClusterOverlaps::TYPE => 'kurgu', 'internal_links' => 'seo', 'title_description' => 'seo', 'technical_seo' => 'aeo', 'translation_fix' => 'seo',
    ];

    /** Finding types that are not site problems (content ideas, competitors, visitor recordings). */
    private const array NOT_PROBLEMS = ['content', 'rakip', 'clarity'];

    public function __construct(private readonly RepairDesk $desk) {}

    public static function isThin(?Page $page): bool
    {
        return $page !== null && in_array((string) $page->category, self::SERVICE_CATEGORIES, true)
            && (int) $page->word_count > 0 && (int) $page->word_count < self::THIN_WORDS;
    }

    /**
     * The site's problem types, worst layer first: how many findings, which layer, and how they get fixed — `fix`
     * "desk" (rows the system writes after approval; `ids` are their desk ids), "manual" (the operator does it on the
     * site; the night closes it), "content" (the content plan puts the page first) or "info".
     *
     * @return list<array{key: string, layer: string, title: string, count: int, priority: int, fix: string, ids: list<int>, pages: list<string>}>
     */
    public function problems(DigitalAsset $site): array
    {
        $out = [];
        $pages = $this->pages($site);
        $rows = $this->findings($site, $pages);
        $deskIds = $this->writableDeskIds($site);
        foreach ($rows->groupBy(fn (Suggestion $s): string => self::keyOf($s)) as $key => $group) {
            $first = $group->first();
            $writable = $group->pluck('id')->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => isset($deskIds[$id]))->values()->all();
            $out[] = [
                'key' => (string) $key,
                'layer' => self::layerOf($first),
                'title' => self::titleOf($first),
                'count' => $group->count(),
                'priority' => (int) $group->min('priority'),
                'fix' => $writable !== [] ? 'desk' : (self::isManual($first) ? 'manual' : 'info'),
                'ids' => $writable,
                'pages' => $group->map(fn (Suggestion $s): string => (string) ($s->page?->path ?? data_get($s->action, 'target', '')))->filter()->unique()->take(5)->values()->all(),
            ];
        }
        $service = $pages->filter(fn (Page $p): bool => in_array((string) $p->category, self::SERVICE_CATEGORIES, true));
        $thin = $service->filter(fn (Page $p): bool => self::isThin($p));
        if ($thin->isNotEmpty()) {
            $out[] = ['key' => 'thin', 'layer' => 'kurgu', 'title' => 'Kısa hizmet sayfaları ('.self::THIN_WORDS.' kelimeden az)', 'count' => $thin->count(), 'priority' => 2,
                'fix' => 'content', 'ids' => [], 'pages' => $thin->sortBy('word_count')->take(5)->map(fn (Page $p): string => (string) $p->path.' · '.(int) $p->word_count.' kelime')->values()->all()];
        }
        $noQuestions = $service->filter(fn (Page $p): bool => (int) $p->word_count > 0 && ! self::answersQuestions($p));
        if ($service->count() >= 3 && $noQuestions->isNotEmpty()) {
            $out[] = ['key' => 'faq', 'layer' => 'aeo', 'title' => 'Soru-cevap bölümü olmayan hizmet sayfaları', 'count' => $noQuestions->count(), 'priority' => 3,
                'fix' => 'content', 'ids' => [], 'pages' => $noQuestions->take(5)->map(fn (Page $p): string => (string) $p->path)->values()->all()];
        }
        usort($out, fn (array $a, array $b): int => [$a['priority'], -$a['count']] <=> [$b['priority'], -$b['count']]);

        return $out;
    }

    /**
     * Scores the site from its problems (four layers of LAYER_POINTS).
     *
     * @param  list<array<string, mixed>>|null  $problems
     * @return array{score: int, layers: array<string, int>, pages: int}
     */
    public function score(DigitalAsset $site, ?array $problems = null): array
    {
        $problems ??= $this->problems($site);
        $pages = $this->pages($site);
        $indexable = max(1, $pages->count());
        $service = max(1, $pages->filter(fn (Page $p): bool => in_array((string) $p->category, self::SERVICE_CATEGORIES, true))->count());
        $penalty = array_fill_keys(array_keys(self::LAYERS), 0.0);
        foreach ($problems as $problem) {
            $type = explode('.', $problem['key'])[0];
            if (isset(self::PER_PAGE[$type])) {
                [, $weight] = self::PER_PAGE[$type];
                $base = in_array($type, ['thin', 'faq'], true) ? $service : $indexable;
                $penalty[$problem['layer']] += $weight * min(1.0, $problem['count'] / $base);
            } elseif (str_starts_with($problem['key'], WebHealthAudit::TYPE.'.aeo')) {
                $penalty['aeo'] += self::AEO_PENALTY[$problem['key']] ?? 0.2;
            } elseif ($type === WebHealthAudit::TYPE) {
                $penalty[$problem['layer']] += (self::PRIORITY_PENALTY[$problem['priority']] ?? 0.03) * min(3, $problem['count']);
            } else {
                $penalty[$problem['layer']] += min(0.3, 0.03 * $problem['count']);
            }
        }
        $layers = [];
        foreach ($penalty as $layer => $p) {
            $layers[$layer] = (int) round(self::LAYER_POINTS * max(0.0, 1 - min(1.0, $p)));
        }

        return ['score' => array_sum($layers), 'layers' => $layers, 'pages' => $pages->count()];
    }

    /** The night's score of one site, stored once a day (Istanbul). */
    public function store(DigitalAsset $site): ?SiteScore
    {
        if (! SchemaState::hasTable('site_scores') || $site->brand_id === null) {
            return null;
        }
        $problems = $this->problems($site);
        $score = $this->score($site, $problems);

        $day = CarbonImmutable::now('Europe/Istanbul')->toDateString();
        $row = SiteScore::query()->where('digital_asset_id', $site->id)->whereDate('day', $day)->first() ?? new SiteScore(['digital_asset_id' => $site->id, 'day' => $day]);
        $row->fill(['brand_id' => $site->brand_id, 'score' => $score['score'], 'layers' => $score['layers'], 'pages' => $score['pages'],
            'problems' => array_map(fn (array $p): array => ['key' => $p['key'], 'layer' => $p['layer'], 'count' => $p['count']], $problems)])->save();

        return $row;
    }

    /** @return array{sites: int} every serviced website scored */
    public function storeAll(): array
    {
        $done = 0;
        DigitalAsset::query()->operational()->where('type', 'website')->whereNotNull('brand_id')->orderBy('id')->each(function (DigitalAsset $site) use (&$done): void {
            if (! BrandlessSites::serviced($site)) {
                return;
            }
            try {
                $this->store($site) !== null && $done++;
            } catch (Throwable $error) {
                report($error);
            }
        });

        return ['sites' => $done];
    }

    /** @return Collection<int, SiteScore> the site's scores of the last $days nights, oldest first */
    public function history(DigitalAsset $site, int $days = 56): Collection
    {
        if (! SchemaState::hasTable('site_scores')) {
            return collect();
        }

        return SiteScore::query()->where('digital_asset_id', $site->id)->where('day', '>=', now()->subDays($days)->toDateString())->orderBy('day')->get();
    }

    /**
     * The sector's median and leader (latest score of each site of a brand in the same sector, last 7 nights). Other
     * brands are never named.
     *
     * @return array{median: ?int, leader: ?int, sites: int, rank: ?int}
     */
    public function sector(DigitalAsset $site): array
    {
        $empty = ['median' => null, 'leader' => null, 'sites' => 0, 'rank' => null];
        $sectorId = Brand::query()->whereKey($site->brand_id)->value('sector_id');
        if ($sectorId === null || ! SchemaState::hasTable('site_scores')) {
            return $empty;
        }
        $brands = Brand::query()->operational()->where('sector_id', $sectorId)->pluck('id');
        $latest = SiteScore::query()->whereIn('brand_id', $brands)->where('day', '>=', now()->subDays(7)->toDateString())
            ->orderByDesc('day')->get(['digital_asset_id', 'score'])->unique('digital_asset_id');
        if ($latest->count() < 2) {
            return $empty;
        }
        $scores = $latest->pluck('score')->map(fn ($s): int => (int) $s)->sort()->values();
        $n = $scores->count();
        $median = $n % 2 === 1 ? $scores[intdiv($n, 2)] : (int) round(($scores[$n / 2 - 1] + $scores[$n / 2]) / 2);
        $own = $latest->firstWhere('digital_asset_id', $site->id)?->score;

        return ['median' => $median, 'leader' => (int) $scores->last(), 'sites' => $n,
            'rank' => $own === null ? null : 1 + $scores->filter(fn (int $s): bool => $s > (int) $own)->count()];
    }

    /**
     * "Son 60 günde düzeltilen 46 sayfa: 30 gün sonra +120 tık" — the site's page fixes whose 30-day result is
     * measured, and the clicks they gained (after − before, Search Console). Null before the first measurement.
     *
     * @return array{pages: int, clicks: int, sentence: string}|null
     */
    public function result(DigitalAsset $site, int $days = 60): ?array
    {
        $fixed = Suggestion::query()->where('brand_id', $site->brand_id)->where('status', Suggestion::APPLIED)->where('channel', 'search')
            ->whereNotIn('action_type', self::NOT_PROBLEMS)->whereNotNull('page_id')->whereIn('page_id', Page::query()->where('website_asset_id', $site->id)->select('id'))
            ->where('applied_at', '>=', now()->subDays($days + 30))->get(['id', 'page_id', 'outcome']);
        $measured = $fixed->filter(fn (Suggestion $s): bool => is_numeric(data_get($s->outcome, 'd30.clicks')));
        if ($measured->isEmpty()) {
            return null;
        }
        $clicks = (int) round($measured->unique('page_id')->sum(fn (Suggestion $s): float => (float) data_get($s->outcome, 'd30.clicks') - (float) data_get($s->outcome, 'd30.before.clicks', 0)));
        $pages = $measured->unique('page_id')->count();

        return ['pages' => $pages, 'clicks' => $clicks, 'sentence' => 'Son '.$days.' günde düzeltilen '.number_format($pages, 0, ',', '.').' sayfa: 30 gün sonra '
            .($clicks >= 0 ? '+' : '').number_format($clicks, 0, ',', '.').' tık.'];
    }

    /**
     * "Hepsini onar" of one problem type: its writable desk rows go through the desk's approval. Returns how many went.
     *
     * @return array{applied: int}
     */
    public function fixAll(DigitalAsset $site, string $key, User $user): array
    {
        $problem = collect($this->problems($site))->firstWhere('key', $key);
        $ids = (array) ($problem['ids'] ?? []);
        if ($ids === []) {
            return ['applied' => 0];
        }
        $result = $this->desk->queueApproval($ids, $user);

        return ['applied' => (int) ($result['applied'] ?? 0)];
    }

    /** @return Collection<int, Page> the site's indexable pages in its main language */
    private function pages(DigitalAsset $site): Collection
    {
        $language = SiteScope::primaryLanguage($site);

        return Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)
            ->when($language !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('language')->orWhere('language', $language)))
            ->get(['id', 'path', 'category', 'word_count', 'headings', 'language']);
    }

    /** @return Collection<int, Suggestion> open findings of the site */
    private function findings(DigitalAsset $site, Collection $pages): Collection
    {
        return Suggestion::query()->with('page:id,path')->where('brand_id', $site->brand_id)->where('channel', 'search')->whereIn('status', self::OPEN)
            ->whereNotIn('action_type', self::NOT_PROBLEMS)
            ->where(fn ($q) => $q->where(fn ($t) => $t->where('target_type', 'asset')->where('target_id', $site->id))
                ->orWhereIn('page_id', Page::query()->where('website_asset_id', $site->id)->select('id')))
            ->get(['id', 'action_type', 'action', 'priority', 'page_id', 'title']);
    }

    /** @return array<int, true> desk rows of the site the system writes after approval */
    private function writableDeskIds(DigitalAsset $site): array
    {
        try {
            return $this->desk->rows((int) $site->brand_id)
                ->filter(fn (array $r): bool => (int) ($r['asset_id'] ?? 0) === (int) $site->id && RepairDesk::lane($r) !== RepairDesk::LANE_MANUAL && $r['kind'] !== RepairDesk::WEB_TASK)
                ->pluck('id')->mapWithKeys(fn ($id): array => [(int) $id => true])->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    private static function keyOf(Suggestion $s): string
    {
        if ($s->action_type !== WebHealthAudit::TYPE) {
            return (string) $s->action_type;
        }
        $check = (string) data_get($s->action, 'check', 'other');

        return WebHealthAudit::TYPE.'.'.$check.($check === 'aeo' ? '.'.self::aeoPart($s) : '');
    }

    /** robots | llms | schema, from the address the AEO finding is about. */
    private static function aeoPart(Suggestion $s): string
    {
        $target = (string) data_get($s->action, 'target', '');

        return match (true) {
            str_ends_with($target, '/robots.txt') => 'robots',
            str_ends_with($target, '/llms.txt') => 'llms',
            default => 'schema',
        };
    }

    private static function layerOf(Suggestion $s): string
    {
        if ($s->action_type === WebHealthAudit::TYPE) {
            return match ((string) data_get($s->action, 'check')) {
                'aeo' => 'aeo',
                'conversion' => 'kurgu',
                default => 'teknik',
            };
        }

        return self::PER_PAGE[(string) $s->action_type][0] ?? self::LAYER_OF_TYPE[(string) $s->action_type] ?? 'seo';
    }

    private static function titleOf(Suggestion $s): string
    {
        if ($s->action_type === WebHealthAudit::TYPE) {
            $check = (string) data_get($s->action, 'check');

            return $check === 'aeo' ? self::AEO_TITLES[self::aeoPart($s)] : (WebHealthAudit::CHECKS[$check] ?? 'Teknik sorun');
        }

        return SiteSuggestionTypes::label((string) $s->action_type);
    }

    private static function isManual(Suggestion $s): bool
    {
        return in_array((string) $s->action_type, SiteSuggestionTypes::MANUAL, true)
            || ($s->action_type === WebHealthAudit::TYPE && (array) data_get($s->action, 'changes', []) === []);
    }

    /** A heading that asks a question, or a "Sık sorulan sorular" section. */
    private static function answersQuestions(Page $page): bool
    {
        foreach ((array) $page->headings as $heading) {
            $text = trim((string) (is_array($heading) ? ($heading['text'] ?? '') : $heading));
            if (str_ends_with($text, '?') || preg_match('/s[ıi]k[cç]?a? sorulan|\bsss\b|\bfaq\b|frequently asked/iu', $text) === 1) {
                return true;
            }
        }

        return false;
    }
}
