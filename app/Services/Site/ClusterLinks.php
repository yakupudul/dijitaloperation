<?php

namespace App\Services\Site;

use App\Models\Brand;
use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Küme bağları ve ölçüm (yakup, 2026-10-15): once an article MoxDOP sent is live on the site,
 *
 *  - Bağlantı: the cluster's main page should link to it. When it does not yet, an "iç link" row goes to the Onarım
 *    masası for the main page (prepared by "AI ile yap" overnight like every link row, applied only on approval).
 *  - Ölçüm: an article live for 90 days that Google never showed (no impression) gets a "güçlendir" idea of its own
 *    page, so it is rewritten or merged into the main page instead of staying a dead page.
 */
final class ClusterLinks
{
    public const int WEAK_AFTER_DAYS = 90;

    public function __construct(private readonly ContentMap $map) {}

    /** @return array{links: int, weak: int} */
    public function run(): array
    {
        $links = 0;
        $weak = 0;
        $ideas = Suggestion::query()->where('channel', 'search')->where('action_type', SiteSuggestionTypes::CONTENT)->whereNotNull('action->article_write_id')
            ->where('updated_at', '>=', now()->subDays(365))->whereHas('brand', fn ($b) => $b->operational())->orderBy('id')->get();
        foreach ($ideas as $idea) {
            if (data_get($idea->action, 'kind') === 'update') {
                continue;
            }
            $site = DigitalAsset::query()->find((int) data_get($idea->action, 'site_id'));
            $page = $site !== null ? $this->livePage($idea, $site) : null;
            if ($page === null) {
                continue;
            }
            $links += $this->linkFromMain($idea, $site, $page) ? 1 : 0;
            $weak += $this->strengthenIfUnseen($idea, $site, $page) ? 1 : 0;
        }

        return ['links' => $links, 'weak' => $weak];
    }

    /** The article's page on the site (its post collected from WordPress), in the site's main language. */
    public function livePage(Suggestion $idea, DigitalAsset $site): ?Page
    {
        $write = ExternalWriteAction::query()->whereKey((int) data_get($idea->action, 'article_write_id'))->whereIn('status', ['succeeded', 'partial'])->first(['id', 'result']);
        $posts = $write === null ? [] : array_values(array_unique(array_filter([(int) data_get($write->result, 'post_id'),
            ...array_map(fn (mixed $p): int => (int) data_get($p, 'post_id'), (array) data_get($write->result, 'posts', []))])));
        if ($posts === []) {
            return null;
        }
        $main = SiteScope::primaryLanguage($site);

        return Page::query()->where('website_asset_id', $site->id)->whereIn('wp_post_id', $posts)->where('is_indexable', true)->orderBy('id')->get(Page::LIST_COLUMNS)
            ->first(fn (Page $p): bool => ContentPlanner::inLanguage($p, $main));
    }

    private function linkFromMain(Suggestion $idea, DigitalAsset $site, Page $page): bool
    {
        $cluster = $idea->cluster_id !== null ? Cluster::query()->find($idea->cluster_id) : null;
        $brand = Brand::query()->find($idea->brand_id);
        $main = $cluster !== null && $brand !== null ? $this->map->pillar($brand, $site, $cluster) : null;
        if ($main === null || (int) $main->id === (int) $page->id || $this->links($site, (string) $main->url, (string) $page->url)) {
            return false;
        }
        $fingerprint = hash('sha256', $site->brand_id.'|cluster-link|'.$main->id.'|'.$page->id);
        if (Suggestion::query()->where('brand_id', $site->brand_id)->where('fingerprint', $fingerprint)->exists()) {
            return false;
        }
        Suggestion::query()->create([
            'brand_id' => $site->brand_id, 'channel' => 'search', 'decision_key' => 'site.cluster-link', 'action_type' => 'internal_links', 'priority' => 3,
            'fingerprint' => $fingerprint, 'material_hash' => hash('sha256', 'cluster-link|'.$page->url), 'status' => Suggestion::OPEN,
            'title' => mb_substr('İç link ekle: '.SeoText::urlPath((string) $main->url).' → '.SeoText::urlPath((string) $page->url), 0, 160),
            'reason' => mb_substr('Kümenin ana sayfası, kümenin yayına giren yeni yazısına bağlantı vermiyor: '.(string) $page->url
                .' ('.($page->title ?: $idea->title).'). Ana sayfanın ilgili bölümünde ona doğal bir iç link ver.', 0, 240),
            'target_type' => 'page', 'target_id' => $main->id, 'page_id' => $main->id, 'cluster_id' => $idea->cluster_id, 'first_seen_at' => now(), 'last_seen_at' => now(),
            'evidence' => [['kind' => 'url', 'value' => 'Yeni destek yazısı: '.$page->url, 'source' => (string) $page->url]],
            'action' => ['site_id' => (int) $site->id, 'link_to' => (string) $page->url, 'from_suggestion_id' => (int) $idea->id],
        ]);

        return true;
    }

