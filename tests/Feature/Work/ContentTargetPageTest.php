<?php

namespace Tests\Feature\Work;

use App\Livewire\Operator\Work\WorkPage;
use App\Models\Suggestion;
use App\Services\Site\ContentPlanner;
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
