<?php

namespace Tests\Feature\Finance;

use App\Livewire\Operator\Finance\FinancePage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\Finance;
use App\Models\AgencySetting;
use App\Models\Agreement;
use App\Models\FinanceDocument;
use App\Models\User;
use App\Services\Finance\FinanceBoard;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\FinanceReader;
use App\Services\Sales\Agreements;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Finans › Özet (yakup 2026-11-13: "dümdüz, gaza getirmiyor; tarih seçimi web sitesi ekranındaki gibi kapsamlı olsun")
 * and the mailbox read without the Claude API ("adıma olan tüm faturaları istiyorum").
 */
class FinanceOverviewTest extends TestCase
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
        CarbonImmutable::setTestNow('2026-11-13 10:00:00');
        Http::fake();
        Storage::fake(FinanceDocuments::DISK);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_any_stretch_of_days_shows_what_comes_who_is_late_the_goal_and_the_raises(): void
    {
        $agreements = app(Agreements::class);
        $big = $agreements->start(null, ['kind' => 'monthly', 'party' => 'Avrupadent', 'fee' => '50000', 'starts_on' => '2026-10-01', 'due_day' => 15], $this->admin);
        $agreements->start(null, ['kind' => 'monthly', 'party' => 'Buşra Nur Özger', 'fee' => '20000', 'starts_on' => '2026-11-01', 'due_day' => 25,
            'next_fee' => '30000', 'next_fee_when' => 'Google Ads başlayınca'], $this->admin);
        $agreements->start(null, ['kind' => 'one_time', 'party' => 'Decco Perde', 'fee' => '15000', 'starts_on' => '2026-10-05'], $this->admin);

        $board = app(FinanceBoard::class)->period(CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-12-31'));
        // November is written, December is what the running agreements will ask for.
        $this->assertSame(140000, $board['totals']['expected']);
        $this->assertSame(['Avrupadent', 'Buşra Nur Özger'], $board['payers']->pluck('name')->sort()->values()->all());
        $this->assertSame('2026-11-15', $board['next']['day']->toDateString());
        $this->assertSame(2, $board['next']['in_days']);
        // October's Avrupadent and the site are late from before.
        $this->assertSame(65000, (int) $board['late']->sum('open'));
        $this->assertSame(['count' => 2, 'target' => 100], ['count' => $board['goal']['count'], 'target' => $board['goal']['target']]);
        $this->assertSame(['Buşra Nur Özger'], $board['goal']['new']);
        $this->assertSame(70000, $board['mrr']['monthly']);
        $this->assertSame([['name' => 'Buşra Nur Özger', 'plus' => 10000, 'when' => 'Google Ads başlayınca']], $board['mrr']['raises']);
        $this->assertSame('day', $board['flow']['by']);
        $this->assertCount(61, $board['flow']['cells']);

        // Money that came is drawn on its day.
        $agreements->markPaid($big->fresh('payments')->payments->firstWhere('period', '2026-10'));
        $year = app(FinanceBoard::class)->period(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-12-31'));
        $this->assertSame('month', $year['flow']['by']);
        $this->assertSame(50000, $year['flow']['came']);

        // Raising the fee to the agreed one clears the waiting raise.
        $agreements->update($agreements->start(null, ['kind' => 'monthly', 'party' => 'OPC', 'fee' => '18500', 'starts_on' => '2026-11-01', 'next_fee' => '25000'], $this->admin), ['fee' => '25000']);
        $this->assertNull(Agreement::query()->where('party', 'OPC')->first()->next_fee);
    }

    public function test_the_overview_opens_on_this_month_and_takes_any_range_and_steps(): void
    {
        app(Agreements::class)->start(null, ['kind' => 'monthly', 'party' => 'Demiderm', 'fee' => '15000', 'starts_on' => '2026-11-01', 'due_day' => 15], $this->admin);

        $page = Livewire::test(FinancePage::class)
            ->assertSee('Özet')->assertSee('Kasım\'da müşterilerinden alacağın')->assertSee('15.000 ₺')->assertSee('2027 sonu hedefi')->assertSee('Bu ay')
            ->assertSee('Gelecek ay');
        $page->call('shift', 1)->assertSet('from', '2026-12-01')->assertSet('to', '2026-12-31')->assertSee('Aralık\'ta müşterilerinden alacağın')->assertSee('Gelecek ay');
        $page->call('setRange', 28, '2026-11-01', '2027-01-31', '')->assertSet('to', '2027-01-31')->assertSee('Bu dönemde müşterilerinden alacağın')->assertSee('45.000 ₺');
        $page->call('shift', -1)->assertSet('from', '2026-08-01')->assertSet('to', '2026-10-31');
        // An old link's month still opens that month.
        Livewire::withQueryParams(['ay' => '2026-10'])->test(FinancePage::class)->assertSee('Ekim\'de müşterilerinden alacağın');
    }

    public function test_a_pdf_with_text_is_read_by_rule_when_the_api_cannot_be_used(): void
    {
        config(['ai.providers.anthropic.key' => '']);
        $this->assertNotNull(app(FinanceReader::class)->held());
        $docs = app(FinanceDocuments::class);

        $ours = $this->pdf(['TURKCELL ILETISIM HIZMETLERI A.S.', 'VKN: 3110045623', 'e-Arsiv Fatura', 'SAYIN', 'YAKUP UDUL', 'TCKN: 12345678901',
            'Fatura No: TCL2026000123456', 'Fatura Tarihi: 05-11-2026', 'Hesaplanan KDV(%20): 150,00', 'Odenecek Tutar: 1.250,50 TL']);
        $kept = $docs->keepSet([['bytes' => $ours, 'name' => 'fatura.pdf', 'mime' => 'application/pdf']], ['source' => 'gmail', 'external_id' => 'gmail:a1:fatura']);
        $doc = $kept[0]->fresh();
        $this->assertSame('new', $doc->status);
        $this->assertSame('1250.50', (string) $doc->amount);
        $this->assertSame('2026-11-05', $doc->doc_date->toDateString());
        $this->assertSame('TCL2026000123456', $doc->doc_no);
        $this->assertSame('text', data_get($doc->meta, 'read_by'));

        // Someone else's invoice is only a mark.
        $theirs = $this->pdf(['NATRO HOSTING', 'VKN: 1111111111', 'e-Arsiv Fatura', 'SAYIN', 'BASKA KLINIK LTD', 'Odenecek Tutar: 500,00 TL']);
        $mark = $docs->keepSet([['bytes' => $theirs, 'name' => 'b.pdf', 'mime' => 'application/pdf']], ['source' => 'gmail', 'external_id' => 'gmail:a2:b'])[0]->fresh();
        $this->assertSame(FinanceDocuments::NOT_MINE, data_get($mark->meta, 'reason'));

        // A scan with no text waits out of sight with its file, for a later read; the mail is not lost.
        $scan = $docs->keepSet([['bytes' => "%PDF-1.4\n%tarama", 'name' => 'scan.pdf', 'mime' => 'application/pdf']], ['source' => 'gmail', 'external_id' => 'gmail:a3:scan'])[0]->fresh();
        $this->assertSame('ignored', $scan->status);
        $this->assertSame(FinanceDocuments::UNVERIFIED, data_get($scan->meta, 'reason'));
        Storage::disk(FinanceDocuments::DISK)->assertExists($scan->path);
        $this->assertSame(1, FinanceDocument::query()->where('meta->reason', FinanceDocuments::UNVERIFIED)->count());

        // The same invoice mailed again in another file is kept once.
        $again = $this->pdf(['TURKCELL ILETISIM HIZMETLERI A.S.', 'VKN: 3110045623', 'e-Arsiv Fatura', 'SAYIN', 'YAKUP UDUL', 'TCKN: 12345678901',
            'Fatura No: TCL2026000123456', 'Fatura Tarihi: 05-11-2026', 'Hesaplanan KDV(%20): 150,00', 'Odenecek Tutar: 1.250,50 TL', 'Kopya']);
        $twin = $docs->keepSet([['bytes' => $again, 'name' => 'fatura-2.pdf', 'mime' => 'application/pdf']], ['source' => 'gmail', 'external_id' => 'gmail:a4:fatura'])[0]->fresh();
        $this->assertSame('ignored', $twin->status);
        $this->assertSame($doc->id, data_get($twin->meta, 'same_as'));
        $this->assertNull(data_get($twin->meta, 'reason'));
        Storage::disk(FinanceDocuments::DISK)->assertExists($twin->path);
        $this->assertSame('new', $doc->fresh()->status);
        $this->assertSame(1, FinanceDocument::query()->where('doc_no', 'TCL2026000123456')->where('status', '!=', 'ignored')->count());
    }

    public function test_a_paper_that_opens_with_the_buyer_still_finds_the_seller_and_both_tax_numbers(): void
    {
        $reader = app(FinanceReader::class);
        $buyerFirst = implode("\n", ['SAYIN', 'Moximu - Yakup Udül', 'Şehzadeler/ Manisa', 'VKN: 1234567890', 'Fatura No: EAR2026000023779',
            'Fatura Tarihi: 10-11-2026', 'Ödeme Yöntemi: Kredi Kartı İle Ödendi', 'E-Arşiv izni kapsamında elektronik ortamda iletilmiştir.',
            'Veridyen Bilişim Teknolojileri Sanayi ve Ticaret', 'Limited Şirketi', 'Vergi Dairesi: EGE', 'VKN: 9240513083', 'e-Arşiv Fatura',
            'Hesaplanan KDV(%20) 436,80TL', 'Vergiler Dahil Toplam Tutar 2.620,80TL', 'Ödenecek Tutar 2.620,80TL']);
        $read = $reader->fromText($buyerFirst);
        $this->assertSame('Veridyen Bilişim Teknolojileri Sanayi ve Ticaret', $read['seller']);
        $this->assertSame(['9240513083', '1234567890'], [$read['seller_tax_no'], $read['buyer_tax_no']]);
        $this->assertSame([2620.8, 436.8, '2026-11-10'], [$read['amount'], $read['vat'], $read['doc_date']]);

        $sellerFirst = $reader->fromText(implode("\n", ['TURKCELL ILETISIM HIZMETLERI A.S.', 'VKN: 3110045623', 'e-Arsiv Fatura', 'SAYIN', 'YAKUP UDUL', 'TCKN: 12345678901', 'Odenecek Tutar: 10,00 TL']));
        $this->assertSame(['TURKCELL ILETISIM HIZMETLERI A.S.', '3110045623', '12345678901'], [$sellerFirst['seller'], $sellerFirst['seller_tax_no'], $sellerFirst['buyer_tax_no']]);
    }

    public function test_a_claude_subscription_round_reads_what_no_rule_could_through_the_mcp_tool(): void
    {
        config(['ai.providers.anthropic.key' => '']);
        $docs = app(FinanceDocuments::class);
        $scan = $docs->keepSet([['bytes' => "%PDF-1.4\n%tarama1", 'name' => 'a.pdf', 'mime' => 'application/pdf']], ['source' => 'gmail', 'external_id' => 'gmail:b1:a', 'subject' => 'Faturanız'])[0];
        $other = $docs->keepSet([['bytes' => "%PDF-1.4\n%tarama2", 'name' => 'b.pdf', 'mime' => 'application/pdf']], ['source' => 'gmail', 'external_id' => 'gmail:b2:b'])[0];

        $list = MoxdopServer::tool(Finance::class, ['action' => 'unread_papers']);
        $list->assertOk()->assertSee('Faturanız')->assertSee('file_url');
        $url = $docs->unread()[0]['file_url'];
        $this->get($url)->assertOk();
        $this->get(route('finance.paper', ['document' => $scan->id]))->assertForbidden();

        MoxdopServer::tool(Finance::class, ['action' => 'read_paper', 'data' => ['id' => $scan->id, 'mine' => true, 'kind' => 'invoice',
            'seller' => 'Vodafone', 'buyer' => 'Yakup Udül', 'amount' => 899.9, 'doc_date' => '2026-10-20', 'currency' => 'TRY']])->assertOk()->assertSee('Belge alındı');
        $this->assertSame('new', $scan->fresh()->status);
        $this->assertSame('Vodafone', $scan->fresh()->counterparty);
        $this->assertSame('899.90', (string) $scan->fresh()->amount);

        MoxdopServer::tool(Finance::class, ['action' => 'read_paper', 'data' => ['id' => $other->id, 'mine' => false]])->assertOk();
        $this->assertSame(FinanceDocuments::NOT_MINE, data_get($other->fresh()->meta, 'reason'));
        $this->assertNull($other->fresh()->path);
        // Once read, the link serves nothing.
        $this->get($url)->assertNotFound();
    }

    /** A one-page PDF whose lines are real text (Helvetica, ASCII). */
    private function pdf(array $lines): string
    {
        $stream = "BT /F1 11 Tf 14 TL 50 780 Td\n".implode("\n", array_map(fn (string $l): string => '('.addcslashes($l, '()\\').') Tj T*', $lines))."\nET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];
        $out = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $body) {
            $offsets[] = strlen($out);
            $out .= ($i + 1)." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($out);
        $out .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $o) {
            $out .= sprintf("%010d 00000 n \n", $o);
        }

        return $out.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
    }
}
