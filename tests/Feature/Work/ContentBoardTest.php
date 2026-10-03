<?php

namespace Tests\Feature\Work;

use App\Ai\Agents\Site\WriteArticleAgent;
use App\Jobs\Site\RunSiteOperationJob;
use App\Livewire\Operator\Website\V2\ContentTab;
use App\Livewire\Operator\Work\WorkPage;
use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Services\Site\ContentPlanner;
use App\Services\Work\WorkDesk;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Site\SiteTestCase;

/** Genel işler › İçerik fikirleri: one box per site, Yaz → Oku → Taslak gönder, written in the site's languages. */
final class ContentBoardTest extends SiteTestCase
{
    public function test_one_click_write_read_and_send_with_a_linked_translation(): void
    {
        $this->enableAi();
        Queue::fake();
        CoreConnection::factory()->create(['digital_asset_id' => $this->site->id, 'type' => 'wordpress_connector', 'enabled' => true,
            'config' => ['pairing_state' => 'paired', 'snapshot_url' => 'https://panorama.com.tr/wp-json/moxdop/v1/snapshot', 'plugin_version' => '1.5.0']]);
        $this->page('/implant/', 'Ankara İmplant Tedavisi', ['category' => 'hizmet']);
        $this->page('/blog/implant-sonrasi/', 'İmplant sonrası', ['category' => 'blog']);
        $this->page('/en/dental-implant/', 'Dental Implant', ['category' => 'hizmet', 'language' => 'en']);
        $idea = Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5('idea'), 'material_hash' => md5('idea'),
            'title' => 'İmplant sonrası ilk hafta', 'reason' => 'Kümenin sayfası yok.', 'priority' => 1, 'evidence' => [], 'action_type' => 'content',
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog', 'outline' => ['Giriş'], 'questions' => []],
            'status' => Suggestion::OPEN, 'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        Livewire::test(WorkPage::class)->assertSee('İçerik fikirleri')->assertSee('panorama.com.tr')->assertSee('İmplant sonrası ilk hafta')
            ->assertSeeHtml('data-site-languages')->assertSee('EN')->assertSeeHtml('data-write="'.$idea->id.'"')
            ->call('writeContent', $idea->id)->assertSee('Okunacak\'a düşer');
        $this->assertSame(Suggestion::APPROVED, $idea->fresh()->status, 'Yaz approves the title');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => ($job->params['suggestion_id'] ?? null) === $idea->id);

        $article = fn (string $title): array => ['title' => $title, 'slug' => 'implant', 'meta_title' => $title, 'meta_description' => 'İlk hafta.',
            'excerpt' => 'Rehber.', 'html' => '<h2>İlk gün</h2><p>Soğuk uygulama yapılır.</p>'];
        WriteArticleAgent::fake([$article('İmplant sonrası ilk hafta'), $article('The first week after an implant')]);
        $this->assertSame(['status' => 'ready'], app(ContentPlanner::class)->writeArticle($idea->fresh()));
        Cache::flush(); // the queued job would have marked the run finished
        $this->assertSame('tr', $idea->fresh()->action['language']);

        Livewire::test(WorkPage::class)->call('setStep', 'okunacak')->assertSeeHtml('data-read="'.$idea->id.'"')->call('read', $idea->id)
            ->assertSeeHtml('data-reader="'.$idea->id.'"')->assertSee('Soğuk uygulama yapılır.')->assertSeeHtml('data-translate="en"')
            ->call('writeContent', $idea->id, 'en')->assertSee('İngilizce çevirisi yazılıyor');
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => ($job->params['language'] ?? null) === 'en');

        $this->assertSame(['status' => 'ready'], app(ContentPlanner::class)->writeArticle($idea->fresh(), 'en'));
        Cache::flush();
        $action = $idea->fresh()->action;
        $this->assertSame('İmplant sonrası ilk hafta', $action['article']['title'], 'the source stays');
        $this->assertSame(['en'], array_keys($action['translations']));
        $this->assertSame('en', $action['translations']['en']['language']);

        Livewire::test(WorkPage::class)->call('read', $idea->id)->assertSee('The first week after an implant')->assertSee('(TR + EN)')
            ->call('sendContent', $idea->id)->assertSee('WordPress taslağı kuyruğa alındı');
        $write = ExternalWriteAction::query()->sole();
        $this->assertSame(['tr', 'en'], $write->request_payload['languages'], 'sent together, linked');
        $this->assertSame(['tr', 'en'], $idea->fresh()->action['sent_languages']);
        $this->assertSame(0, app(WorkDesk::class)->counts()['icerik'], 'a sent idea is no longer open work');
        Livewire::test(WorkPage::class, ['step' => 'gonderildi'])->assertSee('Taslak gönderildi');
    }

    public function test_all_waiting_titles_of_a_site_are_approved_in_one_click_and_the_site_box_is_on_the_content_tab(): void
    {
        Queue::fake();
        $this->page('/implant/', 'Ankara İmplant Tedavisi');
        $ideas = collect(['Bir', 'İki', 'Üç'])->map(fn (string $t): Suggestion => Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5($t), 'material_hash' => md5($t),
            'title' => 'İmplant rehberi '.$t, 'reason' => 'x', 'priority' => 2, 'evidence' => [], 'action_type' => 'content',
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog'], 'status' => Suggestion::OPEN,
            'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]));

        Livewire::test(WorkPage::class)->assertSee('Onay bekleyen başlıklar')->assertSeeHtml('data-write-all="'.$this->site->id.'"')
            ->call('writeAll', $this->site->id)->assertSee('3 başlık onaylandı');
        $this->assertSame([Suggestion::APPROVED], $ideas->map(fn (Suggestion $s): string => $s->fresh()->status)->unique()->values()->all());
        Queue::assertPushed(RunSiteOperationJob::class, 3);

        Livewire::test(ContentTab::class, ['assetId' => $this->site->id])->assertSeeHtml('data-content-box="'.$this->site->id.'"')
            ->assertSee('İmplant rehberi Bir')->call('read', 999999)->assertSet('reading', null);
    }

    public function test_a_foreign_language_can_be_picked_before_writing_and_unknown_languages_are_refused(): void
    {
        Queue::fake();
        $this->page('/implant/', 'Ankara İmplant Tedavisi');
        $this->page('/de/implantat/', 'Zahnimplantat', ['language' => 'de']);
        $idea = Suggestion::query()->create([
            'brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.content', 'fingerprint' => md5('de'), 'material_hash' => md5('de'),
            'title' => 'Implantat in Ankara', 'reason' => 'x', 'priority' => 3, 'evidence' => [], 'action_type' => 'content',
            'action' => ['site_id' => $this->site->id, 'kind' => 'new', 'page_type' => 'blog'], 'status' => Suggestion::OPEN,
            'target_type' => 'site', 'target_id' => $this->site->id, 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        Livewire::test(WorkPage::class)->call('writeContent', $idea->id, 'fr')->assertSee('Bu dil sitede yok')
            ->set('languages.'.$idea->id, 'de')->call('writeContent', $idea->id)->assertSee('(Almanca)');
        $this->assertSame('de', $idea->fresh()->action['language']);
        Queue::assertPushed(RunSiteOperationJob::class, fn (RunSiteOperationJob $job): bool => ! isset($job->params['language']));
    }
}
