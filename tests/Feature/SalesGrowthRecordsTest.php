<?php

namespace Tests\Feature;

use App\Livewire\Operator\Portfolio\BrandAgreementCard;
use App\Livewire\Operator\Sales\LeadCardPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\LeadKindsTool;
use App\Models\AgencySetting;
use App\Models\Agreement;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\SalesLead;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Sales\Agreements;
use App\Services\Sales\LeadClassifier;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesMonthReport;
use App\Services\Sales\SalesReplyWriter;
use App\Support\Roles;
use App\Support\Sales\LeadKinds;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Moximu büyüme sistemi, 1. sürüm (2026-11-07): the generic customer record with its Tür and Sektör, the opportunity
 * layer, the brand agreements (aylık / dönemlik / tek seferlik) with monthly ticks, and the "ilgisiz" lifecycle.
 */
class SalesGrowthRecordsTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_the_kinds_list_starts_with_the_defaults_and_is_edited_by_the_operator_and_by_claude(): void
    {
        $this->assertSame('Klinik', LeadKinds::label('clinic'));
        $this->assertTrue(LeadKinds::pricesApply('clinic'));
        $this->assertFalse(LeadKinds::pricesApply('factory'));
        $this->assertSame('hocam', LeadKinds::address('physician'));
        $this->assertSame('bey_hanim', LeadKinds::address(null));

        LeadKinds::put('hotel', ['label' => 'Otel', 'address' => 'bey_hanim', 'prices' => 'none', 'health' => false, 'hint' => 'otel, hotel']);
        $this->assertSame('Otel', LeadKinds::label('hotel'));
        $this->assertSame('Klinik', LeadKinds::label('clinic'), 'the defaults stay when one kind is added');

        // Claude adds and removes through MCP; the operator sees who did it.
        MoxdopServer::tool(LeadKindsTool::class, ['action' => 'add', 'key' => 'school', 'label' => 'Okul / kurs', 'hint' => 'okul, kurs, akademi', 'note' => '3 kayıt eğitim kurumu'])
            ->assertOk()->assertSee('Tür eklendi: school')->assertSee('Okul / kurs');
        $this->assertSame('claude', LeadKinds::all()['school']['by']);
        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Ali Bey', 'phone' => '0532 100 00 01', 'kind' => 'school'], $this->admin);
        $this->assertSame('school', $lead->fresh()->kind);
        MoxdopServer::tool(LeadKindsTool::class, ['action' => 'remove', 'key' => 'school'])->assertOk()->assertSee('1 kayıt "Diğer" oldu');
        $this->assertSame('other', $lead->fresh()->kind);
        MoxdopServer::tool(LeadKindsTool::class, ['action' => 'remove', 'key' => 'other'])->assertHasErrors();
        MoxdopServer::tool(LeadKindsTool::class, ['action' => 'list'])->assertOk()->assertSee('Otel');

        $this->actingAs($this->admin);
        $this->get('/library/services')->assertOk()->assertSee('Kayıt türleri')->assertSee('Otel');
    }

    public function test_the_classifier_suggests_the_kind_and_the_sector_from_the_site_name_and_title(): void
    {
        // The catalog already carries the seeded sectors; two more make the match unambiguous.
        ServiceCategory::query()->firstOrCreate(['normalized_key' => 'dis'], ['code' => 'sector_dis', 'name' => 'Diş']);
        ServiceCategory::query()->create(['code' => 'sector_hurda', 'name' => 'Hurda ve geri dönüşüm', 'normalized_key' => 'hurda ve geri donusum']);
        $leads = app(SalesLeads::class);

        $clinic = $leads->quickAdd(['name' => 'Dr. Ayşe Yılmaz', 'company' => 'Gülüş Ağız ve Diş Sağlığı Kliniği', 'phone' => '0532 100 00 02'], $this->admin)->fresh();
        $this->assertSame('clinic', $clinic->kind, 'a clinic name with a doctor');
        $this->assertStringContainsString('diş', mb_strtolower((string) $clinic->sectorCategory?->name), 'the dental sector of the catalog');
        $this->assertFalse((bool) data_get($clinic->classification, 'confirmed'));

        $scrap = $leads->quickAdd(['company' => 'Bornova Hurda', 'phone' => '0532 100 00 03'], $this->admin)->fresh();
        $this->assertSame('factory', $scrap->kind);
        $this->assertSame('Hurda ve geri dönüşüm', $scrap->sectorCategory->name);

        $doctor = $leads->quickAdd(['name' => 'Dt. Gözde Zeyrek', 'phone' => '0532 100 00 04'], $this->admin)->fresh();
        $this->assertSame('physician', $doctor->kind, 'a title alone is a physician');

        $unknown = $leads->quickAdd(['name' => 'Musa Bey', 'phone' => '0532 100 00 05'], $this->admin)->fresh();
        $this->assertNull($unknown->kind, 'nothing to go on: no kind is guessed');

        // The site scan's schema says what it is; a confirmed kind is never overwritten.
        $unknown->forceFill(['site_scan' => ['schema_types' => ['Store'], 'home_title' => 'Perde Dünyası | Tül ve stor perde']])->save();
        $s = app(LeadClassifier::class)->suggest($unknown);
        $this->assertSame('store', $s['kind']);
        app(LeadClassifier::class)->apply($unknown);
        $this->assertSame('store', $unknown->fresh()->kind);
        $leads->fill($unknown, ['kind' => 'service']);
        $unknown->save();
        $this->assertFalse(app(LeadClassifier::class)->apply($unknown->fresh()));
        $this->assertSame('service', $unknown->fresh()->kind);
    }

    public function test_winning_a_record_opens_the_customer_the_brand_and_the_agreement_and_the_record_stays_alive_for_the_next_sale(): void
    {
        $this->actingAs($this->admin);
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Dr. Buşra', 'company' => 'Buşra Klinik', 'city' => 'İzmir', 'phone' => '0532 200 00 01'], $this->admin);
        $leads->setOffer($lead, 14000, 'seo_gbp', $this->admin);

        $card = Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->call('openWin')->assertSet('winning', true)->assertSet('agreement.fee', '14000');
        $card->set('agreement.due_day', '5')->call('win')->assertHasNoErrors()->assertSet('winning', false);

        $lead = $lead->fresh();
        $this->assertSame('won', $lead->stage);
        $this->assertSame('customer', $lead->lifecycle);
        $this->assertNotNull($lead->brand_id);
        $agreement = Agreement::query()->where('brand_id', $lead->brand_id)->first();
        $this->assertSame('monthly', $agreement->kind);
        $this->assertSame(14000, $agreement->fee);
        $this->assertSame(5, $agreement->due_day);
        $this->assertSame(['seo_gbp'], $agreement->services);
        $this->assertSame(1, $agreement->payments()->count(), 'this month is the first billed month');
        $this->assertSame('2026-11-05', $agreement->payments()->first()->due_on->toDateString());
        $this->assertSame(14000.0, (float) Customer::query()->find($lead->customer_id)->monthly_fee, 'the old column follows the agreement');
        $won = $lead->opportunities()->first();
        $this->assertSame('won', $won->status);
        $this->assertSame(14000, $won->amount);
        $this->assertSame($agreement->id, $won->agreement_id);

        // The customer's card is pursued again: an upsell to 40.000.
        $card->set('opp.service', 'seo_gbp')->set('opp.amount', '40.000')->set('opp.title', 'Ücret artışı')->call('newOpportunity')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertSame('priced', $lead->stage);
        $this->assertSame('customer', $lead->lifecycle, 'still a customer while the new sale is pursued');
        $this->assertTrue($lead->isDue());
        $open = $lead->openOpportunity();
        $this->assertSame('Ücret artışı', $open->title());
        // Only one open opportunity at a time.
        $card->set('opp.service', 'web')->call('newOpportunity')->assertHasErrors(['opportunity.service']);

        // A linked customer chat does not close the card as won while the upsell is open (stage is its own).
        $this->assertSame('priced', $lead->fresh()->stage);

        // Won with new terms: the agreement is replaced, the old one ended the day before.
        $card->call('openWin', $open->id)->assertSet('agreement.fee', '40000')->set('agreement.starts_on', '2026-12-01')->call('win')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertSame('won', $lead->stage);
        $this->assertSame('won', $open->fresh()->status);
        $this->assertSame('ended', $agreement->fresh()->status);
        $this->assertSame('2026-11-30', $agreement->fresh()->ends_on->toDateString());
        $current = Brand::query()->find($lead->brand_id)->activeAgreement();
        $this->assertSame(40000, $current->fee);
        $this->assertSame(40000.0, (float) Customer::query()->find($lead->customer_id)->monthly_fee);
        $this->assertSame(40000, app(Agreements::class)->wonThisMonth() - 14000, 'both sales were won this month');

        // A lost upsell keeps the customer: the record goes back to "müşteri".
        $leads->openOpportunity($lead, ['service' => 'web', 'amount' => 30000, 'billing' => 'one_time'], $this->admin);
        $this->assertSame('priced', $lead->fresh()->stage);
        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->set('lostReason', 'price')->call('setStage', 'lost')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertSame('won', $lead->stage);
        $this->assertSame('customer', $lead->lifecycle);
        $this->assertSame('lost', $lead->opportunities()->first()->status);
        $this->assertNull($lead->openOpportunity());

        $this->get('/satis/'.$lead->id)->assertOk()->assertSee('Fırsatlar')->assertSee('Anlaşma')->assertSee('40.000 ₺/ay')->assertSee('Ücret artışı');
        $this->get('/satis?sekme=adaylar&gorunum=musteriler')->assertOk()->assertSee('Buşra Klinik')->assertSee('Aylık gelir')->assertSee('Gecikmiş tahsilat')->assertSee('Yeni fırsat');
    }

    public function test_a_term_agreement_bills_its_months_marks_late_ones_and_ends_by_itself(): void
    {
        $customer = Customer::factory()->create(['name' => 'Panorama', 'monthly_fee' => 0]);
        $brand = Brand::query()->create(['customer_id' => $customer->id, 'name' => 'Panorama']);
        $agreements = app(Agreements::class);

        $term = $agreements->start($brand, ['kind' => 'term', 'fee' => '18.000', 'term_months' => 6, 'starts_on' => '2026-09-01', 'due_day' => 1]);
        $this->assertSame('2027-02-28', $term->ends_on->toDateString());
        $this->assertSame(['2026-09', '2026-10', '2026-11'], $term->payments()->pluck('period')->all());
        $summary = $agreements->summary($brand);
        $this->assertSame(3, $summary['overdue'], 'the 1st of November is past too');
        $this->assertSame(18000, $agreements->mrr());
        $this->assertSame(18000.0, (float) $customer->fresh()->monthly_fee);

        $agreements->markPaid($term->payments()->where('period', '2026-09')->first(), '2026-09-03');
        $agreements->markPaid($term->payments()->where('period', '2026-10')->first());
        $summary = $agreements->summary($brand);
        $this->assertSame(1, $summary['overdue']);
        $this->assertSame(3, $summary['late_days']);
        $this->assertSame('odeme_gecikti', $summary['risks'][0]['key']);
        $this->assertSame('2026-12-01', $summary['next_due']->toDateString());
        $this->assertCount(1, $agreements->overdue());

        // A one-time job sits beside the term and ends when it is paid.
        $site = $agreements->start($brand, ['kind' => 'one_time', 'fee' => 30000, 'starts_on' => '2026-11-04']);
        $this->assertSame('active', $term->fresh()->status, 'a one-time job does not replace the monthly terms');
        $this->assertSame(18000, $agreements->mrr(), 'one-time money is not monthly income');
        $agreements->markPaid($site->payments()->first());
        $this->assertSame('ended', $site->fresh()->status);

        // Near the end, the brand says so; ending it makes the customer a former one on the sales record.
        CarbonImmutable::setTestNow('2027-02-10 08:00:00');
        $this->assertContains('donem_bitiyor', array_column($agreements->summary($brand)['risks'], 'key'));
        $lead = app(SalesLeads::class)->forCustomer($customer, $brand);
        $this->assertSame('customer', $lead->lifecycle);
        $agreements->setStatus($term->fresh(), 'ended', '2027-02-10');
        $this->assertSame('former', $lead->fresh()->lifecycle);
        $this->assertSame(0.0, (float) $customer->fresh()->monthly_fee);

        $this->actingAs($this->admin);
        $this->get('/brands/'.$brand->id.'?tab=ayarlar')->assertOk()->assertSee('Anlaşma')->assertSee('18.000 ₺/ay');
        Livewire::test(BrandAgreementCard::class, ['brandId' => $brand->id])->call('startNew')->set('form.kind', 'monthly')->set('form.fee', '20000')->set('form.starts_on', '2027-03-01')
            ->call('save')->assertHasNoErrors();
        $this->assertSame(20000, $brand->fresh()->activeAgreement()->fee);
        $this->assertSame('customer', $lead->fresh()->lifecycle);
        $this->get('/brands')->assertOk()->assertSee('Müşteri ekle');
        $this->get('/brands/create')->assertOk()->assertSee('yeni müşteri aç');

        // Ortaklık: served, nothing of ours billed (no fee → no ticks, no monthly income).
        $partner = Customer::factory()->create(['name' => 'All for Clinics']);
        $partnerBrand = Brand::query()->create(['customer_id' => $partner->id, 'name' => 'All for Clinics']);
        $share = $agreements->start($partnerBrand, ['kind' => 'partnership', 'fee' => '', 'starts_on' => '2027-02-01']);
        $this->assertSame('Ortaklık (gelir paylaşımı)', $share->feeLabel());
        $this->assertSame(0, $share->payments()->count());
        $this->assertSame(20000, $agreements->mrr());
        $this->assertSame([], $agreements->summary($partnerBrand)['risks']);
    }

    public function test_an_irrelevant_chat_leaves_every_count_and_the_reply_writer_follows_the_kind(): void
    {
        $leads = app(SalesLeads::class);
        $spam = $leads->quickAdd(['name' => 'Kurye', 'phone' => '0532 300 00 01'], $this->admin);
        $real = $leads->quickAdd(['name' => 'Dr. Ercan', 'company' => 'Ercan Klinik', 'phone' => '0532 300 00 02'], $this->admin);
        $factory = $leads->quickAdd(['name' => 'Mehmet Bey', 'company' => 'Arısoy Makine Fabrikası', 'phone' => '0532 300 00 03'], $this->admin);

        $leads->markIrrelevant($spam, $this->admin);
        $spam = $spam->fresh();
        $this->assertSame('irrelevant', $spam->lifecycle);
        $this->assertSame('lost', $spam->stage);
        $this->assertSame(2, SalesLead::query()->open()->relevant()->count());
        $report = app(SalesMonthReport::class)->month(CarbonImmutable::parse('2026-11-01', 'Europe/Istanbul'));
        $this->assertSame(2, $report['opened']);
        $this->assertSame(0, $report['lost']);
        $page = Livewire::test(SalesPage::class)->set('tab', 'adaylar');
        $this->assertSame(2, $page->viewData('viewCounts')['acik']);
        $this->assertSame(0, $page->viewData('viewCounts')['kaybedilen']);

        $payload = app(SalesReplyWriter::class)->payload($real);
        $this->assertSame('Klinik', $payload['lead']['kind']);
        $this->assertSame('X Hocam', $payload['kind_rules']['address']);
        $this->assertStringContainsString('yazılabilir', $payload['kind_rules']['prices']);
        $this->assertTrue($payload['kind_rules']['health_rules']);

        $payload = app(SalesReplyWriter::class)->payload($factory->fresh());
        $this->assertSame('Fabrika / üretici', $payload['lead']['kind']);
        $this->assertSame('X Bey / X Hanım', $payload['kind_rules']['address']);
        $this->assertStringContainsString('FİYAT YAZMA', $payload['kind_rules']['prices']);
        $this->assertFalse($payload['kind_rules']['health_rules']);
        $this->assertSame('Arısoy Makine Fabrikası', $payload['lead']['company']);
        $this->assertArrayNotHasKey('clinic', $payload['lead']);
    }
}
