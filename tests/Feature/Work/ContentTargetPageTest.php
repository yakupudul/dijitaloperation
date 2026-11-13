<?php

namespace Tests\Feature\Work;

use App\Livewire\Operator\Work\WorkPage;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Site\ContentPlanner;
use App\Services\Site\ContentUpdateGoLive;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/**
 * yakup, 2026-10-12 ("mevcut sayfayı güçlendir adı altında paylaşılmış, ancak web sitesinde bu başlıkta yazı yok"):
 * an update idea names the page it strengthens, and a Turkish idea never points at a /de/ page or address.
 */
final class ContentTargetPageTest extends SiteTestCase
{
    public function test_an_update_card_names_the_page_it_strengthens(): void
    {
        $page = $this->page('/kanal-tedavisi-nedir/', 'Kanal tedavisi nedir, dişi kurtarmanın en etkili yolu');
        $idea = $this->idea('Kanal tedavisi adım adım nasıl yapılır?', ['kind' => 'update', 'target_url' => $page->url], $page->id);

        Livewire::test(WorkPage::class)->assertSeeHtml('data-target="'.$idea->id.'"')
            ->assertSee('Güncellenecek sayfa')->assertSee('Kanal tedavisi nedir, dişi kurtarmanın en etkili yolu')->assertSee('/kanal-tedavisi-nedir/');
    }

    public function test_the_reader_shows_the_page_it_replaces_and_what_changes(): void
    {
        $page = $this->page('/kanal-tedavisi-nedir/', 'Kanal tedavisi nedir', ['h1' => 'Kanal tedavisi nedir', 'meta_description' => 'Eski açıklama.', 'word_count' => 420,
            'content_outline' => "## Kanal tedavisi nedir\n\nDişin içi temizlenir.\n\n## Kanal tedavisi fiyatları\n\n- Diş sayısına göre değişir."]);
        $idea = $this->idea('Kanal tedavisi adım adım nasıl yapılır?', ['kind' => 'update', 'target_url' => $page->url, 'article' => ['title' => 'Kanal tedavisi adım adım nasıl yapılır?',
            'slug' => 'kanal', 'html' => '<h2>Kanal tedavisi nedir</h2><p>Dişin içi temizlenir.</p><h2>Adım adım tedavi</h2><p>Önce uyuşturma yapılır.</p>', 'meta_title' => 'x',
            'meta_description' => 'Yeni açıklama.', 'excerpt' => 'x', 'language' => 'tr', 'reference' => 'r']], $page->id, Suggestion::APPROVED);

        Livewire::test(WorkPage::class)->call('read', $idea->id)->assertSeeHtml('data-current-page="'.$idea->id.'"')
            ->assertSee('Ne değişecek')->assertSee('Eski açıklama.')->assertSee('Yeni açıklama.')->assertSee('+ Adım adım tedavi')
            ->assertSee('− Kanal tedavisi fiyatları')->assertSee('Şimdiki sayfanın metni')->assertSee('Diş sayısına göre değişir.')
            ->assertDontSeeHtml('data-article-image=');
    }

    public function test_pages_in_another_language_are_never_a_turkish_ideas_page(): void
    {
        $this->page('/', 'Ana sayfa');
        $german = $this->page('/de/sorular-ve-cevap/gibt-es-nachteile-beim-bonding/', 'Gibt es Nachteile beim Bonding?', ['language' => null]);
        $this->page('/blog/bonding-nedir/', 'Bonding nedir', ['wp_post_type' => 'post']);
        $this->assertFalse(ContentPlanner::inLanguage($german, 'tr'), 'an unknown language under /de/ is German');
        $this->assertTrue(ContentPlanner::inLanguage($german, 'de'));

        $update = $this->idea('Bonding\'in dezavantajları var mı?', ['kind' => 'update', 'target_url' => $german->url, 'article' => ['title' => 'x', 'html' => '<p>x</p>']], $german->id, Suggestion::APPROVED);
        $new = $this->idea('Magnezyum diş sıkmaya iyi gelir mi?', ['kind' => 'new', 'target_url' => 'https://panorama.com.tr/de/magnezyum-dis-sikmaya-iyi-gelir-mi/']);
        $fine = $this->idea('Bonding kaç seansta tamamlanır?', ['kind' => 'new', 'target_url' => 'https://panorama.com.tr/blog/bonding-kac-seansta-tamamlanir/']);

        $this->artisan('moxdop:content:fix-languages')->assertSuccessful();

        $update->refresh();
        $this->assertSame('new', $update->action['kind']);
        $this->assertNull($update->page_id);
        $this->assertSame(Suggestion::APPROVED, $update->status, 'the approval and the article stay');
        $this->assertSame('x', $update->action['article']['title']);
        $this->assertStringNotContainsString('/de/', (string) $update->action['target_url']);
        $this->assertStringNotContainsString('/de/', (string) $new->fresh()->action['target_url']);
        $this->assertSame('https://panorama.com.tr/blog/bonding-kac-seansta-tamamlanir/', $fine->fresh()->action['target_url']);
    }

