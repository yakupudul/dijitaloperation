<?php

namespace App\Services\Site;

use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;

/**
 * Technical state of a stored page (blueprint §5.4 "Teknik sorun", §6.4): the HTTP status of its last fetch
 * (`website_html_snapshot`, when the page was fetched over HTTP), indexable (no noindex) and canonical (empty or the
 * page itself). Read from collected data only; the site is never contacted.
 */
final class PageTechnical
{
    /** @return array{status: ?int, indexable: bool, canonical_ok: bool, issues: list<string>} */
    public static function of(Page $page): array
    {
        return self::many(collect([$page]))[(int) $page->id];
    }

    /**
     * One snapshot query for many pages.
     *
     * @param  iterable<Page>  $pages
     * @return array<int, array{status: ?int, indexable: bool, canonical_ok: bool, issues: list<string>}>
     */
    public static function many(iterable $pages): array
    {
        $pages = collect($pages);
        $statuses = [];
        foreach ($pages->groupBy('website_asset_id') as $siteId => $group) {
            $urls = $group->flatMap(fn (Page $p): array => [(string) $p->url, rtrim((string) $p->url, '/'), rtrim((string) $p->url, '/').'/'])->unique()->values();
            foreach ($urls->chunk(500) as $chunk) {
                DB::table('website_html_snapshot')->where('digital_asset_id', (int) $siteId)->whereIn('url', $chunk->all())
                    ->orderBy('observed_at')->get(['url', 'status_code'])
                    ->each(function (object $row) use (&$statuses, $siteId): void {
                        $statuses[(int) $siteId.'|'.rtrim((string) $row->url, '/')] = $row->status_code;
                    });
            }
        }

        return $pages->mapWithKeys(fn (Page $p): array => [(int) $p->id => self::state($p, $statuses[(int) $p->website_asset_id.'|'.rtrim((string) $p->url, '/')] ?? null)])->all();
    }

    /** @return array{status: ?int, indexable: bool, canonical_ok: bool, issues: list<string>} */
    private static function state(Page $page, mixed $status): array
    {
        $url = (string) $page->url;
        $canonical = trim((string) $page->canonical);
        $canonicalOk = $canonical === '' || SeoText::urlKey($canonical) === SeoText::urlKey($url);
        $issues = [];
        if ($status !== null && (int) $status >= 400) {
            $issues[] = 'Sayfa son taramada '.(int) $status.' döndü';
        }
        if (! $page->is_indexable) {
            $issues[] = 'Sayfa noindex — önce indekslemeyi açın';
        }
        if (! $canonicalOk) {
            $issues[] = 'Canonical başka sayfayı gösteriyor ('.(parse_url($canonical, PHP_URL_PATH) ?: $canonical).')';
        }

        return ['status' => $status !== null ? (int) $status : null, 'indexable' => (bool) $page->is_indexable, 'canonical_ok' => $canonicalOk, 'issues' => $issues];
    }
}
