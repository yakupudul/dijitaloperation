<?php

namespace App\Services\Repair;

use App\Ai\Agents\Site\SeoFieldsBatchAgent;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Brand\BrandPack;
use App\Services\Compliance\BriefCompliance;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteEvidence;
use App\Services\Site\SiteScope;
use App\Services\Website\Pages\MainContentExtractor;
use App\Services\Website\PublicDiscovery\StoredHtmlReader;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Toplu hazırlık (yakup, 2026-10-09: tens of thousands of pages without a description must not wait months): title /
 * description fixes of one site are prepared up to 5 pages of one language and page category per AI call
 * (`site.seo_fields_batch`), with the page's own searches. Each value is checked by rules (length, not the same as
 * another page of the site, about this page's own subject, no number the page does not have, sector phrases) before it
 * reaches the Onarım masası as a ready-to-approve row; nothing is written to the site before the operator approves it.
 *
 * yakup, 2026-10-11 (OPC: a dermal-filler Q&A page got a "dental fillings" description): mixed packs of 15 pages with
 * the brand's services made the AI borrow another subject. Pages are now packed by language + category, Q&A and blog
 * pages get no brand services, a thin page's text comes from the stored crawl, and a value that shares fewer than two
 * content words with the page's title / H1 / URL is refused. A row whose values fail 3 times is blocked with the reason
 * instead of being re-sent every hour.
 */
final class SeoFieldsBatch
{
    public const int PAGES_PER_CALL = 5;

    public const array TYPES = [SiteAudit::TYPE, 'title_description'];

    /** Failed preparations of a row before it is blocked (no more AI calls for it). */
    public const int MAX_ATTEMPTS = 3;

    /** A page with fewer words than this is "thin": the main text of its stored crawl HTML is used when longer. */
    public const int THIN_WORDS = 80;

    /** Page categories that get no brand service list (a Q&A / blog page is about its own question). */
    public const array NO_SERVICE_CATEGORIES = ['sss', 'blog'];

    /** Content words a value must share with the page's title / H1 / URL slug. */
    public const int GROUNDING_WORDS = 2;

    public function __construct(
        private readonly SiteAi $ai,
        private readonly StoredHtmlReader $html,
        private readonly MainContentExtractor $extractor,
    ) {}

