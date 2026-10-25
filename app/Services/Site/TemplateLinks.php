<?php

namespace App\Services\Site;

use App\Support\SchemaState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Template links (pool #56): a link that a site repeats on most of its pages (a sidebar listing every question, a
 * related-posts block) is kept once per site in website_template_links, not once per page in website_link_edge.
 * Menus, header and footer were already kept from the home page only (WebsitePageAnalyzer::linkEdges); this covers
 * the blocks outside those tags. Avrupadent's ~1,400 question pages each carried ~6,000 such links (11.6M rows).
 *
 * Readers: a template target counts as linked from the site's pages (ClusterLinks, link suggestions, the verifier),
 * its incoming count is source_pages, and broken / external link checks read it through its sample source page.
 * Per-page link counts are the page's own (content) links.
 */
final class TemplateLinks
{
    /** A site needs this many crawled pages before a repeated link counts as a template link. */
    public const int MIN_SOURCES = 50;

    /** Share of the site's pages a target must be linked from. */
    public const float SHARE = 0.4;

    /** A template link no page carried for this long is dropped (its target is measured per page again). */
    public const int STALE_DAYS = 30;

    /** @var array<int, array{0: array<string, true>, 1: float}> asset => [normalized targets, read at] */
    private static array $cache = [];

    /**
     * Normalized targets that are template links of the site.
     *
     * @return array<string, true>
     */
    public static function targets(int $assetId): array
    {
        $hit = self::$cache[$assetId] ?? null;
        if ($hit !== null && microtime(true) - $hit[1] < 600 && ! app()->runningUnitTests()) {
            return $hit[0];
        }
        $targets = [];
        if (SchemaState::hasTable('website_template_links')) {
            foreach (DB::table('website_template_links')->where('digital_asset_id', $assetId)->pluck('normalized_target_url') as $url) {
                $targets[(string) $url] = true;
            }
        }
        self::$cache[$assetId] = [$targets, microtime(true)];

        return $targets;
    }

    public static function forget(?int $assetId = null): void
    {
        if ($assetId === null) {
            self::$cache = [];
        } else {
            unset(self::$cache[$assetId]);
        }
    }

    /**
     * The template rows of a site (for readers that check links or count incoming ones).
     *
     * @return list<object{normalized_target_url: string, target_url: string, is_internal: bool, sample_source_url: string, source_pages: int}>
     */
    public static function rows(int $assetId, ?bool $internal = null): array
    {
        if (! SchemaState::hasTable('website_template_links')) {
            return [];
        }

        return DB::table('website_template_links')->where('digital_asset_id', $assetId)
            ->when($internal !== null, fn ($q) => $q->where('is_internal', $internal))
            ->get(['normalized_target_url', 'target_url', 'is_internal', 'sample_source_url', 'source_pages'])->all();
    }

    /** Whether the target (any slash form) is a template link of the site. */
    public static function isTemplate(int $assetId, string $target): bool
    {
        $targets = self::targets($assetId);
        if ($targets === []) {
            return false;
        }
        foreach ([$target, rtrim($target, '/'), rtrim($target, '/').'/'] as $form) {
            if (isset($targets[$form])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Edges of one page fetch without the site's template links (the home page keeps all of its links). The template
     * rows the page carried are marked seen.
     *
     * @param  list<array<string, mixed>>  $edges
     * @return list<array<string, mixed>>
     */
    public static function strip(int $assetId, string $sourceUrl, string $homeUrl, array $edges): array
    {
        if ($edges === [] || rtrim($sourceUrl, '/') === rtrim($homeUrl, '/')) {
            return $edges;
        }
        $targets = self::targets($assetId);
        if ($targets === []) {
            return $edges;
        }
        $kept = [];
        $seen = [];
        foreach ($edges as $edge) {
            $target = (string) ($edge['normalized_target_url'] ?? '');
            if ($target !== '' && isset($targets[$target])) {
                $seen[$target] = true;

                continue;
            }
            $kept[] = $edge;
        }
        if ($seen !== []) {
            try {
                foreach (array_chunk(array_keys($seen), 500) as $chunk) {
                    DB::table('website_template_links')->where('digital_asset_id', $assetId)->whereIn('normalized_target_url', $chunk)
                        ->where('last_seen_at', '<', now()->subHours(12))->update(['last_seen_at' => now(), 'updated_at' => now()]);
                }
            } catch (Throwable $exception) {
                Log::warning('Template links: last seen not marked.', ['asset' => $assetId, 'error' => $exception->getMessage()]);
            }
        }

        return $kept;
    }

    /**
     * Finds the site's template links in its stored edges, keeps them once and deletes their per-page rows (the home
     * page's own rows stay). Template links no page carried for STALE_DAYS are dropped.
     *
     * @return array{templates: int, deleted: int, dropped: int}
     */
    public function compact(int $assetId, string $homeUrl): array
    {
        $out = ['templates' => 0, 'deleted' => 0, 'dropped' => 0];
        if (! SchemaState::hasTable('website_link_edge') || ! SchemaState::hasTable('website_template_links')) {
            return $out;
        }
        $out['dropped'] = DB::table('website_template_links')->where('digital_asset_id', $assetId)
            ->where('last_seen_at', '<', now()->subDays(self::STALE_DAYS))->delete();
        $sources = (int) DB::table('website_link_edge')->where('digital_asset_id', $assetId)->distinct()->count('source_url');
        if ($sources < self::MIN_SOURCES) {
            self::forget($assetId);
            $out['templates'] = count(self::targets($assetId));

            return $out;
        }
        $needed = (int) ceil($sources * self::SHARE);
        $found = DB::table('website_link_edge')->where('digital_asset_id', $assetId)
            ->groupBy('normalized_target_url')
            ->havingRaw('count(distinct source_url) >= ?', [$needed])
            ->selectRaw('normalized_target_url, max(target_url) as target_url, max(anchor_text) as anchor_text, min(source_url) as sample_source_url, count(distinct source_url) as pages, max(case when is_internal then 1 else 0 end) as internal')
            ->get();
        $now = now();
        foreach ($found->chunk(500) as $chunk) {
            DB::table('website_template_links')->upsert($chunk->map(fn (object $row): array => [
                'digital_asset_id' => $assetId, 'normalized_target_url' => (string) $row->normalized_target_url, 'target_url' => (string) $row->target_url,
                'is_internal' => (int) $row->internal === 1, 'anchor_text' => $row->anchor_text !== null ? mb_substr((string) $row->anchor_text, 0, 500) : null,
                'sample_source_url' => (string) $row->sample_source_url, 'source_pages' => (int) $row->pages,
                'detected_at' => $now, 'last_seen_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ])->values()->all(), ['digital_asset_id', 'normalized_target_url'], ['target_url', 'is_internal', 'sample_source_url', 'source_pages', 'last_seen_at', 'updated_at']);
        }
        self::forget($assetId);
        $targets = array_keys(self::targets($assetId));
        $out['templates'] = count($targets);
        $home = [$homeUrl, rtrim($homeUrl, '/'), rtrim($homeUrl, '/').'/'];
        foreach (array_chunk($targets, 50) as $chunk) {
            do {
                $ids = DB::table('website_link_edge')->where('digital_asset_id', $assetId)->whereIn('normalized_target_url', $chunk)
                    ->whereNotIn('source_url', $home)->limit(20000)->pluck('id')->all();
                if ($ids !== []) {
                    $out['deleted'] += DB::table('website_link_edge')->whereIn('id', $ids)->delete();
                }
            } while (count($ids) === 20000);
        }

        return $out;
    }
}
