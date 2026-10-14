<?php

namespace App\Services\Site;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Integrations\WordPress\WordPressConnectorClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Where the articles MoxDOP sent stand on the site (yakup, 2026-10-12: "Taslakta" shows the site's drafts, "Yayında"
 * the published ones). The site is asked hourly (connector 1.14.0 `/posts/status`); until then, or for an older
 * plugin, the content snapshot says it. A published article carries its Search Console clicks and impressions.
 */
final class ContentPosts
{
    public const int CACHE_HOURS = 6;

    public function __construct(private readonly WordPressConnectorClient $client) {}

    public static function cacheKey(int $siteId): string
    {
        return 'content-posts:'.$siteId;
    }

    /**
     * The WordPress posts of sent ideas: suggestion id => language => post id (source first).
     *
     * @param  Collection<int, Suggestion>  $ideas
     * @return array<int, array<string, int>>
     */
    public function postIds(Collection $ideas): array
    {
        $writeIds = [];
        foreach ($ideas as $idea) {
            foreach ([data_get($idea->action, 'article_write_id'), ...(array) data_get($idea->action, 'translation_write_ids', [])] as $id) {
                if ((int) $id > 0) {
                    $writeIds[(int) $id] = (int) $idea->id;
                }
            }
        }
        if ($writeIds === []) {
            return [];
        }
        $out = [];
        ExternalWriteAction::query()->whereIn('id', array_keys($writeIds))->whereIn('status', ['succeeded', 'partial'])->orderBy('id')->get(['id', 'result'])
            ->each(function (ExternalWriteAction $write) use ($writeIds, &$out): void {
                $ideaId = $writeIds[(int) $write->id];
                foreach ((array) data_get($write->result, 'posts', []) as $post) {
                    if (($post['ok'] ?? false) && (int) ($post['post_id'] ?? 0) > 0) {
                        $out[$ideaId][(string) ($post['language'] ?? '')] ??= (int) $post['post_id'];
                    }
                }
                if (! isset($out[$ideaId]) && (int) data_get($write->result, 'post_id') > 0) {
                    $out[$ideaId][''] = (int) data_get($write->result, 'post_id');
                }
            });

        return $out;
    }

    /**
     * post id => {status, date (Istanbul), link, checked} from the site's last answer, else the content snapshot.
     *
     * @param  list<int>  $postIds
     * @return array<int, array{status: string, date: ?CarbonImmutable, link: ?string, from: string}>
     */
    public function states(int $siteId, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $asked = (array) Cache::get(self::cacheKey($siteId), []);
        $out = [];
        $snapshot = DB::table('website_cms_object_snapshot')->where('digital_asset_id', $siteId)->where('object_type', '!=', 'attachment')
            ->whereIn('object_id', array_map('strval', $postIds))->orderByDesc('id')->get(['object_id', 'status', 'published_at', 'permalink'])->unique('object_id')->keyBy('object_id');
        foreach ($postIds as $postId) {
            $site = $asked[$postId] ?? null;
            $row = $snapshot->get((string) $postId);
            if (is_array($site) && ($site['status'] ?? 'missing') !== 'missing') {
                $out[$postId] = ['status' => (string) $site['status'], 'date' => self::date($site['date_gmt'] ?? null), 'link' => filled($site['link'] ?? null) ? (string) $site['link'] : null, 'from' => 'site'];
            } elseif ($row !== null) {
                $out[$postId] = ['status' => (string) $row->status, 'date' => self::date($row->published_at), 'link' => $row->status === 'publish' ? (string) $row->permalink : null, 'from' => 'snapshot'];
            } elseif (is_array($site)) {
                $out[$postId] = ['status' => 'missing', 'date' => null, 'link' => null, 'from' => 'site'];
            }
        }

        return $out;
    }

    /**
     * Search Console clicks and impressions of published addresses in the last 30 and 90 days.
     *
     * @param  list<string>  $urls
     * @return array<string, array{clicks30: int, impressions30: int, clicks90: int, impressions90: int}>
     */
    public function metrics(array $urls): array
    {
        if ($urls === []) {
            return [];
        }
        $variants = collect($urls)->flatMap(fn (string $url): array => [$url, rtrim($url, '/'), rtrim($url, '/').'/'])->unique()->values()->all();
        $since30 = now()->subDays(30)->toDateString();
        $rows = DB::table('gsc_page_daily')->whereIn('page', $variants)->where('reporting_date', '>=', now()->subDays(90)->toDateString())
            ->groupBy('page')->selectRaw('page, sum(clicks) as clicks90, sum(impressions) as impressions90, sum(case when reporting_date >= ? then clicks else 0 end) as clicks30, sum(case when reporting_date >= ? then impressions else 0 end) as impressions30', [$since30, $since30])
            ->get()->keyBy(fn (object $row): string => rtrim((string) $row->page, '/'));
        $out = [];
        foreach ($urls as $url) {
            $row = $rows->get(rtrim($url, '/'));
            $out[$url] = ['clicks30' => (int) ($row->clicks30 ?? 0), 'impressions30' => (int) ($row->impressions30 ?? 0), 'clicks90' => (int) ($row->clicks90 ?? 0), 'impressions90' => (int) ($row->impressions90 ?? 0)];
        }

        return $out;
    }

