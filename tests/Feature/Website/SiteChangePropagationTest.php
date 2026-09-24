<?php

namespace Tests\Feature\Website;

use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Models\SiteFixItem;
use App\Services\SeoTasks\SeoUrlInspectionQueue;
use App\Services\Website\SitemapChangeWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/** 1.4.1: site changes reach MoxDOP without waiting for a full crawl (sitemap watch, changed-page inspection, plugin). */
final class SiteChangePropagationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $responses = [];

    /** @var list<string> */
    private array $fetched = [];

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->site = DigitalAsset::factory()->create(['type' => 'website', 'status' => 'active', 'module_id' => 'website', 'domain' => 'site.example', 'primary_url' => 'https://site.example/']);
    }

    public function test_sitemap_watch_records_a_baseline_then_crawls_only_changed_pages(): void
    {
        $this->responses = [
            'https://site.example/robots.txt' => "User-agent: *\nSitemap: https://site.example/sitemap_index.xml\n",
            'https://site.example/sitemap_index.xml' => $this->index(['page-sitemap.xml' => '2026-09-20', 'post-sitemap.xml' => '2026-09-20']),
            'https://site.example/page-sitemap.xml' => $this->urlset(['/' => '2026-09-01', '/hizmet/' => '2026-09-10']),
            'https://site.example/post-sitemap.xml' => $this->urlset(['/blog/a/' => '2026-09-05']),
        ];
        $watcher = $this->watcher();

        $this->assertSame(['status' => 'baseline', 'changed' => 0, 'pages' => 3], $watcher->check($this->site));
        $this->assertSame(0, CollectionRun::query()->count(), 'the first look only records a baseline');

        // One page changed, one was added; the post sitemap did not move and is not downloaded again.
        $this->responses['https://site.example/sitemap_index.xml'] = $this->index(['page-sitemap.xml' => '2026-09-24', 'post-sitemap.xml' => '2026-09-20']);
        $this->responses['https://site.example/page-sitemap.xml'] = $this->urlset(['/' => '2026-09-01', '/hizmet/' => '2026-09-24', '/yeni/' => '2026-09-24']);
        $this->fetched = [];
        $result = $watcher->check($this->site);

        $this->assertSame(['checked', 2, 4], [$result['status'], $result['changed'], $result['pages']]);
        $this->assertNotContains('https://site.example/post-sitemap.xml', $this->fetched);
        $run = CollectionRun::query()->findOrFail($result['run_id']);
        $this->assertEqualsCanonicalizing(['https://site.example/hizmet/', 'https://site.example/yeni/'], data_get($run->request_context, 'context.targeted_verification.urls'));
        $this->assertSame('sitemap_change_refresh', data_get($run->request_context, 'context.collection_intent'));

        // Nothing moved: no crawl, the stored page map is not rewritten.
        $before = DB::table('website_sitemap_watch')->value('pages');
        $this->assertSame(0, $watcher->check($this->site)['changed']);
        $this->assertSame($before, DB::table('website_sitemap_watch')->value('pages'));
        $this->assertSame(1, CollectionRun::query()->count());
    }

    public function test_sites_with_the_wordpress_connector_are_not_watched(): void
    {
        $wordpress = DigitalAsset::factory()->create(['type' => 'website', 'status' => 'active', 'module_id' => 'website', 'domain' => 'wp.example', 'primary_url' => 'https://wp.example/']);
        CoreConnection::factory()->create(['digital_asset_id' => $wordpress->id, 'type' => 'wordpress_connector', 'enabled' => true, 'config' => ['pairing_state' => 'paired']]);

        $ids = $this->watcher()->eligibleSiteIds();

        $this->assertContains($this->site->id, $ids);
        $this->assertNotContains($wordpress->id, $ids);
    }

    public function test_changed_pages_go_first_in_search_console_inspection(): void
    {
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true, 'config' => ['pairing_state' => 'paired']]);
        DB::table('website_connector_events')->insert([
            ['connection_id' => $connection->id, 'digital_asset_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'content.updated', 'object_type' => 'page', 'object_id' => '5',
                'origin' => 'wordpress_user', 'payload' => json_encode(['url' => 'https://site.example/hizmet/']), 'occurred_at' => now()->subDays(2), 'received_at' => now()->subDays(2)],
            ['connection_id' => $connection->id, 'digital_asset_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'content.trashed', 'object_type' => 'page', 'object_id' => '6',
                'origin' => 'wordpress_user', 'payload' => json_encode(['url' => 'https://site.example/silindi/']), 'occurred_at' => now()->subDays(2), 'received_at' => now()->subDays(2)],
            ['connection_id' => $connection->id, 'digital_asset_id' => $this->site->id, 'event_id' => (string) Str::uuid(), 'type' => 'content.updated', 'object_type' => 'page', 'object_id' => '7',
                'origin' => 'wordpress_user', 'payload' => json_encode(['url' => 'https://baska.example/x/']), 'occurred_at' => now()->subDays(2), 'received_at' => now()->subDays(2)],
        ]);
        SiteFixItem::query()->create(['digital_asset_id' => $this->site->id, 'type' => 'seo_title', 'phase' => 1, 'object_id' => '9', 'url' => 'https://site.example/iletisim/',
            'label' => 'İletişim', 'status' => 'applied', 'item_key' => hash('sha256', 'x')]);
        SiteFixItem::query()->whereKey(SiteFixItem::query()->value('id'))->update(['updated_at' => now()->subDays(2)]);

        $queue = app(SeoUrlInspectionQueue::class);
        $this->assertEqualsCanonicalizing(['https://site.example/hizmet/', 'https://site.example/iletisim/'], $queue->recentlyChanged($this->site, now()->subDays(3), now()->subDay()));
        $this->assertSame([], $queue->recentlyChanged($this->site, now()->subHours(12), now()), 'outside the window');
        $this->assertSame('not_bound', $queue->queueChanged($this->site)['status'], 'no Search Console binding, nothing is queued');
    }

    public function test_plugin_sends_right_after_a_save_and_serves_the_indexnow_key(): void
    {
        $events = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-events.php'));
        $this->assertStringContainsString('$this->send_soon();', $events, 'persist() starts a one-off send');
        $this->assertStringContainsString('spawn_cron();', $events, 'non-blocking loopback');
        $this->assertStringContainsString("'moxdop_five_minutes'", $events, 'the 5-minute schedule stays as fallback');
        $indexnow = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-indexnow.php'));
        $this->assertStringContainsString("get_option('blog_public', '1') === '1'", $indexnow, 'never while search engines are discouraged');
        $this->assertStringContainsString("\$path !== '/'.\$key.'.txt'", $indexnow);
    }

    private function watcher(): SitemapChangeWatcher
    {
        return new SitemapChangeWatcher(function (string $url): array {
            $this->fetched[] = $url;

            return isset($this->responses[$url]) ? ['ok' => true, 'body' => $this->responses[$url]] : ['ok' => false, 'body' => null];
        });
    }

    /** @param array<string, string> $files */
    private function index(array $files): string
    {
        $body = '';
        foreach ($files as $file => $mod) {
            $body .= '<sitemap><loc>https://site.example/'.$file.'</loc><lastmod>'.$mod.'</lastmod></sitemap>';
        }

        return '<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$body.'</sitemapindex>';
    }

    /** @param array<string, string> $pages */
    private function urlset(array $pages): string
    {
        $body = '';
        foreach ($pages as $path => $mod) {
            $body .= '<url><loc>https://site.example'.$path.'</loc><lastmod>'.$mod.'</lastmod></url>';
        }

        return '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'.$body.'</urlset>';
    }
}
