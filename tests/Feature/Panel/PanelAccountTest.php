<?php

namespace Tests\Feature\Panel;

use App\Livewire\Panel\AccountPage;
use App\Models\Agreement;
use App\Models\FinanceDocument;
use App\Models\User;
use App\Panel\Models\PanelTicket;
use App\Panel\Support\PanelContext;
use App\Services\Sales\Agreements;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/** Hesap: a brand's manager sees only its own services, payments, opened invoices and renewals. */
final class PanelAccountTest extends PanelTestCase
{
    private Agreement $jobA;

    private Agreement $jobB;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $admin = User::factory()->create(['is_active' => true]);
        $agreements = app(Agreements::class);
        $this->jobA = $agreements->start($this->brandA, ['kind' => 'monthly', 'fee' => '14000', 'starts_on' => now('Europe/Istanbul')->subMonths(2)->startOfMonth()->toDateString(), 'due_day' => 1, 'services' => ['seo_gbp'], 'title' => 'Akasya aylık çalışma'], $admin);
        $this->jobB = $agreements->start($this->brandB, ['kind' => 'monthly', 'fee' => '9000', 'starts_on' => now('Europe/Istanbul')->subMonths(2)->startOfMonth()->toDateString(), 'due_day' => 1, 'services' => ['google_ads'], 'title' => 'Begonya reklam işi'], $admin);
        $yearly = $agreements->start($this->brandA, ['kind' => 'yearly', 'fee' => '1800', 'starts_on' => now('Europe/Istanbul')->addDays(20)->subYear()->toDateString(), 'title' => 'Alan adı', 'beside' => true], $admin);
        $agreements->ensurePayments($yearly);
        $agreements->markPaid($yearly->payments()->orderBy('period')->firstOrFail());
        $agreements->ensurePayments($this->jobA);
        $agreements->ensurePayments($this->jobB);
    }

    public function test_only_the_manager_opens_hesap(): void
    {
        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $this->asPanel($sales)->get($this->url('/hesap'))->assertForbidden();
        $this->asPanel($sales)->get($this->url('/'))->assertOk()->assertDontSee('Hesap');
        $this->asPanel($this->managerA)->get($this->url('/hesap'))->assertOk()
            ->assertSeeInOrder(['data-panel-tabs', 'Hizmetler', 'Faturalar', 'Ödemeler', 'Yenilemeler'], false)
            ->assertSee('Akasya aylık çalışma')->assertSee('14.000 ₺/ay + KDV')->assertDontSee('Begonya reklam işi')
            ->assertSee('Google Ads')->assertSee('Teklif iste');
    }

    public function test_payments_show_only_the_brands_rows_and_a_receipt_reaches_finans(): void
    {
        $this->asPanel($this->managerA)->get($this->url('/hesap/odemeler'))->assertOk()->assertSee('Bekleyen ödemeler')->assertSee('Dekont yükle')->assertDontSee('9.000');
        $this->enter($this->managerA, $this->brandA);
        $rowA = $this->jobA->payments()->whereNull('paid_on')->firstOrFail();
        $rowB = $this->jobB->payments()->whereNull('paid_on')->firstOrFail();

        Livewire::test(AccountPage::class, ['tab' => 'odemeler'])->set('paying', $rowA->id)->set('receipt', UploadedFile::fake()->create('dekont.pdf', 40, 'application/pdf'))
            ->call('sendReceipt')->assertHasNoErrors()->assertSee('bankada bakılıyor');
        $doc = FinanceDocument::query()->where('direction', FinanceDocument::RECEIPT)->firstOrFail();
        $this->assertSame([$this->brandA->id, $rowA->id, 'new', 'panel'], [(int) $doc->brand_id, (int) $doc->payment_id, $doc->status, $doc->source]);
        Storage::disk('local')->assertExists($doc->path);

        Livewire::test(AccountPage::class, ['tab' => 'odemeler'])->set('paying', $rowB->id)->set('receipt', UploadedFile::fake()->create('dekont.pdf', 40, 'application/pdf'))
            ->call('sendReceipt');
        $this->assertSame(1, FinanceDocument::query()->count());
    }

    public function test_an_invoice_is_seen_only_after_the_operator_opens_it_and_only_by_its_brand(): void
    {
        Storage::disk('local')->put('finance/2026/11/a.pdf', '%PDF-1.4 test');
        $invoice = FinanceDocument::query()->create(['direction' => FinanceDocument::OUT, 'source' => 'upload', 'status' => 'filed', 'path' => 'finance/2026/11/a.pdf', 'doc_date' => '2026-11-05', 'doc_no' => 'MOX2026000012', 'amount' => 16800, 'agreement_id' => $this->jobA->id, 'brand_id' => $this->brandA->id]);

        $this->asPanel($this->managerA)->get($this->url('/hesap/faturalar'))->assertOk()->assertDontSee('MOX2026000012');
        $this->asPanel($this->managerA)->get($this->url('/hesap/fatura/'.$invoice->id))->assertNotFound();
        $invoice->forceFill(['panel_visible' => true])->save();
        $this->asPanel($this->managerA)->get($this->url('/hesap/faturalar'))->assertOk()->assertSee('MOX2026000012');
        $this->asPanel($this->managerA)->get($this->url('/hesap/fatura/'.$invoice->id))->assertOk();
        $this->asPanel($this->managerB)->get($this->url('/hesap/fatura/'.$invoice->id))->assertNotFound();
        $this->asPanel($this->managerB)->get($this->url('/hesap/faturalar'))->assertOk()->assertDontSee('MOX2026000012');
    }

    public function test_renewals_and_offer_request(): void
    {
        $this->asPanel($this->managerA)->get($this->url('/hesap/yenilemeler'))->assertOk()->assertSee('Alan adı')->assertSee('gün kaldı')->assertSee('1.800 ₺/yıl');
        $this->asPanel($this->managerB)->get($this->url('/hesap/yenilemeler'))->assertOk()->assertDontSee('1.800')->assertSee('Yıllık yenilenen bir hizmetiniz yok.');

        $this->enter($this->managerA, $this->brandA);
        Livewire::test(AccountPage::class)->call('offer', 'google_ads')->call('offer', 'google_ads')->call('offer', 'seo_gbp')->assertNotFound();
        $tickets = app(PanelContext::class)->system(fn () => PanelTicket::query()->get());
        $this->assertCount(1, $tickets);
        $this->assertSame(['Teklif isteği: Google Ads', $this->brandA->id], [$tickets[0]->subject, (int) $tickets[0]->brand_id]);
    }
}