    public function test_an_update_goes_live_on_one_click_without_waiting_as_a_wordpress_draft(): void
    {
        $idea = $this->updateIdea();

        Livewire::test(WorkPage::class)->call('read', $idea->id)->assertSee('Sayfaya uygula')->assertDontSee('WordPress\'e taslak gönder')->call('sendContent', $idea->id);

        $draft = ExternalWriteAction::query()->sole();
        $this->assertSame(ExternalWriteAction::ACTION_CONTENT_DRAFT, $draft->action);
        $this->assertSame(321, $draft->request_payload['object_id']);
        $this->assertSame('Kanal tedavisi adım adım nasıl yapılır?', $draft->request_payload['title']);
        Livewire::test(WorkPage::class)->call('setStep', 'taslakta')->assertSee('Canlı sayfaya uygulanıyor')->assertDontSeeHtml('data-apply-page="'.$idea->id.'"');

        // WordPress confirmed the copy: it replaces the live page at once (yakup 2026-11-02: no second draft to sort out).
        $draft->forceFill(['status' => 'succeeded', 'result' => ['post_id' => 900, 'status' => 'succeeded']])->save();
        app(ContentUpdateGoLive::class)->draftFinished($draft->fresh());
        app(ContentUpdateGoLive::class)->draftFinished($draft->fresh());

        $apply = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_CONTENT_APPLY)->sole();
        $this->assertSame(900, $apply->request_payload['draft_id']);
        $this->assertTrue(ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_SITE_FIX)->exists(), 'SEO title and description go to the page');
        $this->assertSame(Suggestion::APPLIED, $idea->fresh()->status);
        Livewire::test(WorkPage::class)->call('setStep', 'yayinda')->assertSee('Sayfaya uygulandı');
    }

    public function test_a_copy_sent_before_the_change_still_goes_live_by_admin_click(): void
    {
        $idea = $this->updateIdea();
        Livewire::test(WorkPage::class)->call('sendContent', $idea->id);
        $draft = ExternalWriteAction::query()->sole();
        $draft->forceFill(['status' => 'succeeded', 'result' => ['post_id' => 900, 'status' => 'succeeded'], 'updated_at' => now()->subDay()])->saveQuietly();

        Livewire::test(WorkPage::class)->call('setStep', 'taslakta')->assertSee('canlıdaki sayfa henüz değişmedi')
            ->assertSeeHtml('data-apply-page="'.$idea->id.'"')->call('applyPageUpdate', $idea->id);

        $this->assertSame(900, ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_CONTENT_APPLY)->sole()->request_payload['draft_id']);
        $this->assertSame(Suggestion::APPLIED, $idea->fresh()->status);
    }

    private function updateIdea(): Suggestion
    {
        Queue::fake();
        $connection = CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.14.0']]);
        CoreConnectionCredential::factory()->create(['connection_id' => $connection->id, 'encrypted_payload' => ['client_id' => 'c', 'shared_secret' => str_repeat('s', 43)]]);
        $page = $this->page('/kanal-tedavisi-nedir/', 'Kanal tedavisi nedir', ['wp_post_id' => 321, 'wp_post_type' => 'post']);

        return $this->idea('Kanal tedavisi adım adım nasıl yapılır?', ['kind' => 'update', 'target_url' => $page->url, 'article' => ['title' => 'Kanal tedavisi adım adım nasıl yapılır?',
            'slug' => 'kanal', 'html' => '<h2>Adımlar</h2><p>Önce uyuşturma yapılır.</p>', 'meta_title' => 'Kanal tedavisi adım adım', 'meta_description' => 'Adımlar.', 'excerpt' => 'x',
            'language' => 'tr', 'reference' => 'moxdop-kanal']], $page->id, Suggestion::APPROVED);
    }

    /** @param  array<string, mixed>  $action */
    private function idea(string $title, array $action, ?int $pageId = null, string $status = Suggestion::OPEN): Suggestion
    {
        return Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5($title), 'material_hash' => md5($title),
            'title' => $title, 'reason' => 'Sayfa eksik.', 'priority' => 2, 'evidence' => [], 'action_type' => 'content', 'page_id' => $pageId,
            'action' => array_merge(['site_id' => $this->site->id, 'page_type' => 'blog', 'language' => 'tr'], $action),
            'status' => $status, 'target_type' => $pageId !== null ? 'page' : 'site', 'target_id' => $pageId ?? $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);
    }
}
