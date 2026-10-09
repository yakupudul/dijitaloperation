<?php

namespace Tests\Feature\Website;

use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\Website\Pages\PageStore;
use App\Services\Website\Pages\WordPressPageSync;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** MoxDOP v2 Faz 1: the WordPress Connector snapshot and plugin events keep `pages` (one row per URL, latest only). */
final class PagesFromWordPressTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = DigitalAsset::factory()->create(['type' => 'website', 'status' => 'active', 'domain' => 'klinik.example', 'primary_url' => 'https://klinik.example/']);
    }

    public function test_published_posts_become_pages_with_main_content_seo_fields_and_language(): void
    {
        $this->seo(10, 'yoast', 'İmplant Tedavisi | Klinik', 'Ankara implant tedavisi hakkında her şey.', 'https://klinik.example/implant/', '');
        $this->seo(11, 'rank_math', 'Implant Treatment %sep% %sitename%', 'English description', null, 'noindex,nofollow');

        $stats = $this->sync()->syncContent($this->site->id, [
            $this->wpPost(10, 'https://klinik.example/implant/', 'İmplant', '<h2>Nedir?</h2><p>İmplant kayıp dişin yerine konan yapay köktür.</p><h3>Kimlere uygun?</h3><p>Çene kemiği yeterli olanlara.</p><h4>Not</h4>', 'tr'),
            $this->wpPost(11, 'https://klinik.example/en/implant/', 'Implant', '<h1>Dental implants</h1><p>Implants replace missing teeth.</p>', 'en'),
            $this->wpPost(12, 'https://klinik.example/taslak/', 'Taslak', '<p>x</p>', 'tr', 'draft'),
            ['object_type' => 'elementor_library', 'object_id' => '13', 'status' => 'publish', 'permalink' => 'https://klinik.example/?elementor_library=header', 'content_rendered' => '<p>x</p>'],
        ]);

        $this->assertSame(['created' => 2, 'updated' => 0, 'unchanged' => 0, 'deleted' => 0], $stats);
        $tr = Page::query()->where('wp_post_id', 10)->sole();
        $this->assertSame('https://klinik.example/implant/', $tr->url);
        $this->assertSame('/implant/', $tr->path);
        $this->assertSame('page', $tr->wp_post_type);
        $this->assertSame('tr', $tr->language);
        $this->assertSame('İmplant Tedavisi | Klinik', $tr->title);
        $this->assertSame('seo', $tr->title_source, 'the SEO plugin\'s own title');
        $this->assertSame('Ankara implant tedavisi hakkında her şey.', $tr->meta_description);
        $this->assertSame('https://klinik.example/implant/', $tr->canonical);
        $this->assertSame('İmplant', $tr->h1, 'themes print the post title as H1');
        $this->assertSame([['level' => 2, 'text' => 'Nedir?'], ['level' => 3, 'text' => 'Kimlere uygun?']], $tr->headings, 'H1–H3 only');
        $this->assertStringContainsString('İmplant kayıp dişin yerine konan yapay köktür.', $tr->content_text);
        $this->assertStringNotContainsString('<p>', $tr->content_text);
        $this->assertTrue($tr->is_indexable);
        $this->assertNull($tr->category, 'category is decided in Faz 4');
        $this->assertNull($tr->content_summary);
        $this->assertNull($tr->analyzed_at);
        $this->assertSame('2026-09-01 10:00:00', $tr->changed_at->utc()->format('Y-m-d H:i:s'));
        $this->assertGreaterThan(10, $tr->word_count);

        $en = Page::query()->where('wp_post_id', 11)->sole();
        $this->assertSame('Implant', $en->title, 'a title with template variables is not the rendered title');
        $this->assertSame('post', $en->title_source, 'the plugin renders "Implant | Site": the stored title is the post title');
        $this->assertSame('Dental implants', $en->h1, 'the content H1 wins');
        $this->assertFalse($en->is_indexable, 'noindex → not indexable');
        $this->assertSame('en', $en->language);
        $this->assertSame(2, Page::query()->count(), 'drafts and builder templates are not pages');
    }

    public function test_same_content_is_not_rewritten_and_a_change_resets_analysis(): void
    {
        $sync = $this->sync();
        $record = $this->wpPost(20, 'https://klinik.example/zirkonyum/', 'Zirkonyum', '<p>Zirkonyum kaplama.</p>');
        $sync->syncContent($this->site->id, [$record]);
        $page = Page::query()->sole();
        $page->forceFill(['analyzed_at' => now(), 'content_summary' => 'özet', 'category' => 'hizmet'])->save();
        $hash = $page->content_hash;

        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 1, 'deleted' => 0], $sync->syncContent($this->site->id, [$record]));
        $page->refresh();
        $this->assertNotNull($page->analyzed_at, 'no change → no re-analysis');
        $this->assertSame('özet', $page->content_summary);

        $record['content_rendered'] = '<p>Zirkonyum kaplama ve fiyatları.</p>';
        $record['modified_at'] = '2026-09-20T08:00:00+00:00';
        $this->assertSame(1, $sync->syncContent($this->site->id, [$record])['updated']);
        $page->refresh();
        $this->assertNotSame($hash, $page->content_hash);
        $this->assertNull($page->analyzed_at);
        $this->assertNull($page->content_summary);
        $this->assertSame('hizmet', $page->category, 'the category survives a content change');
        $this->assertSame('2026-09-20', $page->changed_at->utc()->toDateString());
    }

    public function test_seo_section_updates_title_description_and_indexability_of_stored_pages(): void
    {
        $sync = $this->sync();
        $sync->syncContent($this->site->id, [$this->wpPost(30, 'https://klinik.example/kanal/', 'Kanal Tedavisi', '<p>Kanal tedavisi.</p>')]);
        $page = Page::query()->sole();
        $this->assertSame('post', $page->title_source, 'no SEO title yet: the post title is stored');
        $page->forceFill(['analyzed_at' => now()])->save();

        $updated = $sync->syncSeo($this->site->id, [
            ['object_id' => '30', 'seo_provider' => 'seopress', 'seo_title' => 'Kanal Tedavisi Ankara', 'meta_description' => 'Ağrısız kanal tedavisi.', 'canonical_url' => null, 'robots' => 'yes'],
            ['object_id' => '999', 'seo_provider' => 'yoast', 'seo_title' => 'Başka', 'meta_description' => null, 'canonical_url' => null, 'robots' => null],
        ]);

        $this->assertSame(1, $updated);
        $page->refresh();
        $this->assertSame('Kanal Tedavisi Ankara', $page->title);
        $this->assertSame('seo', $page->title_source);
        $this->assertSame('Ağrısız kanal tedavisi.', $page->meta_description);
        $this->assertFalse($page->is_indexable, 'SEOPress robots index = yes means noindex');
        $this->assertNull($page->analyzed_at);
        $this->assertSame(1, Page::query()->count(), 'SEO rows never create pages');
    }

    public function test_url_change_keeps_the_row_and_removal_events_delete_the_page(): void
    {
        $sync = $this->sync();
        $sync->syncContent($this->site->id, [$this->wpPost(40, 'https://klinik.example/eski-adres/', 'Ortodonti', '<p>Tel tedavisi.</p>')]);
        $id = Page::query()->value('id');

        // Slug changed (content.updated with post_name): the changed-object refresh returns the new permalink.
        $sync->syncContent($this->site->id, [$this->wpPost(40, 'https://klinik.example/ortodonti/', 'Ortodonti', '<p>Tel tedavisi.</p>')], [40]);

        $page = Page::query()->sole();
        $this->assertSame($id, $page->id, 'same row, new URL');
        $this->assertSame('https://klinik.example/ortodonti/', $page->url);
        $this->assertSame(PageStore::urlHash('https://klinik.example/ortodonti/'), $page->url_hash);

        $result = $sync->applyEvents($this->site->id, [(object) ['type' => 'content.trashed', 'object_type' => 'page', 'object_id' => '40']]);
        $this->assertSame(['deleted' => 1, 'touched' => 0], $result);
        $this->assertSame(0, Page::query()->count());
    }

    public function test_unpublished_or_permanently_deleted_objects_leave_on_the_changed_object_refresh(): void
    {
        $sync = $this->sync();
        $sync->syncContent($this->site->id, [
            $this->wpPost(50, 'https://klinik.example/a/', 'A', '<p>a</p>'),
            $this->wpPost(51, 'https://klinik.example/b/', 'B', '<p>b</p>'),
        ]);

        // Object 50 came back as a draft, object 51 is gone from WordPress (not returned at all).
        $stats = $sync->syncContent($this->site->id, [$this->wpPost(50, 'https://klinik.example/a/', 'A', '<p>a</p>', 'tr', 'draft')], [50, 51]);

        $this->assertSame(2, $stats['deleted']);
        $this->assertSame(0, Page::query()->count());
    }

    public function test_template_change_marks_every_page_changed_without_refetch(): void
    {
        $sync = $this->sync();
        $sync->syncContent($this->site->id, [
            $this->wpPost(60, 'https://klinik.example/a/', 'A', '<p>a</p>'),
            $this->wpPost(61, 'https://klinik.example/b/', 'B', '<p>b</p>'),
        ]);
        Page::query()->update(['changed_at' => CarbonImmutable::parse('2026-01-01')]);
        $hashes = Page::query()->pluck('content_hash')->all();

        $result = $sync->applyEvents($this->site->id, [(object) ['type' => 'maintenance.theme_changed', 'object_type' => 'theme', 'object_id' => 'astra']]);

        $this->assertSame(['deleted' => 0, 'touched' => 2], $result);
        $this->assertSame(0, Page::query()->whereDate('changed_at', '2026-01-01')->count());
        $this->assertSame($hashes, Page::query()->pluck('content_hash')->all(), 'nothing refetched, content unchanged');
        $this->assertTrue(WordPressPageSync::isTemplateChange((object) ['type' => 'maintenance.update_completed', 'object_type' => 'theme']));
        $this->assertFalse(WordPressPageSync::isTemplateChange((object) ['type' => 'maintenance.update_completed', 'object_type' => 'plugin']));
    }

    public function test_full_inventory_prunes_pages_no_longer_published(): void
    {
        $sync = $this->sync();
        $sync->syncContent($this->site->id, [
            $this->wpPost(70, 'https://klinik.example/a/', 'A', '<p>a</p>'),
            $this->wpPost(71, 'https://klinik.example/b/', 'B', '<p>b</p>'),
        ]);
        Page::query()->create(['website_asset_id' => $this->site->id, 'url' => 'https://klinik.example/sitemap-only/', 'url_hash' => PageStore::urlHash('https://klinik.example/sitemap-only/'), 'path' => '/sitemap-only/']);
        $this->objectSnapshot(70, 'publish');
        $this->objectSnapshot(71, 'trash');

        $this->assertSame(2, $sync->pruneAfterFullInventory($this->site->id));
        $this->assertSame([70], Page::query()->pluck('wp_post_id')->all());
    }

    private function sync(): WordPressPageSync
    {
        return app(WordPressPageSync::class);
    }

    /** @return array<string, mixed> */
    private function wpPost(int $id, string $url, string $title, string $html, string $language = 'tr', string $status = 'publish'): array
    {
        return [
            'object_type' => 'page', 'object_id' => (string) $id, 'status' => $status, 'slug' => Str::slug($title),
            'permalink' => $url, 'title' => $title, 'modified_at' => '2026-09-01T10:00:00+00:00', 'language' => $language,
            'content_raw' => $html, 'content_rendered' => $html,
        ];
    }

    private function seo(int $id, string $provider, ?string $title, ?string $description, ?string $canonical, ?string $robots): void
    {
        DB::table('website_cms_seo_snapshot')->insert([
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => (string) $id,
            'seo_provider' => $provider, 'seo_title' => $title, 'meta_description' => $description, 'canonical_url' => $canonical,
            'robots' => $robots, 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }

    private function objectSnapshot(int $id, string $status): void
    {
        DB::table('website_cms_object_snapshot')->insert([
            'digital_asset_id' => $this->site->id, 'cms' => 'wordpress', 'object_type' => 'page', 'object_id' => (string) $id,
            'status' => $status, 'observed_at' => now(), 'contract_version' => 1, 'first_collected_at' => now(),
            'last_collected_at' => now(), 'record_fingerprint' => Str::random(20),
        ]);
    }
}