    /**
     * @param  list<int>  $suggestionIds
     * @return array{status: string, prepared: int, skipped: int}
     */
    public function prepare(DigitalAsset $site, array $suggestionIds): array
    {
        $brand = SiteScope::brandOf($site);
        if (! SiteScope::aiAllowed($brand)) {
            return ['status' => 'not_operational', 'prepared' => 0, 'skipped' => 0];
        }
        $suggestions = Suggestion::query()->with('page')->whereIn('id', $suggestionIds)->where('brand_id', $brand->id)->whereIn('action_type', self::TYPES)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])->get()
            ->filter(fn (Suggestion $s): bool => $s->page !== null && (int) $s->page->website_asset_id === (int) $site->id && self::needsPreparing($s))
            ->groupBy(fn (Suggestion $s): string => self::packKey($s->page))->first()?->take(self::PAGES_PER_CALL)->values() ?? collect();
        if ($suggestions->isEmpty()) {
            return ['status' => 'ready', 'prepared' => 0, 'skipped' => 0];
        }
        $queries = $this->pageQueries($suggestions->map(fn (Suggestion $s): string => (string) $s->page->url)->all());
        $pack = $this->brandPack($brand, (string) $suggestions->first()->page->category);
        $texts = $this->texts($site, $suggestions);
        $result = $this->ai->run(new SeoFieldsBatchAgent, [
            'brand' => $pack,
            'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
            'pages' => $suggestions->map(fn (Suggestion $s): array => [
                'id' => (int) $s->id, 'url' => (string) $s->page->url, 'language' => (string) ($s->page->language ?: 'tr'),
                'category' => (string) ($s->page->category ?: 'diger'),
                'seo_title' => (string) $s->page->title, 'title_source' => $s->page->title_source === 'post' ? 'post' : 'seo',
                'meta_description' => (string) $s->page->meta_description, 'h1' => (string) $s->page->h1,
                'text' => mb_substr($texts[(int) $s->id], 0, 700), 'fix' => self::fix($s), 'queries' => $queries[(string) $s->page->url] ?? [],
            ])->all(),
        ], 240, 'batch-'.md5(implode(',', $suggestions->pluck('id')->all())));
        if ($result['status'] !== 'ready') {
            return ['status' => $result['status'], 'prepared' => 0, 'skipped' => 0];
        }

        $siteTitles = Page::query()->where('website_asset_id', $site->id)->whereNotIn('id', $suggestions->pluck('page_id'))->pluck('title')
            ->map(fn ($t): string => SeoText::fold((string) $t))->filter()->flip()->all();
        $siteDescriptions = Page::query()->where('website_asset_id', $site->id)->whereNotIn('id', $suggestions->pluck('page_id'))->pluck('meta_description')
            ->map(fn ($t): string => SeoText::fold((string) $t))->filter()->flip()->all();
        $compliance = BriefCompliance::forBrand($brand);
        $forbidden = ForbiddenTerms::forBrand($brand);
        $answers = collect((array) ($result['data']['pages'] ?? []))->filter(fn ($p): bool => is_array($p) && is_int($p['id'] ?? null))->keyBy('id');
        $names = ['brand' => self::words($pack['name']), 'areas' => self::words(implode(' ', $pack['areas']))];
        $prepared = 0;
        foreach ($suggestions as $suggestion) {
            $answer = $answers->get($suggestion->id);
            [$new, $refused] = $answer === null ? [[], ['AI bu sayfa için değer vermedi']]
                : $this->checked($suggestion, (array) $answer, $texts[(int) $suggestion->id], $siteTitles, $siteDescriptions, $compliance, $forbidden, $names);
            if ($new === []) {
                $this->recordFailure($suggestion, $refused);

                continue;
            }
            $page = $suggestion->page;
            $action = (array) $suggestion->action;
            unset($action['proposal_blocked'], $action['proposal_warnings'], $action['proposal_attempts'], $action['proposal_error']);
            $action['proposal'] = [
                'kind' => 'fields',
                'current' => array_filter(['seo_title' => $page->title, 'meta_description' => $page->meta_description], fn ($v): bool => $v !== null),
                'new' => $new, 'note' => '', 'prepared_at' => now()->toIso8601String(), 'prompt_version_id' => $result['prompt_version_id'], 'batch' => true,
            ];
            $suggestion->forceFill(['action' => $action])->save();
            $prepared++;
        }

        return ['status' => 'ready', 'prepared' => $prepared, 'skipped' => $suggestions->count() - $prepared];
    }

    /**
     * A row whose values were all refused: the attempt is counted with the reason; after MAX_ATTEMPTS the row is
     * blocked (no more AI calls) and waits for the operator.
     *
     * @param  list<string>  $refused
     */
    private function recordFailure(Suggestion $suggestion, array $refused): void
    {
        $action = (array) $suggestion->action;
        $reason = $refused === [] ? 'kurallardan geçen değer yok' : implode('; ', array_unique($refused));
        $attempts = (int) ($action['proposal_attempts'] ?? 0) + 1;
        $action['proposal_attempts'] = $attempts;
        $action['proposal_error'] = mb_substr($reason, 0, 300);
        if ($attempts >= self::MAX_ATTEMPTS) {
            $action['proposal_blocked'] = mb_substr('Otomatik hazırlık '.$attempts.' denemede kurallardan geçmedi: '.$reason, 0, 300);
        }
        $suggestion->forceFill(['action' => $action])->save();
    }

    /** Pages packed into one AI call share their language and page category. */
    public static function packKey(Page $page): string
    {
        return mb_strtolower((string) ($page->language ?: 'tr')).'|'.((string) $page->category ?: 'diger');
    }

    /**
     * The page text the AI and the number check see: the stored page text, or for a thin page (fewer than THIN_WORDS
     * words) the main text of the stored crawl HTML when that is longer.
     *
     * @param  Collection<int, Suggestion>  $suggestions
     * @return array<int, string> suggestion id => text
     */
    private function texts(DigitalAsset $site, Collection $suggestions): array
    {
        $out = [];
        foreach ($suggestions as $suggestion) {
            $page = $suggestion->page;
            $text = $page->aiText(2000);
            if ((int) $page->word_count < self::THIN_WORDS) {
                $rendered = $this->renderedText($site, (string) $page->url);
                if ($this->extractor->words($rendered) > $this->extractor->words($text)) {
                    $text = $rendered;
                }
            }
            $out[(int) $suggestion->id] = $text;
        }

        return $out;
    }

    private function renderedText(DigitalAsset $site, string $url): string
    {
        foreach (array_unique([$url, rtrim($url, '/'), rtrim($url, '/').'/']) as $candidate) {
            try {
                $stored = $this->html->read($site, $candidate);
            } catch (Throwable) {
                $stored = null;
            }
            if ($stored !== null) {
                return trim($this->extractor->fromDocument($stored['html'], $url)['content_text']);
            }
        }

        return '';
    }

    /**
     * True when the value shares at least GROUNDING_WORDS content words (stopwords, brand and area words left out) with
     * the page's current title, H1 or URL slug — fewer when the page itself has fewer such words. A page with none
     * cannot be checked and passes.
     *
     * @param  array{brand: list<string>, areas: list<string>}  $names
     */
    public static function grounded(Page $page, string $value, array $names): bool
    {
        $ignore = [...$names['brand'], ...$names['areas']];
        $anchors = array_values(array_diff(self::contentWords(implode(' ', [(string) $page->title, (string) $page->h1, SeoText::slugText((string) $page->url)])), $ignore));
        if ($anchors === []) {
            return true;
        }
        $words = self::contentWords($value);
        $shared = array_filter($anchors, fn (string $anchor): bool => collect($words)->contains(
            fn (string $word): bool => SeoText::wordMatches($word, $anchor) || SeoText::wordMatches($anchor, $word)));

        return count($shared) >= min(self::GROUNDING_WORDS, count($anchors));
    }

    /** @return list<string> folded content words of three letters or more, stopwords and numbers left out */
    private static function contentWords(string $text): array
    {
        return array_values(array_unique(array_filter(SeoText::tokens($text), fn (string $w): bool => mb_strlen($w) >= 3 && ! ctype_digit($w))));
    }

    /**
     * Waiting title / description proposals (not approved, nothing written) whose values fail the grounding check lose
     * them, so they are prepared again under the current rules. Returns how many rows were cleared.
     */
    public static function clearUngrounded(): int
    {
        $cleared = 0;
        $names = [];
        Suggestion::query()->with(['page', 'brand'])->whereIn('action_type', self::TYPES)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::SNOOZED])
            ->whereNotNull('page_id')->whereNotNull('action->proposal')->whereNull('action->writes')
            ->chunkById(500, function ($chunk) use (&$cleared, &$names): void {
                foreach ($chunk as $suggestion) {
                    $action = (array) $suggestion->action;
                    $new = (array) data_get($action, 'proposal.new', []);
                    if ($suggestion->page === null) {
                        continue;
                    }
                    $brandId = (int) $suggestion->brand_id;
                    $names[$brandId] ??= ['brand' => self::words((string) $suggestion->brand?->name),
                        'areas' => self::words(implode(' ', BrandPack::areaNames($suggestion->brand, 10)))];
                    $fails = collect(['seo_title', 'meta_description'])->contains(fn (string $field): bool => filled($new[$field] ?? null)
                        && ! self::grounded($suggestion->page, (string) $new[$field], $names[$brandId]));
                    if ($fails) {
                        unset($action['proposal'], $action['proposal_warnings'], $action['proposal_attempts'], $action['proposal_error']);
                        $suggestion->forceFill(['action' => $action])->save();
                        $cleared++;
                    }
                }
            });

        return $cleared;
    }

    /** A title / description fix with no prepared value yet (nor a block, nor a write on its way). */
    public static function needsPreparing(Suggestion $suggestion): bool
    {
        $action = (array) $suggestion->action;

        return ! isset($action['proposal']) && ! isset($action['proposal_blocked']) && ! isset($action['writes']);
    }

    /**
     * Which fields the page needs, with why: from the audit's problem codes ("seo_title:missing"), both for an older
     * title / description suggestion.
     *
     * @return array<string, string>
     */
    public static function fix(Suggestion $suggestion): array
    {
        $codes = (array) data_get($suggestion->action, 'problems', []);
        if ($codes === []) {
            return ['seo_title' => 'improve', 'meta_description' => 'improve'];
        }
        $out = [];
        foreach ($codes as $code) {
            [$field, $why] = array_pad(explode(':', (string) $code, 2), 2, 'improve');
            if (in_array($field, ['seo_title', 'meta_description'], true)) {
                $out[$field] = $why;
            }
        }

        return $out;
    }

    /**
     * The AI's values that pass the rules, and why the others were refused; a field that fails is left out.
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, int>  $siteTitles
     * @param  array<string, int>  $siteDescriptions
     * @param  array{brand: list<string>, areas: list<string>}  $names  folded words of the brand name and its areas
     * @return array{0: array<string, string>, 1: list<string>}
     */
    private function checked(Suggestion $suggestion, array $answer, string $text, array &$siteTitles, array &$siteDescriptions, BriefCompliance $compliance, ForbiddenTerms $forbidden, array $names): array
    {
        $page = $suggestion->page;
        $evidence = new SiteEvidence([(string) $page->url]);
        preg_match_all('/\d+(?:[.,]\d+)?/u', (string) $page->content_text.' '.$text.' '.$page->title.' '.$page->h1.' '.$page->meta_description.' '.$page->url, $numbers);
        foreach (array_slice($numbers[0], 0, 500) as $number) {
            $evidence->addNumber($number);
        }
        $fix = self::fix($suggestion);
        $new = [];
        $refused = [];
        $limits = ['seo_title' => [SiteAudit::TITLE_MIN, SiteAudit::TITLE_MAX], 'meta_description' => [SiteAudit::DESCRIPTION_MIN, SiteAudit::DESCRIPTION_MAX]];
        $labels = ['seo_title' => 'başlık', 'meta_description' => 'açıklama'];
        foreach ($limits as $field => [$min, $max]) {
            if (! isset($fix[$field])) {
                continue;
            }
            $value = trim((string) ($answer[$field] ?? ''));
            $folded = SeoText::fold($value);
            $taken = $field === 'seo_title' ? $siteTitles : $siteDescriptions;
            $problem = match (true) {
                $value === '' => 'boş',
                mb_strlen($value) < $min || mb_strlen($value) > $max => 'uzunluk '.mb_strlen($value).' karakter',
                isset($taken[$folded]), $value === trim((string) ($field === 'seo_title' ? $page->title : $page->meta_description)) => 'başka sayfayla ya da eskisiyle aynı',
                ! self::grounded($page, $value, $names) => 'sayfanın konusuyla örtüşmüyor',
                ! $evidence->grounded($value) => 'sayfada olmayan sayı ya da iddia',
                ! $compliance->isCompliant($value) || $forbidden->blocking($value) !== [] => 'yasaklı ya da uyumsuz ifade',
                $field === 'seo_title' && ! self::keepsNames($page, $value, $names) => 'marka ya da bölge adı düştü',
                default => null,
            };
            if ($problem !== null) {
                $refused[] = $labels[$field].': '.$problem;

                continue;
            }
            $new[$field] = $value;
            if ($field === 'seo_title') {
                $siteTitles[$folded] = 1;
            } else {
                $siteDescriptions[$folded] = 1;
            }
        }

        return [$new, $refused];
    }

    /**
     * A new title replaces the whole title Google shows: it must keep every brand-name and area word the current title
     * has. A post title (the SEO plugin adds " | Brand Location" to it) carries the brand in search results, so the new
     * literal title must name the brand too.
     *
     * @param  array{brand: list<string>, areas: list<string>}  $names
     */
    public static function keepsNames(Page $page, string $value, array $names): bool
    {
        $current = self::words((string) $page->title);
        $new = self::words($value);
        $kept = array_intersect([...$names['brand'], ...$names['areas']], $current);
        if (array_diff($kept, $new) !== []) {
            return false;
        }

        return $page->title_source !== 'post' || $names['brand'] === [] || array_intersect($names['brand'], $new) !== [];
    }

    /** @return list<string> folded words of three letters or more */
    private static function words(string $text): array
    {
        return array_values(array_unique(array_filter(explode(' ', SeoText::fold($text)), fn (string $w): bool => mb_strlen($w) >= 3)));
    }

    /**
     * The brand part of the pack; a Q&A / blog pack gets no service list (the page is about its own question).
     *
     * @return array{name: string, services: list<string>, areas: list<string>}
     */
    private function brandPack(Brand $brand, string $category): array
    {
        return [
            'name' => (string) $brand->name,
            'services' => in_array($category, self::NO_SERVICE_CATEGORIES, true) ? [] : BrandPack::serviceNames($brand, 30),
            'areas' => BrandPack::areaNames($brand, 10),
        ];
    }

    /**
     * The searches each page was shown for in 28 days, most seen first (up to 5).
     *
     * @param  list<string>  $urls
     * @return array<string, list<string>>
     */
    private function pageQueries(array $urls): array
    {
        $out = [];
        DB::table('gsc_query_page_daily')->whereIn('page', $urls)->where('reporting_date', '>=', now()->subDays(28)->toDateString())
            ->groupBy('page', 'query')->selectRaw('page, query, sum(impressions) as impressions')->orderByDesc('impressions')->limit(400)->get()
            ->each(function (object $r) use (&$out): void {
                if (count($out[(string) $r->page] ?? []) < 5) {
                    $out[(string) $r->page][] = (string) $r->query;
                }
            });

        return $out;
    }

    /** Title / description fixes still to prepare (operational brands, open, no prepared value, block or write). */
    public static function waitingQuery(?int $brandId = null): Builder
    {
        return Suggestion::query()->whereHas('brand', fn ($b) => $b->operational())->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->where('channel', 'search')->whereIn('action_type', self::TYPES)->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])
            ->whereNotNull('page_id')->whereNull('action->proposal')->whereNull('action->proposal_blocked')->whereNull('action->writes');
    }
}
