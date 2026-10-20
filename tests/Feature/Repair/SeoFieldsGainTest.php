<?php

namespace Tests\Feature\Repair;

use App\Models\Page;
use App\Models\Suggestion;
use App\Services\Repair\SiteAudit;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Site\SiteTestCase;

/**
 * yakup, 2026-10-11: one small site showed "Arama 10672" open items, nearly all "Başlık ve açıklamayı düzelt" rows for
 * pages Google never showed. The audit no longer opens a row without expected gain, never checks pagination / archive
 * by-products (they are not junk either), and closes such waiting rows itself until the page starts being seen.
 */
final class SeoFieldsGainTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    public function test_a_page_without_impressions_gets_no_row_unless_it_is_a_service_or_location_page(): void
    {
        $seen = $this->wpPage('/blog/goruldu/', 61, 'blog');
        $this->wpPage('/kw/bakir-boru-fiyatlari/', 62, 'diger');
        $service = $this->wpPage('/implant/', 63, 'hizmet');
        $this->fact('bakır boru', '/blog/goruldu/', 30, 1, 7.0, '2026-09-20');

        $result = app(SiteAudit::class)->audit($this->site);

        $pages = Suggestion::query()->where('action_type', SiteAudit::TYPE)->pluck('page_id')->all();
        $this->assertEqualsCanonicalizing([$seen->id, $service->id], $pages);
        $this->assertSame(2, $result['opened']);
    }

    public function test_a_page_seen_only_a_few_times_a_quarter_gets_no_row_either(): void
    {
        $barely = $this->wpPage('/kw/hurdaci-ali/', 64, 'diger');
        $enough = $this->wpPage('/blog/bakir-fiyati/', 65, 'blog');
        $this->fact('hurdacı ali', '/kw/hurdaci-ali/', SiteAudit::MIN_IMPRESSIONS - 1, 0, 40.0, '2026-09-20');
        $this->fact('bakır fiyatı', '/blog/bakir-fiyati/', SiteAudit::MIN_IMPRESSIONS, 0, 25.0, '2026-09-20');

        app(SiteAudit::class)->audit($this->site);

        $this->assertSame([$enough->id], Suggestion::query()->where('action_type', SiteAudit::TYPE)->pluck('page_id')->all());
        $this->assertFalse(Suggestion::query()->where('page_id', $barely->id)->exists());
    }

    public function test_without_search_console_data_every_page_is_still_checked(): void
    {
        $page = $this->wpPage('/kw/bakir-boru-fiyatlari/', 62, 'diger');

        app(SiteAudit::class)->audit($this->site);

        $this->assertTrue(Suggestion::query()->where('action_type', SiteAudit::TYPE)->where('page_id', $page->id)->exists());
    }

    public function test_pagination_is_never_checked_and_never_treated_as_junk(): void
    {
        $title = 'Blog yazıları ve haberler | Panorama Diş Kliniği';
        $first = $this->wpPage('/blog/', 70, 'blog', ['title' => $title]);
        $second = $this->wpPage('/blog/page/2/', 71, 'blog', ['title' => $title]);

        app(SiteAudit::class)->audit($this->site);

        $this->assertFalse(Suggestion::query()->where('page_id', $second->id)->exists(), 'no row of any kind for /page/2/');
        $reason = (string) Suggestion::query()->where('page_id', $first->id)->value('reason');
        $this->assertStringNotContainsString('sayfada aynı', $reason, 'the paginated copy does not make the title a duplicate');
    }

    public function test_a_waiting_row_without_gain_closes_itself_and_comes_back_once_the_page_is_seen(): void
    {
        $page = $this->wpPage('/kw/bakir-boru-fiyatlari/', 62, 'diger');
        $this->fact('başka', '/blog/x/', 5, 0, 9.0, '2026-09-20');
        $row = Suggestion::query()->create(['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'repair.seo_fields',
            'fingerprint' => hash('sha256', $this->brand->id.'|repair|'.$page->id.'|'.SiteAudit::TYPE), 'material_hash' => 'x',
            'title' => 'Başlık ve açıklamayı düzelt: '.$page->path, 'reason' => 'Meta açıklama yok.', 'priority' => 5, 'action_type' => SiteAudit::TYPE,
            'target_type' => 'page', 'target_id' => $page->id, 'page_id' => $page->id, 'status' => Suggestion::OPEN,
            'action' => ['site_id' => $this->site->id, 'problems' => ['meta_description:missing']], 'evidence' => []]);

        app(SiteAudit::class)->audit($this->site);

        $row->refresh();
        $this->assertSame(Suggestion::DISMISSED, $row->status);
        $this->assertSame(SiteAudit::NO_GAIN, data_get($row->action, 'auto_closed'));

        $this->fact('bakır boru fiyatları', '/kw/bakir-boru-fiyatlari/', 12, 0, 18.0, '2026-09-20');
        app(SiteAudit::class)->audit($this->site);

        $row->refresh();
        $this->assertSame(Suggestion::OPEN, $row->status);
        $this->assertNull(data_get($row->action, 'auto_closed'));
    }

    public function test_an_operator_rejection_is_never_reopened(): void
    {
        $page = $this->wpPage('/implant/', 63, 'hizmet');
        app(SiteAudit::class)->audit($this->site);
        $row = Suggestion::query()->where('page_id', $page->id)->firstOrFail();
        $row->forceFill(['status' => Suggestion::DISMISSED])->save();

        app(SiteAudit::class)->audit($this->site);

        $this->assertSame(Suggestion::DISMISSED, $row->fresh()->status);
    }

    /** @param  array<string, mixed>  $extra */
    private function wpPage(string $path, int $postId, string $category, array $extra = []): Page
    {
        $title = (string) ($extra['title'] ?? 'Sayfa '.$postId.' başlığı yeterince uzun | Panorama');
        unset($extra['title']);

        return $this->page($path, $title, array_merge(['wp_post_id' => $postId, 'title_source' => 'seo', 'category' => $category, 'meta_description' => null], $extra));
    }
}
