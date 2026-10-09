<?php

namespace Tests\Feature\Repair;

use App\Ai\Agents\Site\SeoFieldsBatchAgent;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\SeoFieldsBatch;
use App\Services\Repair\SiteAudit;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Site\SiteTestCase;

/**
 * Title rules of the nightly page check: a post title the SEO plugin wraps in "%title% | Site" is not judged by its
 * length, translations are not duplicates of each other, and a new title never drops the brand / area of the old one.
 */
final class SeoTitleRulesTest extends SiteTestCase
{
    private const string DESCRIPTION = 'Çankaya şubemizde implant tedavisi süreci, kimlere uygun olduğu ve randevu adımları bu sayfada anlatılıyor.';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_post_title_wrapped_by_the_seo_plugin_template_is_not_called_short(): void
    {
        $post = $this->wpPage('/implant/', 'İmplant', 42, 'post');
        $seo = $this->wpPage('/zirkonyum/', 'Zirkonyum', 43, 'seo');
        $longPost = $this->wpPage('/kopru/', str_repeat('Diş köprüsü tedavisi Çankaya ', 3), 44, 'post');

        app(SiteAudit::class)->audit($this->site);

        $rows = Suggestion::query()->where('action_type', SiteAudit::TYPE)->get()->keyBy('page_id');
        $this->assertFalse($rows->has($post->id), '"İmplant" is shown as "İmplant | Panorama Ankara": its length says nothing');
        $this->assertFalse($rows->has($longPost->id));
        $this->assertSame('Başlık 9 karakter (en az 25).', $rows[$seo->id]->reason, 'the SEO plugin\'s own title is checked');
    }

    public function test_a_translation_with_the_same_title_is_not_a_duplicate_but_the_same_language_is(): void
    {
        $title = 'All-on-4 İmplant Tedavisi | Panorama Ankara';
        $tr = $this->wpPage('/all-on-4-implant/', $title, 50, 'seo');
        $en = $this->wpPage('/en/all-on-4-implant/', $title, 51, 'seo', ['language' => 'en']);

        app(SiteAudit::class)->audit($this->site);
        $this->assertSame(0, Suggestion::query()->where('action_type', SiteAudit::TYPE)->count(), 'TR and its EN translation');

        $twin = $this->wpPage('/all-on-4-fiyat/', $title, 52, 'seo');
        app(SiteAudit::class)->audit($this->site);
        $rows = Suggestion::query()->where('action_type', SiteAudit::TYPE)->get()->keyBy('page_id');
        $this->assertSame([$tr->id, $twin->id], $rows->keys()->sort()->values()->all());
        $this->assertStringContainsString('Başlık 2 sayfada aynı', $rows[$tr->id]->reason);
        $this->assertFalse($rows->has($en->id));
    }

    public function test_a_new_title_keeps_the_brand_and_area_of_the_current_one(): void
    {
        $names = ['brand' => ['panorama', 'ankara'], 'areas' => ['cankaya', 'ankara']];
        $seo = $this->wpPage('/zirkonyum/', 'Zirkonyum Kaplama Çankaya | Panorama Ankara', 43, 'seo');
        $this->assertFalse(SeoFieldsBatch::keepsNames($seo, 'Zirkonyum Kaplama Fiyatları ve Süreci', $names), 'drops "Çankaya | Panorama Ankara"');
        $this->assertFalse(SeoFieldsBatch::keepsNames($seo, 'Zirkonyum Kaplama Çankaya Fiyatları', $names), 'drops the brand');
        $this->assertTrue(SeoFieldsBatch::keepsNames($seo, 'Zirkonyum Kaplama Çankaya | Panorama Ankara Diş', $names));

        $post = $this->wpPage('/implant/', 'İmplant', 42, 'post');
        $this->assertFalse(SeoFieldsBatch::keepsNames($post, 'İmplant tedavisi nasıl yapılır, kimlere uygun', $names),
            'the site shows "İmplant | Panorama Ankara" today: a literal title without the brand drops it');
        $this->assertTrue(SeoFieldsBatch::keepsNames($post, 'İmplant tedavisi süreci | Panorama Ankara', $names));
    }

    public function test_the_batch_tells_the_ai_the_title_source_and_refuses_a_title_without_the_brand(): void
    {
        $this->enableAi();
        $page = $this->wpPage('/implant/', 'İmplant', 42, 'post', ['meta_description' => null]);
        $suggestion = Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'repair.seo_fields',
            'fingerprint' => hash('sha256', 'title-rules'), 'material_hash' => hash('sha256', 'x'), 'title' => 'Başlık ve açıklamayı düzelt: /implant/',
            'reason' => 'Başlık 2 sayfada aynı.', 'priority' => 2, 'action_type' => SiteAudit::TYPE, 'status' => Suggestion::OPEN, 'page_id' => $page->id,
            'action' => ['site_id' => $this->site->id, 'problems' => ['seo_title:duplicate', 'meta_description:missing']], 'evidence' => []]);
        $prompts = [];
        SeoFieldsBatchAgent::fake(function (string $prompt) use (&$prompts, $suggestion): array {
            $prompts[] = $prompt;

            return ['pages' => [['id' => $suggestion->id, 'seo_title' => 'İmplant tedavisi süreci ve kimlere uygun olduğu', 'meta_description' => self::DESCRIPTION]]];
        });

        app(SeoFieldsBatch::class)->prepare($this->site, [$suggestion->id]);

        $this->assertStringContainsString('"title_source":"post"', $prompts[0]);
        $this->assertSame(['meta_description' => self::DESCRIPTION], $suggestion->fresh()->action['proposal']['new'], 'the title without "| Panorama Ankara" is left out');
    }

    /** @param  array<string, mixed>  $extra */
    private function wpPage(string $path, string $title, int $postId, string $source, array $extra = []): Page
    {
        $page = $this->page($path, $title, array_merge(['wp_post_id' => $postId, 'meta_description' => self::DESCRIPTION.' '.$path], $extra));
        $page->forceFill(['title_source' => $source])->save();

        return $page;
    }
}
