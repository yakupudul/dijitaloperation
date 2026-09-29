<?php

namespace Tests\Feature\Website;

use App\Models\Collection\CollectionRun;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Website\Pages\MainContentExtractor;
use App\Services\Website\Pages\PageStore;
use App\Services\Website\Pages\SitemapPageSync;
use App\Services\Website\SitemapChangeWatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** MoxDOP v2 Faz 1: non-WordPress sites fill `pages` from the sitemap + main content extraction. */
final class PagesFromSitemapTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> */
    private array $html = [];

    /** @var list<string> */
    private array $fetched = [];

    public function test_main_content_drops_site_chrome_and_keeps_h1_to_h3(): void
    {
        $content = (new MainContentExtractor)->fromDocument($this->page('Diş Beyazlatma', 'Beyazlatma ofiste bir saatte yapılır.'), 'https://site.example/beyazlatma/');

        $this->assertSame('Diş Beyazlatma | Site', $content['title']);
        $this->assertSame('Beyazlatma hakkında.', $content['meta_description']);
        $this->assertSame('https://site.example/beyazlatma/', $content['canonical']);
        $this->assertSame('tr', $content['language']);
        $this->assertTrue($content['is_indexable']);
        $this->assertSame('Diş Beyazlatma', $content['h1']);
        $this->assertSame([['level' => 1, 'text' => 'Diş Beyazlatma'], ['level' => 2, 'text' => 'Nasıl yapılır?'], ['level' => 3, 'text' => 'Süre']], $content['headings']);
        $this->assertStringContainsString('Beyazlatma ofiste bir saatte yapılır.', $content['content_text']);
        foreach (['Ana menü', 'Telif hakkı', 'Kenar çubuğu', 'Çerez', 'console.log'] as $chrome) {
            $this->assertStringNotContainsString($chrome, $content['content_text']);
        }

        $noindex = (new MainContentExtractor)->fromDocument('<html><head><meta name="robots" content="noindex, follow"></head><body><main><p>x</p></main></body></html>', 'https://site.example/x/');
        $this->assertFalse($noindex['is_indexable']);
    }

    public function test_sitemap_urls_are_fetched_once_rewritten_only_on_change_and_removed_when_gone(): void
    {
        $site = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'site.example', 'primary_url' => 'https://site.example/']);
        $this->html = [
            'https://site.example/beyazlatma/' => $this->page('Diş Beyazlatma', 'Birinci sürüm.'),
            'https://site.example/implant/' => $this->page('İmplant', 'İmplant metni.'),
        ];
        $sync = $this->sync();

        $first = $sync->sync($site->id, ['https://site.example/beyazlatma/' => ['m' => '2026-09-01'], 'https://site.example/implant/' => ['m' => '2026-09-01']]);
        $this->assertSame(['fetched' => 2, 'created' => 2, 'updated' => 0, 'unchanged' => 0, 'failed' => 0, 'removed' => 0], $first);
        $page = Page::query()->where('url_hash', PageStore::urlHash('https://site.example/beyazlatma/'))->sole();
        $this->assertNull($page->wp_post_id);
        $this->assertNull($page->category);
        $this->assertStringContainsString('Birinci sürüm.', $page->content_text);
        $page->forceFill(['analyzed_at' => now()])->save();

        // Same lastmod: nothing is fetched again.
        $this->fetched = [];
        $again = $sync->sync($site->id, ['https://site.example/beyazlatma/' => ['m' => '2026-09-01'], 'https://site.example/implant/' => ['m' => '2026-09-01']]);
        $this->assertSame(0, $again['fetched']);

        // Lastmod moved but the main content is the same: fetched, not rewritten.
        $sameAfter = $sync->sync($site->id, ['https://site.example/beyazlatma/' => ['m' => now()->addDay()->toDateString()], 'https://site.example/implant/' => ['m' => '2026-09-01']]);
        $this->assertSame(['fetched' => 1, 'unchanged' => 1], array_intersect_key($sameAfter, ['fetched' => 1, 'unchanged' => 1]));
        $this->assertNotNull($page->fresh()->analyzed_at);

        // Content changed: rewritten and flagged for analysis; the implant page left the sitemap and is removed.
        $this->html['https://site.example/beyazlatma/'] = $this->page('Diş Beyazlatma', 'İkinci sürüm, fiyatlarla.');
        $changed = $sync->sync($site->id, ['https://site.example/beyazlatma/' => ['m' => now()->addDays(2)->toDateString()]], ['https://site.example/beyazlatma/']);
        $this->assertSame(1, $changed['updated']);
        $this->assertSame(1, $changed['removed']);
        $page->refresh();
        $this->assertStringContainsString('İkinci sürüm', $page->content_text);
        $this->assertNull($page->analyzed_at);
        $this->assertSame(1, Page::query()->count());
    }

    public function test_unreachable_pages_are_retried_on_the_next_pass(): void
    {
        $site = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'site.example', 'primary_url' => 'https://site.example/']);
        $sync = $this->sync();

        $this->assertSame(1, $sync->sync($site->id, ['https://site.example/a/' => ['m' => null]])['failed']);
        $this->html['https://site.example/a/'] = $this->page('A', 'Artık erişilebilir.');
        $this->assertSame(1, $sync->sync($site->id, ['https://site.example/a/' => ['m' => null]])['created']);
    }

    public function test_pages_sync_command_runs_a_sitemap_pass_or_queues_the_wordpress_inventory(): void
    {
        Queue::fake();
        $site = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'site.example', 'primary_url' => 'https://site.example/']);
        $wordpress = DigitalAsset::factory()->create(['type' => 'website', 'domain' => 'wp.example', 'primary_url' => 'https://wp.example/']);
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $wordpress->id, 'type' => 'wordpress_connector', 'enabled' => true, 'config' => ['pairing_state' => 'paired']]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => fake()->uuid(), 'shared_secret' => str_repeat('a', 43)]]);
        $this->html = [
            'https://site.example/robots.txt' => "Sitemap: https://site.example/sitemap.xml\n",
            'https://site.example/sitemap.xml' => '<?xml version="1.0"?><urlset><url><loc>https://site.example/beyazlatma/</loc></url></urlset>',
            'https://site.example/beyazlatma/' => $this->page('Diş Beyazlatma', 'Metin.'),
        ];
        $this->app->instance(SitemapChangeWatcher::class, new SitemapChangeWatcher(fn (string $url): array => isset($this->html[$url])
            ? ['ok' => true, 'body' => $this->html[$url], 'content_type' => str_ends_with($url, '/') ? 'text/html' : 'application/xml'] : ['ok' => false, 'body' => null]));

        $this->artisan('moxdop:pages:sync', ['--site' => [$site->id, $wordpress->id]])
            ->expectsOutputToContain('yeni 1')
            ->expectsOutputToContain('WordPress envanteri kuyrukta')
            ->assertSuccessful();

        $this->assertSame(1, Page::query()->where('website_asset_id', $site->id)->count());
        $run = CollectionRun::query()->where('digital_asset_id', $wordpress->id)->sole();
        $this->assertSame('wordpress', data_get($run->request_context, 'context.collection_scope'));
        $this->artisan('moxdop:pages:sync')->assertExitCode(2);
    }

    private function sync(): SitemapPageSync
    {
        return new SitemapPageSync(app(PageStore::class), new MainContentExtractor, function (string $url): array {
            $this->fetched[] = $url;

            return ['html' => $this->html[$url] ?? null, 'final_url' => $url, 'error' => isset($this->html[$url]) ? null : 'http_404'];
        });
    }

    private function page(string $h1, string $body): string
    {
        return '<!doctype html><html lang="tr-TR"><head><title>'.$h1.' | Site</title>'
            .'<meta name="description" content="Beyazlatma hakkında."><link rel="canonical" href="https://site.example/beyazlatma/">'
            .'<script>console.log("x")</script></head><body>'
            .'<header class="site-header"><nav><a href="/">Ana menü</a></nav></header>'
            .'<div class="cookie-bar">Çerez bildirimi</div>'
            .'<main><article><h1>'.$h1.'</h1><p>'.$body.'</p><h2>Nasıl yapılır?</h2><p>Jel uygulanır ve ışık tutulur.</p><h3>Süre</h3><p>Bir saat.</p></article></main>'
            .'<aside>Kenar çubuğu</aside><footer>Telif hakkı 2026</footer></body></html>';
    }
}
