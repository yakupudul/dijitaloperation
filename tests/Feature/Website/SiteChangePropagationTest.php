<?php

namespace Tests\Feature\Website;

use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use App\Services\Website\SitemapChangeWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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

        $baseline = $watcher->check($this->site);
        $this->assertSame(['status' => 'baseline', 'changed' => 0, 'pages' => 3], array_intersect_key($baseline, array_flip(['status', 'changed', 'pages'])));
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

    public function test_no_automatic_public_crawl_only_connector_sites_with_a_sitemap_override_are_watched(): void
    {
        $wordpress = DigitalAsset::factory()->create(['type' => 'website', 'status' => 'active', 'module_id' => 'website', 'domain' => 'wp.example', 'primary_url' => 'https://wp.example/']);
        CoreConnection::factory()->create(['digital_asset_id' => $wordpress->id, 'type' => 'wordpress_connector', 'enabled' => true, 'config' => ['pairing_state' => 'paired']]);
        $override = DigitalAsset::factory()->create(['type' => 'website', 'status' => 'active', 'module_id' => 'website', 'domain' => 'wp2.example', 'primary_url' => 'https://wp2.example/', 'sitemap_url' => 'https://wp2.example/extra.xml']);
        CoreConnection::factory()->create(['digital_asset_id' => $override->id, 'type' => 'wordpress_connector', 'enabled' => true, 'config' => ['pairing_state' => 'paired']]);

        $ids = $this->watcher()->eligibleSiteIds();

        $this->assertNotContains($this->site->id, $ids, 'a site without the connector is collected only by hand');
        $this->assertNotContains($wordpress->id, $ids);
        $this->assertSame([$override->id], $ids);
    }

    public function test_plugin_sends_right_after_a_save_and_serves_the_indexnow_key(): void
    {
        $events = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-events.php'));
        $this->assertStringContainsString('$this->send_soon();', $events, 'persist() schedules a one-off send');
        $this->assertStringContainsString('wp_schedule_single_event(time() + self::DEBOUNCE, self::NOW_HOOK);', $events, 'one debounced send about a minute after the save');
        $this->assertStringContainsString('const DEBOUNCE = 60;', $events);
        $this->assertStringNotContainsString('spawn_cron(', $events, '1.5.1: no loopback request on every save (shared hosts)');
        $this->assertStringContainsString("'interval' => 900", $events, 'the 15-minute schedule stays as fallback');
        $this->assertStringContainsString('$event->schedule !== self::SCHEDULE', $events, 'existing 5-minute installs are moved to 15 minutes');
        $this->assertStringContainsString('const HEARTBEAT = 21600;', $events, 'an empty outbox sends only a 6-hour heartbeat');
        $this->assertStringContainsString('get_option(self::SETUP_OPTION) === MOXDOP_CONNECTOR_VERSION', $events, 'setup / dbDelta once per version');
        $this->assertStringContainsString('time() - HOUR_IN_SECONDS', $events, 'the outbox size check runs at most hourly');
        $rest = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-rest-controller.php'));
        $this->assertStringContainsString('MoxDOP_Connector_Lock::acquire($lock, 120)', $rest, 'one snapshot at a time');
        $this->assertStringContainsString("\$busy->header('Retry-After', '30');", $rest);
        $this->assertStringContainsString('$per_page = min(50, max(1,', $rest);
        $indexnow = file_get_contents(base_path('connectors/wordpress/moxdop-connector/includes/class-moxdop-connector-indexnow.php'));
        $this->assertStringContainsString("get_option('blog_public', '1') === '1'", $indexnow, 'never while search engines are discouraged');
        $this->assertStringContainsString("\$path !== '/'.\$key.'.txt'", $indexnow);
    }

    public function test_every_plugin_file_lints_and_the_version_matches_what_moxdop_offers(): void
    {
        $files = glob(base_path('connectors/wordpress/moxdop-connector/{,includes/}*.php'), GLOB_BRACE) ?: [];
        $this->assertGreaterThan(10, count($files));
        foreach ($files as $file) {
            exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($file).' 2>&1', $output, $code);
            $this->assertSame(0, $code, basename($file).': '.implode("\n", $output));
        }
        $main = (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/moxdop-connector.php'));
        $version = (string) config('moxdop-wordpress.connector_version');
        $this->assertStringContainsString(' * Version: '.$version, $main);
        $this->assertStringContainsString("define('MOXDOP_CONNECTOR_VERSION', '".$version."')", $main);
        $this->assertStringContainsString('Stable tag: '.$version, (string) file_get_contents(base_path('connectors/wordpress/moxdop-connector/readme.txt')));
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
