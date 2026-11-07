<?php

namespace Tests\Feature;

use App\Livewire\Operator\Inbox\InboxPage;
use App\Livewire\Operator\Portfolio\BrandCommercialStrip;
use App\Livewire\Operator\Sales\SalesPage;
use App\Livewire\Operator\Sales\TodayCard;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\BrandReport;
use App\Models\BrandSignal;
use App\Models\BrandTarget;
use App\Models\CoreAssetBinding;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\User;
use App\Services\Inbox\InboxItem;
use App\Services\Inbox\InboxQueue;
use App\Services\Sales\Agreements;
use App\Services\Sales\CustomerSignals;
use App\Services\Sales\SalesLeads;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Müşteri dönemi (Moximu büyüme sistemi, Aşama 3, 2026-11-07): four risk signals read from the existing tables, each
 * a line of work in the Gelen kutusu that leaves with Yapıldı or Sonra; the monthly report tick; the setup list read
 * from the brand's connections; the commercial strip on the brand page and the Moximu line on Komuta.
 */
final class CustomerPeriodTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    private Brand $brand;

    private SalesLead $lead;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup Udul', 'locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        // The 9th: past the report day (8) and before the payment day (15) of November.
        CarbonImmutable::setTestNow('2026-11-09 09:00:00');
        Http::fake();
        Queue::fake();
        $this->actingAs($this->admin);

        $this->customer = Customer::factory()->create(['name' => 'Panorama', 'monthly_fee' => 0]);
        $this->brand = Brand::query()->create(['customer_id' => $this->customer->id, 'name' => 'Panorama']);
        $agreement = app(Agreements::class)->start($this->brand, ['kind' => 'monthly', 'fee' => '18.000', 'starts_on' => '2026-09-01', 'due_day' => 1]);
        app(Agreements::class)->markPaid($agreement->payments()->where('period', '2026-09')->first(), '2026-09-02');
        $this->lead = app(SalesLeads::class)->forCustomer($this->customer, $this->brand);
        $this->lead->forceFill(['last_contact_at' => now()->subDays(40), 'last_incoming_at' => null])->save();
        BrandTarget::query()->create(['brand_id' => $this->brand->id, 'status' => BrandTarget::PROPOSED, 'basis' => ['months' => [
            ['month' => '2026-07', 'total' => 31], ['month' => '2026-08', 'total' => 30], ['month' => '2026-09', 'total' => 22], ['month' => '2026-10', 'total' => 15],
        ]]]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_four_signals_are_read_from_the_data_and_leave_with_yapildi_or_sonra(): void
    {
        $signals = app(CustomerSignals::class);
        $brand = $this->brand->fresh();

        $raw = $signals->raw($brand);
        $this->assertSame(['odeme_gecikti', 'temas_yok', 'rapor_yok', 'talep_dustu'], array_column($raw, 'key'));
        $this->assertSame('2026-10', $raw[0]['period'], 'October is unpaid and its 15th is past; November is unpaid but its 15th is not');
        $this->assertStringContainsString('Ekim 2026 · 18.000 ₺', $raw[0]['label']);
        $this->assertSame('40 gündür temas yok', $raw[1]['label']);
        $this->assertSame('2026-10', $raw[2]['period']);
        $this->assertSame('Ekim raporu gönderilmedi', $raw[2]['label']);
        $this->assertSame('2026-10', $raw[3]['period']);
        $this->assertStringContainsString('Ağu 30 → Eyl 22 → Eki 15', $raw[3]['detail']);
        $this->assertCount(4, $signals->open($brand));
        $this->assertCount(1, $signals->all(), 'one served brand with open signals');

        // Yapıldı on "temas yok": a contact is written on the record and the 30-day count restarts.
        $this->assertStringContainsString('Temas kaydedildi', $signals->handle($brand, 'temas_yok', '2026-11', $this->admin, 'Aradım, memnun.'));
        $this->assertSame(now()->toDateTimeString(), $this->lead->fresh()->last_contact_at->toDateTimeString());
        $this->assertStringContainsString('Aradım, memnun.', (string) SalesLeadEvent::query()->where('lead_id', $this->lead->id)->where('kind', 'call')->value('body'));
        $this->assertNotContains('temas_yok', array_column($signals->open($brand), 'key'));

        // Sonra on the payment: silent until that morning, then back; Yapıldı (reminded) keeps it quiet for a week.
        $signals->snooze($brand, 'odeme_gecikti', '2026-10', CarbonImmutable::parse('2026-11-12 10:00', 'Europe/Istanbul'), $this->admin);
        $this->assertNotContains('odeme_gecikti', array_column($signals->open($brand), 'key'));
        CarbonImmutable::setTestNow('2026-11-12 10:01:00');
        $this->assertContains('odeme_gecikti', array_column($signals->open($brand), 'key'));
        $this->assertStringContainsString('Hatırlatma kaydedildi', $signals->handle($brand, 'odeme_gecikti', '2026-10', $this->admin));
        $this->assertNotContains('odeme_gecikti', array_column($signals->open($brand), 'key'));
        $this->assertStringContainsString('Ödeme hatırlatıldı (Ekim 2026)', (string) SalesLeadEvent::query()->where('lead_id', $this->lead->id)->where('kind', 'note')->value('body'));
        CarbonImmutable::setTestNow('2026-11-20 10:00:00');
        $open = collect($signals->open($brand))->firstWhere('key', 'odeme_gecikti');
        $this->assertNotNull($open, 'a week later the money still did not come: the signal is back');
        $this->assertStringContainsString('2 ay · 36.000 ₺', $open['label'], 'by the 20th November is late too');
        $this->assertSame('2026-10', $open['period'], 'the first unpaid month keys the signal, so one state covers it');

        // Yapıldı on the report marks the month and counts as a contact; the tick can be taken back.
        $this->assertStringContainsString('Ekim 2026 raporu gönderildi', $signals->handle($brand, 'rapor_yok', '2026-10', $this->admin));
        $this->assertNotNull($signals->report($brand, '2026-10'));
        $this->assertNotContains('rapor_yok', array_column($signals->raw($brand), 'key'));
        $this->assertTrue(SalesLeadEvent::query()->where('lead_id', $this->lead->id)->where('kind', 'report')->exists());
        $this->assertSame('2026-11-20', $signals->lastContactAt($brand)->timezone('Europe/Istanbul')->toDateString());
        $signals->unmarkReport($brand, '2026-10');
        $this->assertContains('rapor_yok', array_column($signals->raw($brand), 'key'));

        // Yapıldı on falling demand is quiet for a month; the state table holds one row per signal and period.
        $signals->handle($brand, 'talep_dustu', '2026-10', $this->admin);
        $this->assertNotContains('talep_dustu', array_column($signals->open($brand), 'key'));
        $this->assertSame(4, BrandSignal::query()->where('brand_id', $brand->id)->count());
        $this->assertSame(['months' => [['month' => '2026-08', 'total' => 30.0], ['month' => '2026-09', 'total' => 22.0], ['month' => '2026-10', 'total' => 15.0]], 'falling' => true], $signals->demand($brand));
        $this->assertSame(3, CustomerSignals::monthNo($brand->activeAgreement()));
    }

    public function test_the_setup_list_is_read_from_the_connections_for_the_agreed_services(): void
    {
        $signals = app(CustomerSignals::class);
        $brand = $this->brand->fresh();
        $this->assertSame(['site', 'gsc', 'ga4', 'gbp'], array_column($signals->setup($brand, $brand->activeAgreement())['items'], 'key'), 'no services named: the default package');
        $this->assertSame(0, $signals->setup($brand)['done']);

        $site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'name' => 'panorama.com']);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $site->id, 'capability' => 'search_console']);
        $gbp = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_business_profile', 'name' => 'Panorama GBP']);
        $setup = $signals->setup($brand, $brand->activeAgreement());
        $this->assertSame(2, $setup['done']);
        $this->assertSame(['site' => true, 'gsc' => true, 'ga4' => false, 'gbp' => false], array_combine(array_column($setup['items'], 'key'), array_column($setup['items'], 'done')), 'a profile asset without its binding is not connected');
        CoreAssetBinding::factory()->create(['digital_asset_id' => $gbp->id, 'capability' => 'google_business_profile']);
        $this->assertSame(3, $signals->setup($brand, $brand->activeAgreement())['done']);

        app(Agreements::class)->update($brand->activeAgreement(), ['services' => ['gbp']]);
        $this->assertSame(['items' => [['key' => 'gbp', 'label' => 'İşletme Profili', 'done' => true]], 'done' => 1, 'total' => 1], $signals->setup($brand, $brand->fresh()->activeAgreement()));
    }

    public function test_the_signals_are_lines_of_work_in_the_inbox_and_on_the_customer_table_and_the_brand_page(): void
    {
        $queue = app(InboxQueue::class);
        $items = $queue->items();
        $this->assertSame(4, $items->filter(fn (InboxItem $i): bool => $i->type === InboxItem::RISK)->count());
        $this->assertSame(4, $queue->counts($items)['musteriler']);
        $this->assertSame(4, $queue->counts($items)['adimlar']);
        $this->assertSame(0, $queue->counts($items)['borclu'], 'a signal is work for us, not an owed answer');
        $key = InboxItem::riskKey($this->brand->id, 'rapor_yok', '2026-10');
        $this->assertSame('Ekim raporu gönderilmedi', $queue->find($key)?->preview);
        $this->assertSame([InboxItem::RISK, $this->brand->id, 'rapor_yok', '2026-10'], InboxItem::parse($key));

        $page = Livewire::withQueryParams(['gorunum' => 'musteriler', 'kayit' => $key])->test(InboxPage::class)
            ->assertSee('Panorama')->assertSee('Ekim raporu gönderilmedi')->assertSee('Raporu gönderdim')->assertSee('18.000 ₺/ay');
        $page->call('irrelevant')->assertHasErrors(['item']);
        $page->call('done')->assertHasNoErrors()->assertSet('message', 'Ekim 2026 raporu gönderildi olarak işlendi.');
        $this->assertNotNull(BrandReport::query()->where('brand_id', $this->brand->id)->where('month', '2026-10')->first());
        $this->assertSame(2, $queue->items()->filter(fn (InboxItem $i): bool => $i->type === InboxItem::RISK)->count(), 'the report is a contact too: two lines left');

        $page->set('item', InboxItem::riskKey($this->brand->id, 'talep_dustu', '2026-10'))->set('laterDate', '2026-11-16')->call('later')->assertHasNoErrors();
        $this->assertSame('2026-11-16', BrandSignal::query()->where('signal', 'talep_dustu')->value('snoozed_until')->timezone('Europe/Istanbul')->toDateString());
        $this->assertSame(1, $queue->items()->filter(fn (InboxItem $i): bool => $i->type === InboxItem::RISK)->count());
        $this->assertSame(1, $queue->openCount());

        // Satış › Müşteriler: the tick, the month, the setup, the contact and the signal on the row; the tick toggles.
        $sales = Livewire::withQueryParams(['sekme' => 'adaylar', 'gorunum' => 'musteriler'])->test(SalesPage::class)
            ->assertSee('Panorama')->assertSee('3. ay')->assertSee('Kurulum 0/4')->assertSee('Ödeme gelmedi');
        $sales->call('toggleReport', $this->brand->id, '2026-10')->assertHasNoErrors()->assertSee('raporu işareti kaldırıldı');
        $this->assertNull(BrandReport::query()->where('brand_id', $this->brand->id)->where('month', '2026-10')->first());
        $sales->call('toggleReport', $this->brand->id, '2026-10')->assertSee('raporu gönderildi olarak işlendi');
        $this->assertNotNull(BrandReport::query()->where('brand_id', $this->brand->id)->where('month', '2026-10')->first());
        $sales->call('toggleReport', $this->brand->id, 'bozuk')->assertHasNoErrors();

        // The brand page carries the strip; it reads from Satış and links there.
        $this->get('/brands/'.$this->brand->id)->assertOk()->assertSee('data-brand-commercial', false);
        Livewire::test(BrandCommercialStrip::class, ['brandId' => $this->brand->id])
            ->assertSee('18.000 ₺/ay')->assertSee('3. ay')->assertSee('Ekim raporu gönderildi')->assertSee('Kurulum 0/4')->assertSee('Ödeme gelmedi')->assertSee('Satış › Müşteriler');

        // Komuta's Moximu line counts the signals apart from answers and steps.
        Livewire::test(TodayCard::class)->assertSee('1 müşteri sinyali')->assertSee('Panorama')->assertSee('Gelen kutusu');
    }
}
