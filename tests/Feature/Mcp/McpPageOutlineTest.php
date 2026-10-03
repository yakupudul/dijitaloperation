<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\GetPage;
use App\Models\Page;
use App\Services\Website\Pages\MainContentExtractor;
use App\Services\Website\Pages\PageStore;
use Tests\Feature\Site\SiteTestCase;

/**
 * Pages keep their main content as a light Markdown outline (headings, paragraphs, lists, tables, questions) that AI
 * operations and Claude read instead of flat text; filling it in never counts as a new page version.
 */
final class McpPageOutlineTest extends SiteTestCase
{
    public function test_the_main_content_becomes_a_markdown_outline(): void
    {
        $html = '<header><nav><a href="/">Menü</a></nav></header><main>'
            .'<h1>İmplant <em>Tedavisi</em></h1><p>İmplant <strong>tek seansta</strong> yapılmaz, <a href="/fiyat/">fiyatlar</a> sayfaya göre değişir.</p>'
            .'<h2>Aşamalar</h2><ol><li>Muayene</li><li>Cerrahi<ul><li>Lokal anestezi</li></ul></li></ol>'
            .'<table><tr><th>Aşama</th><th>Süre</th></tr><tr><td>Cerrahi</td><td>1 saat</td></tr></table>'
            .'<details><summary>Ağrı olur mu?</summary><p>Hafif sızı olabilir.</p></details>'
            .'<img src="/a.jpg" alt="İmplant modeli"><div>Serbest metin <a href="#top">yukarı</a></div></main><footer>Telif</footer>';

        $content = (new MainContentExtractor)->fromDocument('<html><head><title>T</title></head><body>'.$html.'</body></html>', 'https://site.example/implant/');

        $this->assertSame(implode("\n\n", [
            '# İmplant Tedavisi',
            'İmplant tek seansta yapılmaz, [fiyatlar](/fiyat/) sayfaya göre değişir.',
            '## Aşamalar',
            "1. Muayene\n2. Cerrahi\n  - Lokal anestezi",
            "| Aşama | Süre |\n| --- | --- |\n| Cerrahi | 1 saat |",
            "S: Ağrı olur mu?\nC: Hafif sızı olabilir.",
            '[görsel: İmplant modeli]',
            'Serbest metin yukarı',
        ]), $content['content_outline']);
        $this->assertStringNotContainsString('Menü', $content['content_outline']);
        // The flat text and headings stay as they were.
        $this->assertStringContainsString('İmplant tek seansta yapılmaz', $content['content_text']);
        $this->assertSame('İmplant Tedavisi', $content['h1']);
    }

    public function test_filling_in_the_outline_is_not_a_new_version_and_seo_refresh_keeps_it(): void
    {
        $store = app(PageStore::class);
        $fields = ['url' => 'https://panorama.com.tr/implant/', 'title' => 'İmplant', 'h1' => 'İmplant', 'content_text' => 'İmplant yapılır.', 'word_count' => 2];
        $this->assertSame(PageStore::CREATED, $store->upsert($this->site->id, $fields));
        $page = Page::query()->where('website_asset_id', $this->site->id)->firstOrFail();
        $page->forceFill(['content_summary' => 'özet', 'analyzed_at' => now()])->save();
        $changedAt = $page->changed_at;

        $this->assertSame(PageStore::UNCHANGED, $store->upsert($this->site->id, $fields + ['content_outline' => "# İmplant\n\nİmplant yapılır."]));
        $page->refresh();
        $this->assertSame("# İmplant\n\nİmplant yapılır.", $page->content_outline);
        $this->assertSame('özet', $page->content_summary);
        $this->assertEquals($changedAt, $page->changed_at);

        // An SEO-only refresh passes no outline: the stored one stays.
        $this->assertSame(PageStore::UPDATED, $store->upsert($this->site->id, ['title' => 'İmplant Tedavisi'] + $fields));
        $this->assertSame("# İmplant\n\nİmplant yapılır.", $page->refresh()->content_outline);
    }

    public function test_ai_text_prefers_the_outline_and_cuts_at_a_paragraph(): void
    {
        $page = new Page(['content_text' => 'düz metin', 'content_outline' => "## A\n\n".str_repeat('x', 40)."\n\n".str_repeat('y', 40)]);
        $this->assertSame("## A\n\n".str_repeat('x', 40), $page->aiText(60));
        $this->assertSame('düz metin', (new Page(['content_text' => 'düz metin']))->aiText(100));
    }

    public function test_get_page_reads_a_stored_page_of_the_brand_only(): void
    {
        $page = $this->page('/implant/', 'İmplant Tedavisi', ['content_outline' => "# İmplant Tedavisi\n\n## Süre\n\nBir saat."]);

        MoxdopServer::tool(GetPage::class, ['brand_id' => $this->brand->id, 'url' => 'https://panorama.com.tr/implant/'])->assertOk()
            ->assertSee('"format":"markdown"')->assertSee('## Süre');
        MoxdopServer::tool(GetPage::class, ['brand_id' => $this->brand->id, 'page_id' => $page->id])->assertOk()->assertSee('İmplant Tedavisi');
        MoxdopServer::tool(GetPage::class, ['brand_id' => $this->brand->id, 'url' => 'https://baska.example/'])->assertHasErrors();
    }
}
