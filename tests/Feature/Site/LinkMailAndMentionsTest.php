<?php

namespace Tests\Feature\Site;

use App\Jobs\Site\WriteLinkPack;
use App\Livewire\Operator\Links\LinkDeskPage;
use App\Mail\LinkOutreachMail;
use App\Models\BacklinkSource;
use App\Models\LinkMessage;
use App\Models\LinkSource;
use App\Services\Site\Backlinks\BacklinkVerifier;
use App\Services\Site\Backlinks\LinkMailer;
use App\Services\Site\Backlinks\LinkMentions;
use App\Services\Site\Backlinks\LinkNapCheck;
use App\Services\Website\PageFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;

/**
 * Backlink hizmeti, adım 3 (2026-11-06): a ready e-mail leaves the desk with the operator's click, one follow-up is
 * offered after a week, rows that brought nothing leave the desk; Google Alerts pages that name the brand without a
 * link become anılma rows; a verified listing is checked against the Business Profile's phone and address.
 */
final class LinkMailAndMentionsTest extends SiteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Mail::fake();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
    }

    public function test_the_operator_sends_the_mail_and_one_follow_up_and_silent_rows_leave_the_desk(): void
    {
        $pool = LinkSource::query()->create(['domain' => 'haber.example', 'name' => 'Yerel Haber', 'url' => 'https://haber.example/', 'kind' => 'yerel_haber',
            'quality' => 'uygun', 'contact_email' => 'editor@haber.example']);
        $row = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Yerel Haber', 'url' => 'https://haber.example/', 'domain' => 'haber.example',
            'status' => BacklinkSource::NONE, 'link_source_id' => $pool->id, 'engine' => 'anilma', 'material_status' => 'ready',
            'material' => ['email_subject' => 'Haberiniz hakkında', 'email_body' => "Merhaba,\nTeşekkürler.\nPanorama Ankara adına"]]);
        $form = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Rehber', 'url' => 'https://rehber.example/', 'domain' => 'rehber.example',
            'status' => BacklinkSource::NONE, 'engine' => 'kunye', 'material_status' => 'ready', 'material' => ['steps' => ['Formu doldur']]]);

        Livewire::test(LinkDeskPage::class)->assertSee('1 e-postayı gönder')->call('sendMail', $row->id)->assertSee('e-posta gönderildi');
        Mail::assertSent(LinkOutreachMail::class, fn (LinkOutreachMail $m): bool => $m->hasTo('editor@haber.example') && $m->mailSubject === 'Haberiniz hakkında');
        $row->refresh();
        $this->assertSame(BacklinkSource::APPLIED, $row->status);
        $this->assertSame(['ilk', 'editor@haber.example'], [$row->messages()->sole()->kind, $row->messages()->sole()->to]);
        $this->assertSame(1, $pool->fresh()->applied_count);
        app(\App\Services\Site\Backlinks\LinkDesk::class)->sent($form);

        $this->assertFalse(LinkMailer::followUpDue($row->fresh()), 'no follow-up in the first week');
        $this->travel(8)->days();
        $this->assertTrue(LinkMailer::followUpDue($row->fresh()));
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        $this->assertFalse(LinkMailer::followUpDue($form->fresh()), 'a form application has no mail to follow up');
        Livewire::test(LinkDeskPage::class)->call('toggle', $row->id)->assertSee('Hatırlatmayı gönder')->call('sendFollowUp', $row->id)->assertSee('hatırlatma gönderildi');
        $this->assertSame(['ilk', 'takip'], LinkMessage::query()->orderBy('id')->pluck('kind')->all());
        $this->assertNotNull($row->fresh()->followup_at);
        $this->assertFalse(LinkMailer::followUpDue($row->fresh()), 'one follow-up only');

        $this->travel(14)->days();
        $this->assertSame(1, LinkMailer::giveUp());
        $this->assertSame([BacklinkSource::DROPPED, BacklinkSource::APPLIED], [$row->fresh()->status, $form->fresh()->status], 'a directory gets longer to approve');
        $this->travel(24)->days();
        $this->assertSame(1, LinkMailer::giveUp());
        $this->assertSame(BacklinkSource::DROPPED, $form->fresh()->status);
    }

    public function test_a_row_without_an_address_takes_the_one_the_operator_types(): void
    {
        $pool = LinkSource::query()->create(['domain' => 'dernek.example', 'name' => 'Dernek', 'url' => 'https://dernek.example/', 'kind' => 'dernek', 'quality' => 'uygun']);
        $row = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Dernek', 'url' => 'https://dernek.example/', 'domain' => 'dernek.example',
            'status' => BacklinkSource::NONE, 'link_source_id' => $pool->id, 'material_status' => 'ready', 'material' => ['email_body' => 'Merhaba']]);
        $this->assertFalse(LinkMailer::mailReady($row));

        Livewire::test(LinkDeskPage::class)->call('toggle', $row->id)->set('recipient.'.$row->id, 'yanlis')->call('setRecipient', $row->id)->assertHasErrors('recipient.'.$row->id)
            ->set('recipient.'.$row->id, 'info@dernek.example')->call('setRecipient', $row->id)->assertHasNoErrors();

        $this->assertTrue(LinkMailer::mailReady($row->fresh()));
        $this->assertSame('info@dernek.example', $pool->fresh()->contact_email, 'the pool learns the address');
    }

    public function test_alert_pages_that_name_the_brand_without_a_link_become_mention_rows(): void
    {
        $feed = 'https://www.google.com/alerts/feeds/123/456';
        Http::fake([$feed => Http::response('<?xml version="1.0"?><feed xmlns="http://www.w3.org/2005/Atom">'
            .'<entry><link href="https://www.google.com/url?rct=j&amp;sa=t&amp;url=https://www.haber.example/saglik/yeni-klinik&amp;ct=ga"/></entry>'
            .'<entry><link href="https://www.google.com/url?url=https://linkli.example/yazi"/></entry>'
            .'<entry><link href="https://www.google.com/url?url=https://www.instagram.com/p/x"/></entry>'
            .'<entry><link href="https://www.google.com/url?url=https://baska.example/yazi"/></entry></feed>')]);
        $fetcher = Mockery::mock(PageFetcher::class);
        $fetcher->shouldReceive('fetch')->andReturnUsing(fn (string $url): array => ['html' => match (true) {
            str_contains($url, 'haber.example') => '<p>Panorama Ankara yeni şubesini açtı.</p><a href="mailto:Editor@Haber.example">Bize yazın</a> <a href="mailto:x@gmail.com">x</a>',
            str_contains($url, 'linkli') => '<p>Panorama Ankara</p><a href="https://panorama.com.tr/">site</a>',
            default => '<p>Başka bir klinik</p>',
        }]);
        $this->app->instance(PageFetcher::class, $fetcher);

        Livewire::test(LinkDeskPage::class)->set('tab', 'hedefler')->set('alertFeed.'.$this->brand->id, 'https://example.com/rss')->call('setAlertFeed', $this->brand->id)
            ->assertHasErrors('alertFeed.'.$this->brand->id)
            ->set('alertFeed.'.$this->brand->id, $feed)->call('setAlertFeed', $this->brand->id)->assertSee('1 anılma satırı eklendi');

        $row = BacklinkSource::query()->where('brand_id', $this->brand->id)->sole();
        $this->assertSame(['anilma', 'queued', 'editor@haber.example', 'https://www.haber.example/saglik/yeni-klinik'],
            [$row->engine, $row->material_status, $row->material['to'], $row->url]);
        Queue::assertPushed(WriteLinkPack::class, 1);
        $this->assertNotNull($this->brand->fresh()->link_alert_read_at);

        // Read again the next morning: nothing is added twice.
        $this->assertSame(0, app(LinkMentions::class)->read($this->brand->fresh())['added']);
    }

    public function test_a_listing_is_compared_with_the_business_profile(): void
    {
        $profiles = [['phone' => '0312 555 44 33', 'address_text' => 'Kızılay Mahallesi, Atatürk Bulvarı No:10, Çankaya, Ankara']];
        $html = '<h1>Panorama Ankara</h1><p>Tel: 0312 555 00 00</p><p>Atatürk Bulvarı, Ankara</p>';

        $nap = LinkNapCheck::compare($profiles, $html);

        $this->assertSame(['farkli', 'eksik', '3125554433'], [$nap['phone'], $nap['address'], $nap['expected_phone']]);
        $row = new BacklinkSource(['nap' => $nap]);
        $this->assertSame('İşletme Profili ile farklı: telefon farklı (3125550000; doğrusu 03125554433), adres eksik. Kayıttan düzeltin.', LinkNapCheck::line($row));
        $same = LinkNapCheck::compare($profiles, '<p>0 (312) 555 44 33</p><p>Kızılay Mah. Atatürk Bulvarı No:10 Çankaya / Ankara</p>');
        $this->assertNull(LinkNapCheck::line(new BacklinkSource(['nap' => $same])));
        $this->assertNull(LinkNapCheck::compare([], $html), 'no Business Profile, no check');

        // The verifier runs it on a listing it finds (no profile here: nothing is stored).
        $fetcher = Mockery::mock(PageFetcher::class);
        $fetcher->shouldReceive('fetch')->andReturn(['html' => $html.'<a href="https://panorama.com.tr/">site</a>']);
        $this->app->instance(PageFetcher::class, $fetcher);
        $listing = BacklinkSource::query()->create(['brand_id' => $this->brand->id, 'name' => 'Rehber', 'url' => 'https://rehber.example/', 'domain' => 'rehber.example',
            'status' => BacklinkSource::GIVEN, 'link_url' => 'https://rehber.example/panorama', 'engine' => 'kunye', 'kind' => 'dizin']);
        $this->assertSame(BacklinkVerifier::FOUND, app(BacklinkVerifier::class)->verify($listing));
        $this->assertNull($listing->fresh()->nap);
    }
}
