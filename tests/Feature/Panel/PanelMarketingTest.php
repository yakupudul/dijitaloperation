<?php

namespace Tests\Feature\Panel;

use App\Livewire\Panel\MarketingPage;
use App\Models\BrandSession;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Models\User;
use App\Panel\Models\PanelTicket;
use App\Panel\Support\PanelContext;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Pazarlama: a brand reads only its own published reports, finished work, approved posts and accounts. */
final class PanelMarketingTest extends PanelTestCase
{
    private function report(int $brandId, string $note, bool $published): BrandSession
    {
        return BrandSession::query()->create([
            'brand_id' => $brandId, 'month' => now('Europe/Istanbul')->subMonth()->format('Y-m'), 'summary' => 'İç özet, müşteriye gösterilmez.', 'jobs' => [['title' => 'İmplant sayfasını yenile']],
            'client_note' => $note, 'report_lines' => [['what' => 'Dört blog yazısı yayınlandı', 'result' => 'İkisi ilk sayfada'], ['what' => 'Gizli kalacak satır', 'result' => '']],
            'numbers' => ['from' => '2026-06-01', 'to' => '2026-10-31', 'lines' => [['label' => 'Organik tık', 'start' => 120, 'now' => 180, 'change' => 50]], 'services' => []],
            'status' => 'approved', 'token' => Str::random(48), 'panel_published_at' => $published ? now() : null, 'panel_hidden_lines' => [1],
        ]);
    }

    public function test_a_report_is_read_only_after_it_is_published_and_only_by_its_brand(): void
    {
        $report = $this->report($this->brandA->id, 'Akasya için güzel bir ay oldu.', false);
        $this->report($this->brandB->id, 'Begonya notu.', true);

        $this->asPanel($this->managerA)->get($this->url('/pazarlama'))->assertOk()->assertSee('İlk raporunuz hazırlanıyor.')->assertDontSee('Begonya notu.');
        $report->forceFill(['panel_published_at' => now()])->save();
        $this->asPanel($this->managerA)->get($this->url('/pazarlama'))->assertOk()
            ->assertSeeInOrder(['data-panel-tabs', 'Aylık rapor', 'Yaptıklarımız', 'Yayınlanacaklar', 'Varlıklar'], false)
            ->assertSee('Akasya için güzel bir ay oldu.')->assertSee('Dört blog yazısı yayınlandı')->assertSee('Google aramasından siteye gelen ziyaret')->assertSee('İmplant sayfasını yenile')
            ->assertDontSee('Gizli kalacak satır')->assertDontSee('İç özet')->assertDontSee('Begonya notu.');
        $this->assertNotNull($report->fresh()->panel_read_at);
    }

    public function test_work_upcoming_and_assets_belong_to_the_brand(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $assetA = DigitalAsset::factory()->create(['brand_id' => $this->brandA->id, 'type' => 'google_business_profile', 'name' => 'Akasya Merkez']);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $this->brandB->id, 'type' => 'google_business_profile', 'name' => 'Begonya Şube']);
        foreach ([[$assetA, 'succeeded'], [$assetA, 'succeeded'], [$assetA, 'failed'], [$assetB, 'succeeded']] as [$asset, $status]) {
            ExternalWriteAction::query()->create(['channel' => ExternalWriteAction::CHANNEL_GBP, 'action' => ExternalWriteAction::ACTION_REVIEW_REPLY, 'digital_asset_id' => $asset->id,
                'brand_id' => $asset->brand_id, 'status' => $status, 'requested_by' => $admin->id, 'request_payload' => []]);
        }
        $day = now('Europe/Istanbul')->addDays(3);
        GbpQueuedPost::query()->create(['digital_asset_id' => $assetA->id, 'brand_id' => $this->brandA->id, 'angle' => 'surec', 'publish_on' => $day->toDateString(), 'summary' => 'Akasya onaylı gönderi.', 'status' => GbpQueuedPost::APPROVED]);
        GbpQueuedPost::query()->create(['digital_asset_id' => $assetA->id, 'brand_id' => $this->brandA->id, 'angle' => 'surec', 'publish_on' => $day->toDateString(), 'summary' => 'Akasya taslak gönderi.', 'status' => GbpQueuedPost::DRAFT]);
        GbpQueuedPost::query()->create(['digital_asset_id' => $assetB->id, 'brand_id' => $this->brandB->id, 'angle' => 'surec', 'publish_on' => $day->toDateString(), 'summary' => 'Begonya gönderisi.', 'status' => GbpQueuedPost::APPROVED]);

        $this->asPanel($this->managerA)->get($this->url('/pazarlama/yaptiklarimiz'))->assertOk()->assertSee('Yorum yanıtı')->assertSee('2 iş');
        $this->asPanel($this->managerA)->get($this->url('/pazarlama/yayinlanacaklar'))->assertOk()->assertSee('Akasya onaylı gönderi.')->assertSee('Değişiklik iste')
            ->assertDontSee('Akasya taslak gönderi.')->assertDontSee('Begonya gönderisi.')->assertDontSee('Onayla');
        $this->asPanel($this->managerA)->get($this->url('/pazarlama/varliklar'))->assertOk()->assertSee('Akasya Merkez')->assertDontSee('Begonya Şube');

        $this->enter($this->managerA, $this->brandA);
        $key = 'gbp-'.GbpQueuedPost::query()->where('summary', 'Akasya onaylı gönderi.')->value('id');
        Livewire::test(MarketingPage::class, ['tab' => 'yayinlanacaklar'])->set('changing', $key)->call('askChange')->assertHasErrors('change')
            ->set('change', 'Telefon numarası eski, yenisini yazalım.')->call('askChange')->assertHasNoErrors()->assertSee('Değişiklik isteğiniz bizde');
        $ticket = app(PanelContext::class)->system(fn () => PanelTicket::query()->sole());
        $this->assertStringStartsWith('Değişiklik isteği: ', $ticket->subject);
        $this->assertSame($this->brandA->id, (int) $ticket->brand_id);
    }

    public function test_a_role_without_pazarlama_does_not_open_it(): void
    {
        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $this->asPanel($sales)->get($this->url('/pazarlama'))->assertForbidden();
        $this->asPanel($sales)->get($this->url('/'))->assertOk()->assertDontSee('Pazarlama');
    }
}