    private function strengthenIfUnseen(Suggestion $idea, DigitalAsset $site, Page $page): bool
    {
        $sentAt = $idea->resolved_at ?? $idea->updated_at;
        if ($sentAt === null || $sentAt->gt(now()->subDays(self::WEAK_AFTER_DAYS)) || ! Schema::hasTable('gsc_page_daily')) {
            return false;
        }
        $key = SeoText::urlKey((string) $page->url);
        $seen = (int) DB::table('gsc_page_daily')->whereIn('page', array_values(array_unique([(string) $page->url, rtrim((string) $page->url, '/'), rtrim((string) $page->url, '/').'/'])))
            ->where('reporting_date', '>=', now()->subDays(self::WEAK_AFTER_DAYS)->toDateString())->sum('impressions');
        $fingerprint = hash('sha256', implode('|', [$idea->brand_id, 'content', 'weak-article', $key]));
        if ($seen > 0 || Suggestion::query()->where('brand_id', $idea->brand_id)->where(fn ($q) => $q->where('fingerprint', $fingerprint)
            ->orWhere(fn ($open) => $open->where('action_type', SiteSuggestionTypes::CONTENT)->where('page_id', $page->id)->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::SNOOZED])))->exists()) {
            return false;
        }
        Suggestion::query()->create([
            'brand_id' => $idea->brand_id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => $fingerprint, 'material_hash' => hash('sha256', $key),
            'title' => mb_substr((string) ($page->title ?: $idea->title), 0, 160), 'priority' => 4, 'status' => Suggestion::OPEN,
            'reason' => mb_substr('Yazı '.self::WEAK_AFTER_DAYS.' günde Google\'da hiç gösterim almadı; güçlendirilmeli ya da kümenin ana sayfasıyla birleştirilmeli.', 0, 240),
            'evidence' => [['kind' => 'page', 'value' => SeoText::urlPath((string) $page->url).' · '.self::WEAK_AFTER_DAYS.' günde 0 gösterim', 'source' => 'Search Console']],
            'action_type' => SiteSuggestionTypes::CONTENT, 'target_type' => 'page', 'target_id' => $page->id, 'page_id' => $page->id, 'cluster_id' => $idea->cluster_id,
            'first_seen_at' => now(), 'last_seen_at' => now(),
            'action' => ['site_id' => (int) $site->id, 'kind' => 'update', 'page_type' => (string) data_get($idea->action, 'page_type', 'blog'), 'target_url' => (string) $page->url,
                'outline' => array_values((array) data_get($idea->action, 'outline', [])), 'questions' => array_values((array) data_get($idea->action, 'questions', [])),
                'query' => data_get($idea->action, 'query'), 'angle' => 'update', 'source' => 'weak_article', 'week' => now()->format('o-\WW')],
        ]);

        return true;
    }

    /** Whether the site's link map already holds a link from one page to the other. */
    private function links(DigitalAsset $site, string $from, string $to): bool
    {
        if (! Schema::hasTable('website_link_edge')) {
            return false;
        }
        $targets = array_values(array_unique([$to, rtrim($to, '/'), rtrim($to, '/').'/']));
        $sources = array_values(array_unique([$from, rtrim($from, '/'), rtrim($from, '/').'/']));

        return DB::table('website_link_edge')->where('digital_asset_id', $site->id)->whereIn('source_url', $sources)
            ->where(fn ($q) => $q->whereIn('target_url', $targets)->orWhereIn('normalized_target_url', $targets))->exists();
    }
}
