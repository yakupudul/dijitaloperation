<?php

namespace App\Services\Site;

use App\Models\BrandClusterPage;
use App\Models\Cluster;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Çıta sayfalar (docs/product/CONTENT_IDEAS_BLUEPRINT.md §6.1–6.2): pages of OTHER brands mapped to the same cluster
 * whose nightly score is at least MIN_SCORE (scored, not "veri az"), best MAX first. Only their skeleton goes to the
 * AI — score and Search Console measures, H2–H4 outline, word count, question headings, the cluster subtopics they
 * cover — never their text. No restriction between brands (operator decision, 2026-10-01).
 */
final class ClusterBenchmarks
{
    public const int MIN_SCORE = 60;

    public const int MAX = 3;

    /** @return list<array{score: int, position: ?float, coverage: float, ctr: float, outline: list<string>, word_count: int, faq_count: int, covered_subtopics: list<string>}> */
    public function for(Cluster $cluster, ?int $excludeBrandId): array
    {
        $rows = DB::table('cluster_page_scores')->where('cluster_id', $cluster->id)->where('state', 'scored')->where('score', '>=', self::MIN_SCORE)
            ->whereNotNull('page_id')->when($excludeBrandId !== null, fn ($q) => $q->where('brand_id', '!=', $excludeBrandId))
            ->orderByDesc('score')->orderBy('id')->limit(self::MAX)->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $pages = Page::query()->whereIn('id', $rows->pluck('page_id')->all())->get(['id', 'headings', 'content_text', 'word_count'])->keyBy('id');
        $subtopics = array_values(array_filter(array_map('strval', (array) $cluster->subtopics)));

        return $rows->map(function (object $row) use ($pages, $subtopics): ?array {
            $page = $pages->get($row->page_id);
            if ($page === null) {
                return null;
            }
            $text = SeoText::fold(implode(' ', [...$page->headingTexts(), (string) $page->content_text]));

            return [
                'score' => (int) $row->score, 'position' => $row->position !== null ? (float) $row->position : null,
                'coverage' => (float) $row->coverage, 'ctr' => (float) $row->ctr,
                'outline' => array_slice($page->outline(), 0, 40), 'word_count' => (int) $page->word_count,
                'faq_count' => count(array_filter($page->headingTexts(), fn (string $h): bool => str_ends_with($h, '?'))),
                'covered_subtopics' => array_values(array_filter($subtopics, fn (string $s): bool => SeoText::fold($s) !== '' && str_contains($text, SeoText::fold($s)))),
            ];
        })->filter()->values()->all();
    }

    /**
     * Content texts of the other brands' pages mapped to the cluster (the copy check compares against them).
     *
     * @return array<string, string> url → text
     */
    public static function otherBrandTexts(int $clusterId, int $brandId): array
    {
        $pageIds = BrandClusterPage::query()->where('cluster_id', $clusterId)->where('brand_id', '!=', $brandId)->get(['page_id', 'extra_page_ids'])
            ->flatMap(fn (BrandClusterPage $row): array => $row->pageIds())->unique()->values()->all();

        return Page::query()->whereIn('id', $pageIds ?: [0])->whereNotNull('content_text')->get(['url', 'content_text'])
            ->mapWithKeys(fn (Page $p): array => [(string) $p->url => (string) $p->content_text])->all();
    }
}
