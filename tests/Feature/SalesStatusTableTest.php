<?php

namespace Tests\Feature;

use App\Livewire\Operator\Sales\LeadCardPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\AgencySetting;
use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\ReferralReward;
use App\Models\Referrer;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Sales\Agreements;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesReplyWriter;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Satış yeniden kurgu (yakup 2026-11-08): one table filtered by six statuses, the ⋯ menu (durum, yapılacak,
 * hatırlatma / toplantı, Kimden), Müşteri opens the customer, referrals earn their reward after the second payment.
 */
class SalesStatusTableTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup Udul']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        CarbonImmutable::setTestNow('2026-11-04 08:00:00');
        Http::fake();
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_lead_card_shows_person_info_and_takes_the_six_statuses(): void
    {
        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Dr. Selin', 'phone' => '0532 777 22 33', 'email' => 'selin@ornek.com', 'source' => 'form'], $this->admin);

        $card = Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])
            ->assertSee('Kişi bilgileri')->assertSee('selin@ornek.com')->assertSee('Web site formu')->assertSee('İrtibat geçmişi')
            ->call('setStatus', 'guclu');
        $this->assertSame('guclu', $lead->fresh()->status());
        $card->call('setStatus', 'olumsuz')->assertHasErrors('lostReason');
        $this->assertSame('guclu', $lead->fresh()->status());
        $card->call('setStatus', 'kararsiz');
        $this->assertSame('kararsiz', $lead->fresh()->status());
        $card->call('setStatus', 'musteri')->assertSet('winning', true);
        $this->assertFalse($lead->fresh()->isCustomer(), 'Müşteri opens the terms form first');
    }

    public function test_six_statuses_filter_one_table_and_the_menu_moves_a_record_between_them(): void
    {
        $leads = app(SalesLeads::class);
        $talking = $leads->quickAdd(['name' => 'Dr. Ayşe', 'phone' => '0532 111 22 33', 'source' => 'whatsapp'], $this->admin);
        $thinking = $leads->quickAdd(['name' => 'Mehmet Bey', 'phone' => '0532 111 22 44', 'source' => 'phone'], $this->admin);
        $leads->setStage($thinking, 'thinking');

        $page = Livewire::test(SalesPage::class);
        $page->assertSee('Devam eden')->assertSee('En güçlü adaylar')->assertSee('Müşteriye dönüşenler')->assertSee('Rapor')->assertSee('Ayarlar')
            ->assertSee('Dr. Ayşe')->assertDontSee('Mehmet Bey');
        $page->call('showStatus', 'kararsiz')->assertSee('Mehmet Bey')->assertSee('Çağrı')->assertDontSee('Dr. Ayşe');

        // Güçlü aday is the operator's mark; Claude's hint goes away once marked.
        $talking->forceFill(['strong_hint' => 'Fiyatı sordu, bu hafta başlamak istiyor'])->save();
        Livewire::test(SalesPage::class)->assertSee('Claude: güçlü aday?')->call('setStatus', $talking->id, 'guclu');
        $this->assertSame('guclu', $talking->fresh()->status());
        $this->assertNull($talking->fresh()->strong_hint);

        // Olumsuz asks why; Aday olmayan = ilgisiz; Devam eden brings it back.
        Livewire::test(SalesPage::class)->call('setStatus', $thinking->id, 'olumsuz')->assertSet('dialog', 'close')
            ->set('closeReason', 'price')->call('saveClose')->assertHasNoErrors();
        $this->assertSame('olumsuz', $thinking->fresh()->status());
        $leads->setStatus($thinking->fresh(), 'aday_degil', $this->admin);
        $this->assertSame('aday_degil', $thinking->fresh()->status());
        $leads->setStatus($thinking->fresh(), 'devam', $this->admin);
        $this->assertSame('devam', $thinking->fresh()->status());
        $this->assertSame('prospect', $thinking->fresh()->lifecycle);

        // Müşteri: the customer and the brand are opened, and a customer cannot become Olumsuz.
        Livewire::test(SalesPage::class)->call('setStatus', $talking->id, 'musteri')->assertHasNoErrors();
        $won = $talking->fresh();
        $this->assertSame('musteri', $won->status());
        $this->assertNotNull($won->brand_id);
        $this->assertNotNull(Customer::query()->find($won->customer_id));
        Livewire::withQueryParams(['durum' => 'musteri'])->test(SalesPage::class)->assertSee('Dr. Ayşe');
        $this->expectException(ValidationException::class);
        $leads->setStatus($won, 'aday_degil', $this->admin);
    }

    public function test_todo_and_meeting_from_the_menu_land_on_the_card_and_the_calendar(): void
    {
        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Dr. Can', 'phone' => '0532 222 33 44'], $this->admin);
        Livewire::test(SalesPage::class)->call('openDialog', 'todo', $lead->id)->set('todo.todo', 'Karar bekleniyor')->set('todo.date', '2026-11-10')
            ->call('saveTodo')->assertHasNoErrors()->assertSet('dialog', '');
        $this->assertSame('Karar bekleniyor', $lead->fresh()->next_step);

        Livewire::test(SalesPage::class)->call('openDialog', 'plan', $lead->id)->set('plan.title', 'Online görüşme')->set('plan.date', '2026-11-10')->set('plan.time', '14:00')
            ->call('savePlan')->assertHasNoErrors();
        $reminder = Reminder::query()->where('sales_lead_id', $lead->id)->firstOrFail();
        $this->assertSame('meeting', $reminder->kind);
        $this->assertSame('2026-11-10 14:00', $reminder->remind_at->timezone('Europe/Istanbul')->format('Y-m-d H:i'));
        $this->assertSame('meeting', $lead->fresh()->stage);
        $this->assertSame('Online görüşme', $lead->fresh()->next_step);
    }

    public function test_a_referral_earns_a_gift_month_for_a_customer_and_ten_percent_of_the_first_payment_for_anyone_else(): void
    {
        $agreements = app(Agreements::class);
        $leads = app(SalesLeads::class);
        // The referring customer, with a monthly agreement.
        $old = Customer::factory()->create(['name' => 'Panorama']);
        $oldBrand = Brand::factory()->create(['customer_id' => $old->id, 'name' => 'Panorama']);
        $agreements->start($oldBrand, ['kind' => 'monthly', 'fee' => '18.000', 'starts_on' => '2026-10-01', 'due_day' => 5]);

        $byCustomer = $leads->quickAdd(['name' => 'Dr. Gift', 'phone' => '0532 333 44 55'], $this->admin);
        Livewire::test(SalesPage::class)->call('openDialog', 'from', $byCustomer->id)->set('from.via', 'referral')->set('from.who', 'c-'.$old->id)
            ->call('saveFrom')->assertHasNoErrors();
        $this->assertSame('Referans · Panorama', $byCustomer->fresh()->cameViaLabel());

        $byPerson = $leads->quickAdd(['name' => 'Dr. Ten', 'phone' => '0532 333 44 66'], $this->admin);
        Livewire::test(SalesPage::class)->call('openDialog', 'from', $byPerson->id)->set('from.via', 'referral')->set('newReferrer', 'Eyüp Bey')
            ->call('saveFrom')->assertHasNoErrors();
        $this->assertSame('Eyüp Bey', Referrer::query()->value('name'));

        foreach ([$byCustomer, $byPerson] as $lead) {
            $leads->setStatus($lead->fresh(), 'musteri', $this->admin);
            $brand = Brand::query()->findOrFail($lead->fresh()->brand_id);
            $agreements->start($brand, ['kind' => 'monthly', 'fee' => '14.000', 'starts_on' => '2026-10-01', 'due_day' => 5]);
        }
        $gift = ReferralReward::query()->where('lead_id', $byCustomer->id)->firstOrFail();
        $commission = ReferralReward::query()->where('lead_id', $byPerson->id)->firstOrFail();
        $this->assertSame(['gift_month', 'waiting'], [$gift->kind, $gift->status]);
        $this->assertSame(['commission', 'waiting'], [$commission->kind, $commission->status]);

        // One paid month is not enough; the second earns it.
        foreach ([$byCustomer, $byPerson] as $lead) {
            $agreement = Agreement::query()->where('customer_id', $lead->fresh()->customer_id)->firstOrFail();
            $agreements->ensurePayments($agreement);
            $agreements->markPaid($agreement->payments()->where('period', '2026-10')->firstOrFail());
        }
        $this->assertSame('waiting', $commission->fresh()->status);
        foreach ([$byCustomer, $byPerson] as $lead) {
            $agreement = Agreement::query()->where('customer_id', $lead->fresh()->customer_id)->firstOrFail();
            $agreements->markPaid($agreement->payments()->where('period', '2026-11')->firstOrFail());
        }
        $this->assertSame(['earned', 1400], [$commission->fresh()->status, $commission->fresh()->amount]);
        $this->assertSame(['earned', 18000], [$gift->fresh()->status, $gift->fresh()->amount]);

        Livewire::withQueryParams(['sekme' => 'sonuclar'])->test(SalesPage::class)->assertSee('Referans ödülleri')->assertSee('Eyüp Bey')
            ->call('giveReward', $gift->id)->call('giveReward', $commission->id)->assertHasNoErrors();
        $this->assertSame('paid', $commission->fresh()->status);
        $payment = AgreementPayment::query()->findOrFail($gift->fresh()->gift_payment_id);
        $this->assertSame(0, (int) $payment->amount);
        $this->assertStringContainsString('Referans hediyesi', (string) $payment->note);
    }

    public function test_claude_names_an_unnamed_record_from_the_chat_and_suggests_strong_prospects(): void
    {
        $this->assertTrue(SalesReplyWriter::looksLikeName('Dr. Ayşe Yılmaz'));
        $this->assertFalse(SalesReplyWriter::looksLikeName('[ad]'));
        $this->assertFalse(SalesReplyWriter::looksLikeName('0532 111 22 33'));
        $this->assertFalse(SalesReplyWriter::looksLikeName('bilinmiyor'));

        $lead = app(SalesLeads::class)->quickAdd(['phone' => '0532 999 88 77', 'source' => 'whatsapp'], $this->admin);
        $store = new \ReflectionMethod(SalesReplyWriter::class, 'store');
        $store->invoke(app(SalesReplyWriter::class), $lead, ['action' => 'reply', 'reply' => 'Merhaba Ayşe Hanım, teşekkürler.', 'send_on' => '2026-11-04',
            'note' => '', 'summary' => '', 'person_name' => 'Ayşe Hanım', 'strong' => 'Fiyat sordu, başlamak istiyor']);
        $fresh = $lead->fresh();
        $this->assertSame('Ayşe Hanım', $fresh->name);
        $this->assertSame('Fiyat sordu, başlamak istiyor', $fresh->strong_hint);

        // A name set once is never overwritten by a later guess.
        $store->invoke(app(SalesReplyWriter::class), $fresh, ['action' => 'reply', 'reply' => 'Tekrar merhaba.', 'send_on' => '2026-11-04', 'note' => '', 'summary' => '', 'person_name' => 'Başka Biri', 'strong' => '']);
        $this->assertSame('Ayşe Hanım', $lead->fresh()->name);
        $this->assertNull($lead->fresh()->strong_hint);
    }

    public function test_settings_lists_referrers_and_old_addresses_still_open(): void
    {
        Livewire::withQueryParams(['sekme' => 'ayarlar'])->test(SalesPage::class)->set('newReferrer', 'Eyüp Bey')->call('addReferrer')
            ->assertSee('Referanslar')->assertSee('Eyüp Bey');
        $this->get('/satis?sekme=adaylar&gorunum=kaybedilen')->assertOk()->assertSee('Olumsuz');
        $this->get('/satis?sekme=ayarlar')->assertOk()->assertSee('Referanslar');
    }
}
