<?php

namespace App\Services\Repair;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\PageTechnical;
use App\Services\Site\SiteMetrics;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Web onarımı (Onarım Faz 3): every indexable WordPress page of every site is checked, without AI, for a missing,
 * too short, too long or duplicate SEO title and meta description. Each affected page gets one "seo_fields" suggestion
 * (prepared overnight by "AI ile yap", approved on the Onarım masası); the suggestion closes by itself when the page
 * no longer has the problem. Pages that answer an error, are noindex or canonicalised elsewhere are left out.
 *
 * Priority (yakup, 2026-10-11 — "6686 kritik öneri" was noise): 1 only for a missing / duplicate TITLE on a page that
 * had Search Console impressions in the last 90 days or is a service / location page (else 2); title length 4;
 * missing / duplicate description 3, description length 4; a description issue on a page without impressions in 90
 * days (Search Console connected) 5. A description the plugin leaves empty or as a template but still renders in the
 * HTML (stored crawl) counts as present.
 *
 * No row without expected gain (yakup, 2026-10-11 — "Arama 10672" on one small site was noise): when the site has
 * Search Console data, a page that had no impressions in 90 days gets no row unless it is a service / location page;
 * WordPress by-products (pagination such as /page/2/, tag / author archives — SeoText::isCrawlablePage) are not checked
 * at all. Such pages are not junk and get no noindex / delete advice; they are simply not worth a title fix. Their
 * waiting rows close as "auto dismissed" and come back by themselves when the page starts being seen.
 */
final class SiteAudit
{
    public const string TYPE = 'seo_fields';

    public const int TITLE_MIN = 25;

    public const int TITLE_MAX = 60;

    public const int DESCRIPTION_MIN = 70;

    public const int DESCRIPTION_MAX = 160;

    /** Search Console look-back of the "is this page seen" test, in days. */
    public const int IMPRESSION_DAYS = 90;

    /** Page categories whose missing / duplicate title is critical even without impressions. */
    public const array KEY_CATEGORIES = ['hizmet', 'lokasyon'];

    /** `action.auto_closed` of a row the audit closed itself because fixing the page brings nothing (reopens when it does). */
    public const string NO_GAIN = 'no_gain';

    public const string NOT_A_PAGE = 'not_a_page';

    public function __construct(private readonly SiteMetrics $metrics) {}

    /** @return array{sites: int, opened: int, closed: int} */
    public function run(): array
    {
        $done = ['sites' => 0, 'opened' => 0, 'closed' => 0];
        DigitalAsset::query()->operational()->where('type', 'website')->orderBy('id')->each(function (DigitalAsset $site) use (&$done): void {
            try {
                $result = $this->audit($site);
            } catch (Throwable $e) {
                report($e);

                return;
            }
            $done['sites']++;
            $done['opened'] += $result['opened'];
            $done['closed'] += $result['closed'];
        });

        return $done;
    }

