<?php

namespace Tests\Feature\Panel;

use App\Livewire\Operator\Panel\PanelTodayCard;
use App\Livewire\Operator\Portfolio\BrandPanelTab;
use App\Livewire\Panel\SupportPage;
use App\Models\BrandSession;
use App\Models\FinanceDocument;
use App\Models\User;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelDocument;
use App\Panel\Models\PanelMeeting;
use App\Panel\Models\PanelNotification;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelAccount;
use App\Panel\Services\PanelDesk;
use App\Panel\Support\PanelContext;
use App\Services\Sales\Agreements;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** The brand page's customer side: requests taken by phone, the report's publish switch, invoices, shared files and the leave lock. */
class BrandPanelTabTest extends PanelTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake((string) config('panel.files_disk'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    private function system(callable $work): mixed
    {
        return app(PanelContext::class)->system($work);
    }

    public function test_a_request_taken_on_the_phone_is_answered_from_the_brand_page(): void
    {
        Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandA->id, 'view' => 'talepler'])->set('adding', true)
            ->set('form.subject', 'Saatler güncellensin')->set('form.body', '<p>Cumartesi <strong>14.00</strong> kapanış.</p>')->set('form.origin', 'phone')
            ->call('openTicket')->assertHasNoErrors()->assertSee('Saatler güncellensin')->assertSee('Telefonda istendi')
            ->set('reply', 'Güncellendi.')->set('uploads', [UploadedFile::fake()->image('ekran.png')])->call('send')->assertHasNoErrors()->assertSee('ekran.png');
        $ticket = $this->system(fn () => PanelTicket::query()->firstOrFail());
        $this->assertSame($this->brandA->id, (int) $ticket->brand_id);
        $this->assertSame(PanelTicket::ANSWERED, $ticket->status);
        $this->assertSame(1, $this->system(fn () => PanelDocument::query()->where('ticket_id', $ticket->id)->whereNotNull('operator_id')->count()));
        $this->assertTrue($this->system(fn () => PanelNotification::query()->where('brand_id', $this->brandA->id)->where('title', 'like', 'Sizin için talep açtık%')->exists()));
        // Another brand's page never opens it.
        Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandB->id, 'view' => 'talepler'])->assertDontSee('Saatler güncellensin')
            ->set('ticketId', $ticket->id)->set('reply', 'x')->call('send')->assertNotFound();
    }

    public function test_a_report_reaches_the_panel_only_when_published_and_without_the_lines_left_out(): void
    {
        $report = BrandSession::query()->create(['brand_id' => $this->brandA->id, 'month' => '2026-10', 'summary' => 'iç not', 'jobs' => [], 'client_note' => 'Ekim iyi geçti.',
            'report_lines' => [['what' => 'Dört yazı yayınlandı', 'result' => ''], ['what' => 'İç iş kalemi', 'result' => '']], 'status' => 'approved', 'token' => Str::random(48)]);
        $page = Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandA->id, 'view' => 'rapor'])->assertSee('Ekim 2026')->assertSee('Panelde değil');
        $page->call('toggleLine', $report->id, 1)->call('publish', $report->id)->assertSee('okunmadı');
        $report->refresh();
        $this->assertTrue($report->isOnPanel());
        $this->assertSame([['what' => 'Dört yazı yayınlandı', 'result' => '']], $report->panelLines());
        $this->assertTrue($this->system(fn () => PanelNotification::query()->where('brand_id', $this->brandA->id)->where('title', 'Ekim 2026 raporunuz hazır')->exists()));
        $page->call('unpublish', $report->id);
        $this->assertFalse($report->fresh()->isOnPanel());
        Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandB->id, 'view' => 'rapor'])->call('publish', $report->id);
        $this->assertFalse($report->fresh()->isOnPanel(), 'another brand\'s page cannot publish it');
    }

    public function test_an_invoice_is_opened_to_the_panel_with_one_switch(): void
    {
        $doc = FinanceDocument::query()->create(['direction' => FinanceDocument::OUT, 'brand_id' => $this->brandA->id, 'status' => 'filed', 'source' => 'upload', 'path' => 'finance/x.pdf', 'doc_date' => '2026-11-01', 'amount' => 14000, 'sha256' => str_repeat('a', 64)]);
        $account = app(PanelAccount::class);
        $this->assertCount(0, $account->invoices($this->brandA->id));
        Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandA->id, 'view' => 'finans'])->assertSee('Panelde göster')->call('toggleInvoice', $doc->id)->assertSee('Panelde görünüyor');
        $this->assertCount(1, $account->invoices($this->brandA->id));
        Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandB->id, 'view' => 'finans'])->call('toggleInvoice', $doc->id);
        $this->assertTrue((bool) $doc->fresh()->panel_visible, 'another brand\'s page cannot switch it');
    }

    public function test_a_meeting_request_is_planned_and_the_brand_is_told(): void
    {
        $meeting = $this->system(function () {
            $row = new PanelMeeting;
            $row->brand_id = $this->brandA->id;
            $row->fill(['panel_user_id' => $this->managerA->id, 'topic' => 'Aylık değerlendirme', 'preferred' => 'Salı', 'status' => PanelMeeting::REQUESTED])->save();

            return $row;
        });
        Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandA->id])->assertSee('Aylık değerlendirme')
            ->set('plan.'.$meeting->id, ['day' => '2026-12-01', 'hour' => '14:00', 'url' => 'https://meet.google.com/abc'])->call('planMeeting', $meeting->id)->assertHasNoErrors();
        $meeting = $this->system(fn () => $meeting->fresh());
        $this->assertSame(PanelMeeting::PLANNED, $meeting->status);
        $this->assertSame('2026-12-01 14:00', $meeting->starts_at->timezone('Europe/Istanbul')->format('Y-m-d H:i'));
        $this->assertTrue($this->system(fn () => PanelNotification::query()->where('brand_id', $this->brandA->id)->where('title', 'like', 'Toplantı günü belli oldu%')->exists()));
    }

    public function test_a_leaving_brand_with_an_open_balance_exports_nothing(): void
    {
        app(Agreements::class)->start($this->brandA, ['kind' => 'monthly', 'fee' => '14000', 'starts_on' => now('Europe/Istanbul')->subMonths(2)->startOfMonth()->toDateString(), 'due_day' => 1, 'services' => ['seo_gbp'], 'title' => 'Akasya aylık çalışma'], $this->admin);
        $account = app(PanelAccount::class);
        $this->assertGreaterThan(0, $account->payments($this->brandA->id)['debt']);
        $this->assertFalse($account->locked($this->brandA->id), 'a late payer who is not leaving keeps everything');
        $this->asPanel($this->managerA)->get($this->url('/disa-aktar/adaylar'))->assertOk();

        $this->actingAs($this->admin);
        Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandA->id])->call('startLeave')->assertSee('Ayrılış')->assertSee('Bakiye');
        $setting = PanelBrandSetting::forBrand($this->brandA->id);
        $this->assertTrue($setting->is_read_only);
        $this->assertNotNull($setting->leaving_at);
        $this->assertTrue($account->locked($this->brandA->id));
        $this->asPanel($this->managerA)->get($this->url('/disa-aktar/adaylar'))->assertForbidden();
        // The other brand is untouched.
        $this->asPanel($this->managerB)->get($this->url('/disa-aktar/adaylar'))->assertOk();

        $this->actingAs($this->admin);
        Livewire::test(BrandPanelTab::class, ['brandId' => $this->brandA->id])->call('stopLeave');
        $this->assertFalse($account->locked($this->brandA->id));
    }

    public function test_today_lists_what_the_panels_ask_of_the_operator(): void
    {
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(SupportPage::class)->set('adding', true)->set('form.subject', 'Formu bağlayın')->set('form.body', 'Form panele düşsün.')->call('open')->assertHasNoErrors();
        Livewire::test(SupportPage::class, ['tab' => 'toplantilar'])->set('meeting.topic', 'Değerlendirme')->set('meeting.preferred', 'Salı')->call('askMeeting')->assertHasNoErrors();
        app(PanelContext::class)->leave();
        BrandSession::query()->create(['brand_id' => $this->brandB->id, 'month' => '2026-10', 'summary' => 'x', 'jobs' => [], 'client_note' => 'y', 'report_lines' => [], 'status' => 'approved',
            'token' => Str::random(48), 'panel_published_at' => now()->subDays(9)]);

        $rows = app(PanelDesk::class)->rows();
        $this->assertSame(['ticket', 'meeting', 'unread'], $rows->pluck('kind')->all());
        $this->assertSame(['Akasya', 'Akasya', 'Begonya'], $rows->pluck('brand')->all());
        $this->assertStringContainsString('tab=talepler', $rows[0]['url']);

        $this->actingAs($this->admin);
        Livewire::test(PanelTodayCard::class)->assertSee('Talep cevap bekliyor: Formu bağlayın')->assertSee('Toplantı isteğine gün ver: Değerlendirme')->assertSee('Ekim 2026 raporu okunmadı');
    }
}
