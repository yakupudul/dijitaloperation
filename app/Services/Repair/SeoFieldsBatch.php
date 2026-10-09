<?php

namespace App\Services\Repair;

use App\Ai\Agents\Site\SeoFieldsBatchAgent;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Compliance\BriefCompliance;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\SiteAi;
use App\Services\Site\SiteEvidence;
use App\Services\Site\SiteScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Toplu hazırlık (yakup, 2026-10-09: tens of thousands of pages without a description must not wait months): title /
 * description fixes of one site are prepared up to 15 pages per AI call (`site.seo_fields_batch`), with the page's own
 * searches. Each value is checked by rules (length, not the same as another page of the site, no number the page does
 * not have, sector phrases) before it reaches the Onarım masası as a ready-to-approve row; nothing is written to the
 * site before the operator approves it.
 */
final class SeoFieldsBatch
{
    public const int PAGES_PER_CALL = 15;

    public const array TYPES = [SiteAudit::TYPE, 'title_description'];

    public function __construct(private readonly SiteAi $ai) {}

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
            ->take(self::PAGES_PER_CALL)->values();
        if ($suggestions->isEmpty()) {
            return ['status' => 'ready', 'prepared' => 0, 'skipped' => 0];
        }
        $queries = $this->pageQueries($suggestions->map(fn (Suggestion $s): string => (string) $s->page->url)->all());
        $result = $this->ai->run(new SeoFieldsBatchAgent, [
            'brand' => $this->brandPack($brand),
            'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
            'pages' => $suggestions->map(fn (Suggestion $s): array => [
                'id' => (int) $s->id, 'url' => (string) $s->page->url, 'language' => (string) ($s->page->language ?: 'tr'),
                'seo_title' => (string) $s->page->title, 'meta_description' => (string) $s->page->meta_description, 'h1' => (string) $s->page->h1,
                'text' => $s->page->aiText(700), 'fix' => self::fix($s), 'queries' => $queries[(string) $s->page->url] ?? [],
            ])->all(),
        ], 240);
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
        $prepared = 0;
        foreach ($suggestions as $suggestion) {
            $new = $this->checked($suggestion, (array) $answers->get($suggestion->id, []), $siteTitles, $siteDescriptions, $compliance, $forbidden);
            if ($new === []) {
                continue;
            }
            $page = $suggestion->page;
            $action = (array) $suggestion->action;
            unset($action['proposal_blocked'], $action['proposal_warnings']);
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
     * The AI's values that pass the rules; a field that fails is left out (the row then waits for the next run).
     *
     * @param  array<string, mixed>  $answer
     * @param  array<string, int>  $siteTitles
     * @param  array<string, int>  $siteDescriptions
     * @return array<string, string>
     */
    private function checked(Suggestion $suggestion, array $answer, array &$siteTitles, array &$siteDescriptions, BriefCompliance $compliance, ForbiddenTerms $forbidden): array
    {
        $page = $suggestion->page;
        $evidence = new SiteEvidence([(string) $page->url]);
        preg_match_all('/\d+(?:[.,]\d+)?/u', (string) $page->content_text.' '.$page->title.' '.$page->h1.' '.$page->meta_description.' '.$page->url, $numbers);
        foreach (array_slice($numbers[0], 0, 500) as $number) {
            $evidence->addNumber($number);
        }
        $fix = self::fix($suggestion);
        $new = [];
        $limits = ['seo_title' => [SiteAudit::TITLE_MIN, SiteAudit::TITLE_MAX], 'meta_description' => [SiteAudit::DESCRIPTION_MIN, SiteAudit::DESCRIPTION_MAX]];
        foreach ($limits as $field => [$min, $max]) {
            $value = trim((string) ($answer[$field] ?? ''));
            $folded = SeoText::fold($value);
            $taken = $field === 'seo_title' ? $siteTitles : $siteDescriptions;
            if (! isset($fix[$field]) || $value === '' || mb_strlen($value) < $min || mb_strlen($value) > $max || isset($taken[$folded])
                || $value === trim((string) ($field === 'seo_title' ? $page->title : $page->meta_description))
                || ! $evidence->grounded($value) || ! $compliance->isCompliant($value) || $forbidden->blocking($value) !== []) {
                continue;
            }
            $new[$field] = $value;
            if ($field === 'seo_title') {
                $siteTitles[$folded] = 1;
            } else {
                $siteDescriptions[$folded] = 1;
            }
        }

        return $new;
    }

    /** @return array{name: string, services: list<string>, areas: list<string>} */
    private function brandPack(Brand $brand): array
    {
        return [
            'name' => (string) $brand->name,
            'services' => SiteScope::offerings($brand)->map(fn (BrandOffering $o): string => $o->displayName())->filter()->unique()->take(30)->values()->all(),
            'areas' => SiteScope::areas($brand)->map(fn ($a): string => trim((string) ($a->district_name ?: $a->city_name ?: $a->name)))->filter()->unique()->take(10)->values()->all(),
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