    /**
     * Asks the site where the posts of its sent articles stand (connector 1.14.0); older plugins keep the snapshot.
     *
     * @return int posts asked
     */
    public function sync(DigitalAsset $site): int
    {
        $connection = CoreConnection::query()->with('credential')->where('digital_asset_id', $site->id)->where('type', 'wordpress_connector')->where('enabled', true)->first();
        if ($connection === null || version_compare((string) data_get($connection->config, 'plugin_version', '0.0.0'), '1.14.0', '<')) {
            return 0;
        }
        $ideas = self::sentIdeas()->filter(fn (Suggestion $s): bool => (int) data_get($s->action, 'site_id') === (int) $site->id);
        $postIds = array_values(array_unique(array_merge(...array_map('array_values', array_values($this->postIds($ideas)) ?: [[]]))));
        if ($postIds === []) {
            return 0;
        }
        $states = [];
        foreach (array_chunk($postIds, 100) as $chunk) {
            foreach ($this->client->postsStatus($connection, $chunk) as $post) {
                $states[(int) ($post['post_id'] ?? 0)] = array_intersect_key($post, array_flip(['status', 'date_gmt', 'link', 'featured_media_id']));
            }
        }
        Cache::put(self::cacheKey((int) $site->id), $states, now()->addHours(self::CACHE_HOURS));

        return count($states);
    }

    /** One post's new state straight after MoxDOP changed its date (the next hourly read confirms it). */
    public static function remember(int $siteId, int $postId, array $state): void
    {
        $states = (array) Cache::get(self::cacheKey($siteId), []);
        $states[$postId] = array_intersect_key($state, array_flip(['status', 'date_gmt', 'link', 'featured_media_id']));
        Cache::put(self::cacheKey($siteId), $states, now()->addHours(self::CACHE_HOURS));
    }

    /**
     * Site events as they arrive (yakup 2026-10-14: a draft published on the site must show in MoxDOP at once). Connector
     * 1.17.0 sends the state of a post MoxDOP made with the event; for an older plugin a publish / unpublish / trash /
     * delete event of a post MoxDOP already tracks moves it too. The hourly read still confirms everything.
     *
     * @param  list<array<string, mixed>>  $events
     */
    public static function applyEvents(int $siteId, array $events): void
    {
        $states = (array) Cache::get(self::cacheKey($siteId), []);
        $changed = false;
        foreach ($events as $event) {
            $postId = ctype_digit((string) ($event['object_id'] ?? '')) ? (int) $event['object_id'] : 0;
            if ($postId < 1) {
                continue;
            }
            $sent = $event['post_state'] ?? null;
            if (is_array($sent) && (int) ($sent['post_id'] ?? 0) === $postId && filled($sent['status'] ?? null)) {
                $states[$postId] = array_intersect_key($sent, array_flip(['status', 'date_gmt', 'link', 'featured_media_id']));
                $changed = true;

                continue;
            }
            $status = ['content.published' => 'publish', 'content.unpublished' => 'draft', 'content.trashed' => 'trash', 'content.deleted' => 'missing'][$event['type'] ?? ''] ?? null;
            if ($status === null || ! isset($states[$postId]) || ! is_array($states[$postId])) {
                continue;
            }
            $before = (string) ($states[$postId]['status'] ?? '');
            $states[$postId] = ['status' => $status, 'link' => $status === 'publish' ? (string) ($event['url'] ?? '') : ''] + $states[$postId];
            if ($status === 'publish' && ($before !== 'future' || blank($states[$postId]['date_gmt'] ?? null))) {
                // A draft published by hand goes out now; a scheduled one keeps its planned date.
                $states[$postId]['date_gmt'] = (string) ($event['occurred_at'] ?? '');
            }
            $changed = true;
        }
        if ($changed) {
            Cache::put(self::cacheKey($siteId), $states, now()->addHours(self::CACHE_HOURS));
        }
    }

    /** @return Collection<int, Suggestion> ideas whose article went to WordPress */
    public static function sentIdeas(): Collection
    {
        return Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->whereNotNull('action->article_write_id')
            ->where('updated_at', '>=', now()->subDays(365))->get(['id', 'action']);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        return filled($value) ? CarbonImmutable::parse((string) $value)->timezone('Europe/Istanbul') : null;
    }
}