    /** @return array{opened: int, closed: int} */
    public function audit(DigitalAsset $site): array
    {
        $pages = Page::query()->where('website_asset_id', $site->id)->where('is_indexable', true)->whereNotNull('wp_post_id')
            ->get(['id', 'website_asset_id', 'url', 'path', 'language', 'category', 'title', 'title_source', 'meta_description', 'canonical', 'is_indexable', 'wp_post_id', 'content_hash']);
        // Pagination and archive by-products share their title on purpose; they are not pages to fix (nor junk).
        $byProducts = $pages->reject(fn (Page $p): bool => SeoText::isCrawlablePage((string) $p->url))->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $pages = $pages->filter(fn (Page $p): bool => SeoText::isCrawlablePage((string) $p->url))->values();
        $technical = PageTechnical::many($pages);
        $pages = $pages->filter(fn (Page $p): bool => ($technical[$p->id]['status'] ?? 200) < 400 && ($technical[$p->id]['canonical_ok'] ?? true))->values();
        $rendered = RenderedMeta::descriptions((int) $site->id, $pages->filter(fn (Page $p): bool => trim((string) $p->meta_description) === '')->pluck('url')->all());
        $descriptions = $pages->mapWithKeys(fn (Page $p): array => [(int) $p->id => trim((string) $p->meta_description) !== ''
            ? trim((string) $p->meta_description) : ($rendered[rtrim((string) $p->url, '/')] ?? '')])->all();
        $impressions = $this->impressions($site);
        $titleCounts = $this->counts($pages, fn (Page $p): string => trim((string) $p->title));
        $descriptionCounts = $this->counts($pages, fn (Page $p): string => $descriptions[(int) $p->id]);

        $existing = Suggestion::query()->where('brand_id', $site->brand_id)->where('action_type', self::TYPE)
            ->whereIn('page_id', Page::query()->where('website_asset_id', $site->id)->select('id'))->get()->keyBy('page_id');
        $opened = 0;
        $seen = [];
        $noGain = [];
        foreach ($pages as $page) {
            $description = $descriptions[(int) $page->id];
            $problems = $this->problems($page, $description, $titleCounts, $descriptionCounts);
            if ($problems === []) {
                continue;
            }
            $seenImpressions = $impressions === null ? null : ($impressions[SeoText::urlKey((string) $page->url)] ?? 0);
            if (! self::worthFixing($seenImpressions, (string) $page->category)) {
                $noGain[] = (int) $page->id;

                continue;
            }
            $seen[] = (int) $page->id;
            $priority = self::priority($problems, $seenImpressions, (string) $page->category);
            $opened += $this->upsert($site, $page, $description, $problems, $priority, $existing->get($page->id)) ? 1 : 0;
        }
        $waiting = $existing->filter(fn (Suggestion $s): bool => ! in_array((int) $s->page_id, $seen, true)
            && in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED], true));
        $closed = 0;
        foreach ([self::NO_GAIN => $noGain, self::NOT_A_PAGE => $byProducts] as $why => $pageIds) {
            foreach ($waiting->filter(fn (Suggestion $s): bool => in_array((int) $s->page_id, $pageIds, true)) as $suggestion) {
                $suggestion->forceFill(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'action' => array_merge((array) $suggestion->action, ['auto_closed' => $why]),
                    'operator_note' => $why === self::NO_GAIN ? 'Sayfa 90 günde aramada görünmedi; düzeltmenin beklenen kazancı yok (otomatik kapandı, sayfa görünmeye başlarsa geri gelir).'
                        : 'Sayfalama / arşiv adresi; düzeltilecek sayfa değil (otomatik kapandı).'])->save();
                $closed++;
            }
            $waiting = $waiting->reject(fn (Suggestion $s): bool => in_array((int) $s->page_id, $pageIds, true));
        }
        $closed += Suggestion::query()->whereIn('id', $waiting->pluck('id'))
            ->update(['status' => Suggestion::APPLIED, 'verification' => Suggestion::VERIFY_AUTO, 'verified_at' => now(), 'resolved_at' => now(),
                'operator_note' => 'Sorun sayfada kalmadı (otomatik kapandı).']);

        return ['opened' => $opened, 'closed' => $closed];
    }

    /**
     * Whether a page's title / description fix can bring anything: always without Search Console data and on a service
     * or location page; otherwise only when the page had impressions in the last 90 days.
     */
    public static function worthFixing(?int $impressions, ?string $category): bool
    {
        return $impressions === null || $impressions > 0 || in_array((string) $category, self::KEY_CATEGORIES, true);
    }

    /**
     * @param  array<string, int>  $titleCounts
     * @param  array<string, int>  $descriptionCounts
     * @return list<array{field: string, code: string, text: string}>
     */
    private function problems(Page $page, string $description, array $titleCounts, array $descriptionCounts): array
    {
        $problems = [];
        $title = trim((string) $page->title);
        $titleLength = mb_strlen($title);
        $descriptionLength = mb_strlen($description);
        $titleKey = self::key($page, $title);
        $descriptionKey = self::key($page, $description);
        // A post title is what the SEO plugin's template ("%title% | Site name") wraps: Google shows it longer, so its
        // length says nothing about the title in search results.
        $lengthKnown = $page->title_source !== 'post';
        $problems[] = match (true) {
            $title === '' => ['field' => 'seo_title', 'code' => 'missing', 'text' => 'SEO başlığı yok'],
            ($titleCounts[$titleKey] ?? 0) > 1 => ['field' => 'seo_title', 'code' => 'duplicate', 'text' => 'Başlık '.$titleCounts[$titleKey].' sayfada aynı'],
            $lengthKnown && $titleLength > self::TITLE_MAX => ['field' => 'seo_title', 'code' => 'long', 'text' => 'Başlık '.$titleLength.' karakter (en çok '.self::TITLE_MAX.')'],
            $lengthKnown && $titleLength < self::TITLE_MIN => ['field' => 'seo_title', 'code' => 'short', 'text' => 'Başlık '.$titleLength.' karakter (en az '.self::TITLE_MIN.')'],
            default => null,
        };
        $problems[] = match (true) {
            $description === '' => ['field' => 'meta_description', 'code' => 'missing', 'text' => 'Meta açıklama yok'],
            ($descriptionCounts[$descriptionKey] ?? 0) > 1 => ['field' => 'meta_description', 'code' => 'duplicate', 'text' => 'Açıklama '.$descriptionCounts[$descriptionKey].' sayfada aynı'],
            $descriptionLength > self::DESCRIPTION_MAX => ['field' => 'meta_description', 'code' => 'long', 'text' => 'Açıklama '.$descriptionLength.' karakter (en çok '.self::DESCRIPTION_MAX.')'],
            $descriptionLength < self::DESCRIPTION_MIN => ['field' => 'meta_description', 'code' => 'short', 'text' => 'Açıklama '.$descriptionLength.' karakter (en az '.self::DESCRIPTION_MIN.')'],
            default => null,
        };

        return array_values(array_filter($problems));
    }

    /**
     * The suggestion priority of a page's problems (the most urgent one wins).
     *
     * @param  list<array{field: string, code: string, text?: string}>|list<string>  $problems  problems or "field:code" strings
     * @param  ?int  $impressions  Search Console impressions in 90 days; null when the site has no Search Console data
     */
    public static function priority(array $problems, ?int $impressions, ?string $category): int
    {
        $seen = $impressions === null || $impressions > 0;
        $priorities = [];
        foreach ($problems as $problem) {
            [$field, $code] = is_string($problem) ? array_pad(explode(':', $problem, 2), 2, '') : [$problem['field'], $problem['code']];
            $severe = in_array($code, ['missing', 'duplicate'], true);
            $priorities[] = match (true) {
                $field === 'seo_title' && $severe => ($impressions !== null && $impressions > 0) || in_array((string) $category, self::KEY_CATEGORIES, true) ? 1 : 2,
                $field === 'seo_title' => 4,
                ! $seen => 5,
                $severe => 3,
                default => 4,
            };
        }

        return $priorities === [] ? 4 : min($priorities);
    }

    /**
     * Re-prioritises the waiting (open, recheck, snoozed) title / description fixes by the current rules; approved and
     * applied rows are never touched. Returns how many rows changed.
     */
    public function reprioritizeWaiting(): int
    {
        $waiting = [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED];
        $changed = 0;
        $sites = Page::query()->whereIn('id', Suggestion::query()->where('action_type', self::TYPE)->whereIn('status', $waiting)->whereNotNull('page_id')->select('page_id'))
            ->get(['id', 'website_asset_id', 'url', 'category'])->groupBy('website_asset_id');
        foreach ($sites as $siteId => $pages) {
            $site = DigitalAsset::query()->find($siteId);
            $impressions = $site !== null ? $this->impressions($site) : null;
            $pages = $pages->keyBy('id');
            Suggestion::query()->where('action_type', self::TYPE)->whereIn('status', $waiting)->whereIn('page_id', $pages->keys()->all())
                ->get(['id', 'page_id', 'priority', 'action'])
                ->each(function (Suggestion $suggestion) use ($pages, $impressions, &$changed): void {
                    $page = $pages->get($suggestion->page_id);
                    $problems = array_values(array_filter((array) (((array) $suggestion->action)['problems'] ?? []), 'is_string'));
                    if ($page === null || $problems === []) {
                        return;
                    }
                    $seen = $impressions === null ? null : ($impressions[SeoText::urlKey((string) $page->url)] ?? 0);
                    $priority = self::priority($problems, $seen, (string) $page->category);
                    if ((int) $suggestion->priority !== $priority) {
                        Suggestion::query()->whereKey($suggestion->id)->update(['priority' => $priority]);
                        $changed++;
                    }
                });
        }

        return $changed;
    }

    /**
     * Search Console impressions of the site's pages in the 90 days up to the last collected day (key: URL key), or
     * null when the brand has no Search Console data.
     *
     * @return array<string, int>|null
     */
    private function impressions(DigitalAsset $site): ?array
    {
        $brand = $site->brand;
        if (! $brand instanceof Brand) {
            return null;
        }
        $last = $this->metrics->lastGscDay($brand);
        if ($last === null) {
            return null;
        }
        $totals = $this->metrics->pageTotals($brand, $site, [$last->subDays(self::IMPRESSION_DAYS - 1)->toDateString(), $last->toDateString()]);

        return array_map(fn (array $row): int => (int) $row['impressions'], $totals);
    }

    /**
     * @param  list<array{field: string, code: string, text: string}>  $problems
     */
    private function upsert(DigitalAsset $site, Page $page, string $description, array $problems, int $priority, ?Suggestion $existing): bool
    {
        $codes = array_map(fn (array $p): string => $p['field'].':'.$p['code'], $problems);
        $material = hash('sha256', implode('|', $codes).'|'.SeoText::fold((string) $page->title).'|'.SeoText::fold($description));
        $reason = implode('; ', array_column($problems, 'text')).'.';
        $values = [
            'channel' => 'search', 'decision_key' => 'repair.'.self::TYPE, 'title' => mb_substr('Başlık ve açıklamayı düzelt: '.($page->path ?: $page->url), 0, 160),
            'reason' => mb_substr($reason, 0, 240), 'priority' => $priority, 'action_type' => self::TYPE, 'target_type' => 'page', 'target_id' => $page->id,
            'page_id' => $page->id, 'material_hash' => $material, 'last_seen_at' => now(),
            'evidence' => [['kind' => 'quote', 'value' => mb_substr((string) $page->title, 0, 200) ?: 'başlık yok', 'source' => (string) $page->url]],
        ];
        if ($existing === null) {
            Suggestion::openOnce($values + [
                'brand_id' => $site->brand_id, 'fingerprint' => hash('sha256', $site->brand_id.'|repair|'.$page->id.'|'.self::TYPE),
                'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'action' => ['site_id' => (int) $site->id, 'problems' => $codes],
            ]);

            return true;
        }
        $action = (array) $existing->action;
        $autoClosed = $existing->status === Suggestion::DISMISSED && isset($action['auto_closed']);
        if (($existing->status === Suggestion::DISMISSED && ! $autoClosed) || ($existing->status === Suggestion::APPLIED && $existing->material_hash === $material)) {
            return false;
        }
        unset($action['auto_closed']);
        $changed = $existing->material_hash !== $material;
        if ($changed) {
            // The page changed: an old proposal no longer fits.
            unset($action['proposal'], $action['proposal_blocked'], $action['proposal_attempts'], $action['proposal_error'], $action['writes']);
        }
        $reopen = $existing->status === Suggestion::APPLIED || $autoClosed;
        $existing->forceFill($values + ['action' => array_merge($action, ['problems' => $codes])]
            + ($reopen ? ['status' => Suggestion::OPEN, 'applied_at' => null, 'resolved_at' => null, 'operator_note' => null, 'verification' => null, 'verified_at' => null] : []))->save();

        return $reopen;
    }

    /**
     * @param  Collection<int, Page>  $pages
     * @param  callable(Page): string  $value
     * @return array<string, int> language + folded value => pages carrying it
     */
    private function counts(Collection $pages, callable $value): array
    {
        return $pages->map(fn (Page $p): string => self::key($p, $value($p)))->filter(fn (string $v): bool => $v !== '')->countBy()->all();
    }

    /** A title / description compared within its language: a TR page and its EN translation are not duplicates. */
    private static function key(Page $page, string $value): string
    {
        $folded = SeoText::fold($value);

        return $folded === '' ? '' : mb_strtolower((string) $page->language).'|'.$folded;
    }
}
