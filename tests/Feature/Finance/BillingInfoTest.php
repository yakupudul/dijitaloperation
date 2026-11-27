<?php

namespace Tests\Feature\Finance;

use App\Livewire\Operator\Finance\FinancePage;
use App\Mail\OperatorTestMail;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\Finance as FinanceTool;
use App\Models\AgencySetting;
use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Services\Finance\BillingInfo;
use App\Services\Finance\CertificateReader;
use App\Services\Finance\CompanyProfile;
use App\Services\Finance\CustomerMailGuard;
use App\Services\Finance\FinanceBoard;
use App\Services\Finance\FinanceSettings;
use App\Services\Operator\BrandToday;
use App\Services\Sales\Agreements;
use App\Support\Demo\DemoMenu;
use App\Support\Finance\TaxNumber;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * yakup 2026-11-27: "neden vergi levhası vs. gibi şirket bilgilerini girebileceğimiz alanı oluşturmadık", "müşteriler
 * alanına menüden ulaşamıyorum", "şu an sistem tarafından herhangi bir müşteriye mail gitmesini istemiyorum".
 */
class BillingInfoTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        CarbonImmutable::setTestNow('2026-11-27 10:00:00');
        Storage::fake('local');
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function job(Brand $brand, bool $noInvoice = false): Agreement
    {
        return Agreement::query()->create(['brand_id' => $brand->id, 'customer_id' => $brand->customer_id, 'kind' => 'monthly', 'plan' => 'auto', 'fee' => 15000,
            'status' => 'active', 'starts_on' => '2026-11-01', 'due_day' => 15, 'no_invoice' => $noInvoice]);
    }

    public function test_an_invoiced_brand_without_billing_details_is_flagged_everywhere_and_its_todo_closes_once_filled(): void
    {
        $invoiced = Brand::factory()->create(['name' => 'Panorama']);
        $invoiced->customer->forceFill(['legal_name' => null])->save();
        $plain = Brand::factory()->create(['name' => 'Bornova Hurda']);
        $this->job($invoiced);
        $this->job($plain, noInvoice: true);

        $billing = app(BillingInfo::class);
        $this->assertSame([$invoiced->id], $billing->gaps()->map(fn (array $r): int => $r['brand']->id)->all(), 'a brand that takes no invoice needs nothing');
        $this->assertSame(['unvan', 'vergi no', 'vergi dairesi', 'fatura adresi'], $billing->gaps()->first()['missing']);

        $this->assertSame(['added' => 2, 'closed' => 0], $billing->sync(), 'one for the brand, one for our own company');
        $this->assertSame(['added' => 0, 'closed' => 0], $billing->sync(), 'never written twice');
        $todo = Reminder::query()->where('brand_id', $invoiced->id)->sole();
        $this->assertSame('Panorama için fatura bilgilerini Finans\'a gir', $todo->title);
        $this->assertStringContainsString('marka='.$invoiced->id, (string) $todo->url);
        $this->assertSame(1, Reminder::query()->where('title', BillingInfo::OWN_TODO)->count());

        $row = collect(app(BrandToday::class)->rows()['rows'])->firstWhere('id', $invoiced->id);
        $this->assertStringContainsString('Fatura bilgisi eksik', $row['line']);

        Livewire::test(FinancePage::class)->assertSee('Fatura bilgisi eksik')->assertSee('Panorama')
            ->call('editBilling', $invoiced->id)->assertSet('tab', 'bilgiler')
            ->set('billing.legal_name', 'Panorama Ankara Ağız ve Diş Sağlığı Hizmetleri Ltd. Şti.')
            ->set('billing.tax_no', '997 171 9846')
            ->set('billing.tax_office', 'Çankaya')
            ->set('billing.billing_address', 'Kızılay Mah. No: 1')
            ->set('billing.billing_email', 'Muhasebe@Panorama.test')
            ->set('billing.invoice_kind', 'e_fatura')
            ->call('saveBilling')->assertHasNoErrors()->assertSet('billingBrand', 0);

        $customer = $invoiced->refresh()->customer;
        $this->assertSame('9971719846', $customer->tax_no);
        $this->assertSame('muhasebe@panorama.test', $customer->billing_email);
        $this->assertNotNull($todo->refresh()->done_at, 'filling the details ticks the to-do at once');
        $this->assertSame('9971719846', Agreement::query()->where('brand_id', $invoiced->id)->value('tax_no'), 'the job gets the vergi no so Gmail invoices find it');
        $this->assertTrue($billing->gaps()->isEmpty());
    }

    public function test_a_wrong_vergi_no_is_refused(): void
    {
        $brand = Brand::factory()->create(['name' => 'Dentocare']);
        $this->job($brand);
        Livewire::test(FinancePage::class)->call('editBilling', $brand->id)->set('billing.tax_no', '12345')->call('saveBilling')->assertHasErrors('billing.tax_no')
            ->set('billing.tax_no', '9971719847')->call('saveBilling')->assertHasErrors('billing.tax_no');
    }

    public function test_our_company_details_iban_and_vergi_levhasi_are_kept_in_one_place(): void
    {
        Livewire::test(FinancePage::class, ['tab' => 'bilgiler'])->set('tab', 'bilgiler')->assertSee('Şirketimiz')->assertSee('Kapalı')
            ->set('company.title', 'Yakup Udül')
            ->set('company.kind', 'sahis')
            ->set('company.tax_no', '10000000146')
            ->set('company.tax_office', 'Manisa')
            ->set('company.address', 'Manisa')
            ->set('company.holder', 'Yakup Udül')
            ->set('company.iban', 'TR12 0006 4000 0011 2345 6789 01')
            ->call('saveCompany')->assertHasNoErrors()
            ->set('companyCertificate', UploadedFile::fake()->create('levha.pdf', 40, 'application/pdf'))
            ->assertHasNoErrors();

        $profile = app(CompanyProfile::class);
        $this->assertSame([], $profile->missing());
        $this->assertSame('10000000146', app(FinanceSettings::class)->get('own_tax_no'), 'the Gmail filter reads the same vergi no');
        $this->assertContains('yakup udül', app(FinanceSettings::class)->get('own_names'));
        $this->assertStringContainsString('IBAN: TR12 0006 4000 0011 2345 6789 01', $profile->sheet());
        $this->get(route('operator.finance.certificate', ['owner' => 'sirket']))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $brand = Brand::factory()->create(['name' => 'Panorama']);
        $agreement = $this->job($brand);
        app(Agreements::class)->ensurePayments($agreement);
        $payment = AgreementPayment::query()->where('agreement_id', $agreement->id)->firstOrFail();
        $this->assertStringContainsString('TR12 0006 4000', app(FinanceBoard::class)->reminderText($payment), 'the payment reminder says where to pay');

        Livewire::test(FinancePage::class)->set('company.iban', 'TR12')->call('saveCompany')->assertHasErrors('company.iban');
    }

    public function test_the_invoice_panel_shows_what_to_type_into_the_portal(): void
    {
        $brand = Brand::factory()->create(['name' => 'Panorama']);
        $agreement = $this->job($brand);
        app(BillingInfo::class)->save($brand, ['legal_name' => 'Panorama Ltd. Şti.', 'tax_no' => '9971719846', 'tax_office' => 'Çankaya', 'billing_address' => 'Kızılay', 'invoice_kind' => 'e_fatura']);
        app(Agreements::class)->ensurePayments($agreement);
        $payment = AgreementPayment::query()->where('agreement_id', $agreement->id)->firstOrFail();

        $sheet = app(BillingInfo::class)->invoiceSheet($payment->load('agreement.brand.customer'));
        $this->assertStringContainsString('Alıcı: Panorama Ltd. Şti.', $sheet['text']);
        $this->assertStringContainsString('Vergi no: 9971719846', $sheet['text']);
        $this->assertStringContainsString('Toplam: 18.000,00 TL', $sheet['text']);
        $this->assertTrue($sheet['e_fatura']);
        $this->assertSame([], $sheet['missing']);
    }

    public function test_no_mail_reaches_a_customer_address_while_the_switch_is_off(): void
    {
        $brand = Brand::factory()->create(['name' => 'Panorama']);
        $brand->customer->forceFill(['billing_email' => 'muhasebe@panorama.test'])->save();
        config(['mail.default' => 'array']);
        $guard = app(CustomerMailGuard::class);
        $this->assertFalse($guard->allowed(), 'off by default');

        Mail::to('muhasebe@panorama.test')->send(new OperatorTestMail);
        Mail::to('yakup@moximu.test')->send(new OperatorTestMail);
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent, 'only the mail to a non-customer left');

        Livewire::test(FinancePage::class)->set('tab', 'bilgiler')->call('setCustomerMail', true);
        $this->assertTrue($guard->allowed());
        Mail::to('muhasebe@panorama.test')->send(new OperatorTestMail);
        $this->assertCount(2, app('mailer')->getSymfonyTransport()->messages());
    }

    public function test_a_customer_filling_the_start_link_fills_only_empty_fields(): void
    {
        $brand = Brand::factory()->create(['name' => 'Panorama']);
        app(BillingInfo::class)->save($brand, ['legal_name' => 'Operatörün yazdığı unvan']);
        app(BillingInfo::class)->fromStartLink($brand->refresh(), ['title' => 'Müşterinin yazdığı', 'tax_no' => '9971719846', 'tax_office' => 'Çankaya', 'address' => 'Kızılay', 'email' => 'a@b.test']);

        $customer = $brand->refresh()->customer;
        $this->assertSame('Operatörün yazdığı unvan', $customer->legal_name);
        $this->assertSame('9971719846', $customer->tax_no);
        $this->assertSame('start_link', $customer->billing_source);
    }

    public function test_the_mcp_tool_reads_and_writes_billing_details(): void
    {
        $brand = Brand::factory()->create(['name' => 'Panorama']);
        $this->job($brand);

        MoxdopServer::tool(FinanceTool::class, ['action' => 'billing'])->assertOk()->assertSee('Panorama')->assertSee('vergi dairesi');
        MoxdopServer::tool(FinanceTool::class, ['action' => 'set_billing', 'data' => ['brand_id' => $brand->id, 'tax_office' => 'Çankaya']])->assertOk()->assertSee('kaydedildi');
        $this->assertSame('Çankaya', $brand->refresh()->customer->tax_office);
        MoxdopServer::tool(FinanceTool::class, ['action' => 'set_billing', 'data' => ['brand_id' => $brand->id, 'legal_name' => 'Panorama Ltd.']])->assertOk();
        $this->assertSame('Çankaya', $brand->refresh()->customer->tax_office, 'a field not given is kept');
    }

    public function test_vergi_and_tc_numbers_are_checked_by_their_check_digit(): void
    {
        $this->assertTrue(TaxNumber::valid('9971719846'));
        $this->assertFalse(TaxNumber::valid('9971719847'));
        $this->assertTrue(TaxNumber::valid('10000000146'));
        $this->assertFalse(TaxNumber::valid('11111111111'));
        $this->assertNull(TaxNumber::error(''));
    }

    public function test_the_levha_text_gives_unvan_daire_and_vergi_no(): void
    {
        $read = app(CertificateReader::class)->fromText("VERGİ LEVHASI\nTİCARET ÜNVANI\nPANORAMA ANKARA AĞIZ VE DİŞ SAĞLIĞI HİZMETLERİ LİMİTED ŞİRKETİ\nVERGİ DAİRESİ ÇANKAYA\nVERGİ KİMLİK NO 997 171 9846\nTAKVİM YILI 2025");
        $this->assertSame('PANORAMA ANKARA AĞIZ VE DİŞ SAĞLIĞI HİZMETLERİ LİMİTED ŞİRKETİ', $read['title']);
        $this->assertSame('ÇANKAYA', $read['tax_office']);
        $this->assertSame('9971719846', $read['tax_no']);
        $this->assertSame(['title' => null, 'tax_office' => null, 'tax_no' => null], app(CertificateReader::class)->read('not a pdf', 'image/jpeg'));
    }

    public function test_bilgi_iste_gives_a_ready_text_the_operator_sends(): void
    {
        $brand = Brand::factory()->create(['name' => 'Panorama']);
        $brand->customer->forceFill(['legal_name' => null])->save();
        $this->job($brand);
        Livewire::test(FinancePage::class)->set('tab', 'bilgiler')->call('askBilling', $brand->id)
            ->assertSee('faturanızı doğru düzenleyebilmemiz için')->assertSee('Vergi levhanızın');
    }

    public function test_customers_are_back_in_the_menu(): void
    {
        $routes = collect(DemoMenu::groups()[0]['items'])->firstWhere('route', 'operator.brands')['children'];
        $this->assertContains('operator.customers', array_column($routes, 'route'));
    }
}
