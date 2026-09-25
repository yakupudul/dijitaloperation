<?php

namespace App\Services\Brain\Clustering;

use App\Services\SeoTasks\SeoText;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;

/** Active clusters of services with their member query keys (folded, places removed) — read side of the cluster map. */
final class ServiceClusters
{
    /**
     * @param  list<int>|null  $serviceIds
     * @return array<int, array{id: int, service_id: int, name: string, page_type: ?string, intent: ?string, head: ?string, keys: array<string, true>, queries: int}>
     */
    public function all(?array $serviceIds = null): array
    {
        $clusters = DB::table('library_query_clusters')->where('status', 'active')
            ->when($serviceIds !== null, fn ($q) => $q->whereIn('service_id', $serviceIds))
            ->get(['id', 'service_id', 'name', 'page_type', 'intent', 'head_query']);
        if ($clusters->isEmpty()) {
            return [];
        }
        $members = DB::table('search_query_library_item_service as s')->join('search_query_library_items as q', 'q.id', '=', 's.search_query_library_item_id')
            ->whereIn('s.library_cluster_id', $clusters->pluck('id'))->where('q.status', 'active')->whereNull('q.deleted_at')
            ->get(['s.library_cluster_id', 'q.canonical_text'])->groupBy('library_cluster_id');
        $out = [];
        foreach ($clusters as $cluster) {
            $keys = [];
            foreach ($members->get($cluster->id, []) as $member) {
                $keys[self::key((string) $member->canonical_text)] = true;
            }
            $out[(int) $cluster->id] = [
                'id' => (int) $cluster->id, 'service_id' => (int) $cluster->service_id, 'name' => (string) $cluster->name,
                'page_type' => $cluster->page_type, 'intent' => $cluster->intent, 'head' => $cluster->head_query,
                'keys' => $keys, 'queries' => count($keys),
            ];
        }

        return $out;
    }

    public static function key(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', SeoText::fold(LocationOptions::strip($text)['text'])) ?? '');
    }

    /**
     * Impressions, clicks and average position per page of the site for the cluster's queries.
     *
     * @param  list<array<string, mixed>>  $rows  gsc query × page rows of one site
     * @param  array<string, true>  $keys
     * @return array<string, array{url: string, impressions: int, clicks: int, position: ?float}> by url key, most impressions first
     */
    public static function pages(array $rows, array $keys): array
    {
        $pages = [];
        foreach ($rows as $row) {
            if (! isset($keys[self::key((string) $row['query'])])) {
                continue;
            }
            $key = (string) $row['url_key'];
            $page = $pages[$key] ?? ['url' => (string) $row['page'], 'impressions' => 0, 'clicks' => 0, 'pos_sum' => 0.0, 'pos_w' => 0];
            $page['impressions'] += (int) $row['impressions'];
            $page['clicks'] += (int) $row['clicks'];
            if ($row['position'] !== null) {
                $page['pos_sum'] += (float) $row['position'] * (int) $row['impressions'];
                $page['pos_w'] += (int) $row['impressions'];
            }
            $pages[$key] = $page;
        }
        foreach ($pages as &$page) {
            $page['position'] = $page['pos_w'] > 0 ? round($page['pos_sum'] / $page['pos_w'], 1) : null;
            unset($page['pos_sum'], $page['pos_w']);
        }
        uasort($pages, fn (array $a, array $b): int => $b['impressions'] <=> $a['impressions']);

        return $pages;
    }
}
