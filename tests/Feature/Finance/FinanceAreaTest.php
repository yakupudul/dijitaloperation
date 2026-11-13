<?php

namespace Tests\Feature\Finance;

use App\Livewire\Operator\Finance\FinancePage;
use App\Mcp\Servers\MoxdopServer;
use App\Mcp\Tools\Finance as FinanceTool;
use App\Models\AgencySetting;
use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\FinanceDocument;
use App\Models\User;
use App\Services\Finance\FinanceBoard;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\FinanceReader;
use App\Services\Finance\FinanceReminders;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\GmailInvoices;
use App\Services\Integrations\Google\GoogleWorkspace;
use App\Services\Moxi\MoxiActions;
use App\Services\Moxi\MoxiTools;
use App\Services\Sales\Agreements;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Finans (yakup 2026-11-12): payment tracking of every paid job. A job may have no brand, may be paid on typed dates,
 * may be paid in part and may renew once a year; invoices, expenses and dekonts are kept as files and found again.
 */
class FinanceAreaTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup Udul']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        CarbonImmutable::setTestNow('2026-11-12 10:00:00');
        Http::fake();
        Storage::fake('local');
        $this->brand = Brand::factory()->create(['name' => 'Panorama']);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_a_job_outside_the_panel_is_kept_with_its_payer_and_typed_installments(): void
    {
        $agreements = app(Agreements::class);
        $job = $agreements->start(null, ['kind' => 'one_time', 'party' => 'Ahmet Usta', 'party_phone' => '0532 111 22 33', 'title' => 'Web sitesi', 'plan' => 'schedule',
            'starts_on' => '2026-11-01', 'installments' => [['due_on' => '2026-11-15', 'amount' => '10.000', 'label' => 'Peşinat'], ['due_on' => '2026-12-15', 'amount' => '15000']]], $this->admin);

        $this->assertNull($job->brand_id);
        $this->assertSame('Ahmet Usta', $job->payer());
        $this->assertSame(25000, $job->wholePrice());
        $this->assertSame(2, $job->payments()->count());
        $this->assertSame(25000, $job->fresh('payments')->remaining());

        $this->expectException(ValidationException::class);
        $agreements->start(null, ['kind' => 'one_time', 'fee' => '5000', 'starts_on' => '2026-11-01'], $this->admin);
    }

    public function test_money_that_comes_in_fills_the_oldest_rows_and_says_what_remains(): void
    {
        $agreements = app(Agreements::class);
        $job = $agreements->start($this->brand, ['kind' => 'term', 'fee' => '10000', 'term_months' => 6, 'starts_on' => '2026-09-01', 'due_day' => 5], $this->admin);
        $this->assertSame(60000, $job->fresh('payments')->wholePrice());

        $result = $agreements->receive($job, 25000, '2026-11-12');
        $this->assertSame(25000, $result['applied']);
        $job = $job->fresh('payments');
        $this->assertSame(35000, $job->remaining());
        $rows = $job->payments->sortBy('period')->values();
        $this->assertNotNull($rows[0]->paid_on);
        $this->assertNotNull($rows[1]->paid_on);
        $this->assertTrue($rows[2]->isPartial());
        $this->assertSame(5000, $rows[2]->open());

        $agreements->unpay($rows[0]->fresh());
        $this->assertSame(45000, $job->fresh('payments')->remaining());
    }

    public function test_a_yearly_job_opens_its_row_ahead_and_a_fee_change_keeps_the_day_it_began(): void
    {
        $agreements = app(Agreements::class);
        $yearly = $agreements->start($this->brand, ['kind' => 'yearly', 'fee' => '1800', 'starts_on' => '2025-12-01', 'title' => 'Alan adı', 'beside' => true], $this->admin);
        $agreements->ensurePayments($yearly);
        $this->assertSame(['2025-12', '2026-12'], $yearly->payments()->orderBy('period')->pluck('period')->all());

        $monthly = $agreements->start($this->brand, ['kind' => 'monthly', 'fee' => '12000', 'starts_on' => '2025-06-01', 'due_day' => 5], $this->admin);
        $this->assertSame('2025-06-01', $monthly->feeSince()->toDateString());
        $agreements->update($monthly, ['kind' => 'monthly', 'fee' => '14000', 'starts_on' => '2025-06-01', 'due_day' => 5, 'fee_since' => '2026-11-01']);
        $monthly = $monthly->fresh('payments');
        $this->assertSame('2026-11-01', $monthly->feeSince()->toDateString());
        $this->assertSame(12000, (int) $monthly->payments->firstWhere('period', '2026-10')->amount);
        $this->assertSame(14000, (int) $monthly->payments->firstWhere('period', '2026-11')->amount);
    }

    public function test_an_e_invoice_xml_is_read_by_rule_and_finds_its_job_by_vergi_no(): void
    {
        $agreements = app(Agreements::class);
        $job = $agreements->start($this->brand, ['kind' => 'monthly', 'fee' => '10000', 'starts_on' => '2026-10-01', 'due_day' => 5, 'tax_no' => '1234567890'], $this->admin);
        app(FinanceSettings::class)->put(['own_tax_no' => '11111111111']);

        $docs = app(FinanceDocuments::class)->keep($this->invoiceXml('11111111111', 'Moximu', '1234567890', 'Panorama Diş', '12000.00', '2000.00'), 'fatura.xml', 'text/xml');
        $this->assertCount(1, $docs);
        $doc = $docs[0]->fresh();
        $this->assertSame(FinanceDocument::OUT, $doc->direction);
        $this->assertSame($job->id, $doc->agreement_id);
        $this->assertSame('GIB2026000000042', $doc->doc_no);
        $this->assertSame('12000.00', (string) $doc->amount);
        $this->assertNotNull($doc->payment_id);
        $this->assertTrue($doc->hasFile());

        // The same file again is not kept twice.
        $again = app(FinanceDocuments::class)->keep($this->invoiceXml('11111111111', 'Moximu', '1234567890', 'Panorama Diş', '12000.00', '2000.00'), 'fatura-kopya.xml', 'text/xml');
        $this->assertSame(1, FinanceDocument::query()->count());
        $this->assertSame($doc->id, $again[0]->id ?? $doc->id);

        // An invoice written to us is an expense.
        $in = app(FinanceDocuments::class)->keep($this->invoiceXml('9998887776', 'Hostinger Bilişim', '11111111111', 'Moximu', '600.00', '100.00'), 'gider.xml', 'text/xml');
        $this->assertSame(FinanceDocument::IN, $in[0]->fresh()->direction);
        $this->assertSame('sunucu', $in[0]->fresh()->category);
    }

    public function test_a_month_downloads_as_one_zip_with_folders_and_a_list(): void
    {
        app(FinanceSettings::class)->put(['own_tax_no' => '11111111111']);
        app(FinanceDocuments::class)->keep($this->invoiceXml('9998887776', 'Hostinger Bilişim', '11111111111', 'Moximu', '600.00', '100.00'), 'gider.xml', 'text/xml');
        app(FinanceDocuments::class)->manual(['direction' => 'in', 'counterparty' => 'Turkcell', 'amount' => '450,50', 'doc_date' => '2026-11-03', 'category' => 'iletisim'], $this->admin->id);

        $response = $this->get(route('operator.finance.zip', ['ay' => '2026-11']));
        $response->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $names = collect(range(0, $zip->numFiles - 1))->map(fn (int $i): string => (string) $zip->getNameIndex($i));
        $this->assertTrue($names->contains(fn (string $n): bool => str_starts_with($n, 'giderler/')));
        $list = (string) $zip->getFromName($names->first(fn (string $n): bool => str_starts_with($n, 'liste-')));
        $this->assertStringContainsString('Turkcell', $list);
        $this->assertStringContainsString('450,50', $list);
        $zip->close();

        $doc = FinanceDocument::query()->whereNotNull('path')->firstOrFail();
        $this->get(route('operator.finance.document', $doc))->assertOk();

        $other = User::factory()->create(['is_active' => true]);
        $this->actingAs($other)->get(route('operator.finance.zip', ['ay' => '2026-11']))->assertForbidden();
        $this->actingAs($other)->get(route('operator.finance.document', $doc))->assertForbidden();
    }

    public function test_the_month_board_lists_who_owes_what_and_the_raise_view_shows_the_melt(): void
    {
        $agreements = app(Agreements::class);
        $agreements->start($this->brand, ['kind' => 'monthly', 'fee' => '10000', 'starts_on' => '2025-05-01', 'due_day' => 5, 'services' => ['seo_gbp']], $this->admin);
        $agreements->start(null, ['kind' => 'one_time', 'party' => 'Ahmet Usta', 'fee' => '20000', 'starts_on' => '2026-11-10', 'title' => 'Web sitesi'], $this->admin);
        app(FinanceSettings::class)->put(['inflation' => 40.0]);

        $board = app(FinanceBoard::class);
        $month = $board->month(CarbonImmutable::parse('2026-11-01'));
        $this->assertSame(30000, (int) $month['totals']['expected']);
        $this->assertContains('Ahmet Usta · Web sitesi', collect($month['rows'])->pluck('name')->all());

        $raises = $board->raises();
        $row = $raises['rows']->first();
        $this->assertSame(18, $row['months']);
        $this->assertLessThan(10000, $row['worth']);
        $this->assertGreaterThan(10000, $row['same']);
        $this->assertSame(4000, $row['under_list']);
        $this->assertTrue($row['due']);
    }

    public function test_sellers_seen_in_two_months_become_subscriptions_with_the_next_day_and_a_price_rise(): void
    {
        $documents = app(FinanceDocuments::class);
        $documents->manual(['direction' => 'in', 'counterparty' => 'Ahrefs', 'amount' => '4000', 'doc_date' => '2026-09-08', 'category' => 'yazilim'], $this->admin->id);
        $documents->manual(['direction' => 'in', 'counterparty' => 'Ahrefs', 'amount' => '4000', 'doc_date' => '2026-10-08', 'category' => 'yazilim'], $this->admin->id);
        $documents->manual(['direction' => 'in', 'counterparty' => 'Ahrefs', 'amount' => '5000', 'doc_date' => '2026-11-08', 'category' => 'yazilim'], $this->admin->id);

        $subs = app(FinanceBoard::class)->subscriptions();
        $this->assertCount(1, $subs);
        $this->assertSame('aylık', $subs[0]['every']);
        $this->assertSame('2026-12-08', CarbonImmutable::parse($subs[0]['next'])->toDateString());
        $this->assertSame(25, (int) round($subs[0]['up']));
    }

    public function test_the_phone_is_told_once_on_the_day_and_once_when_a_payment_runs_late(): void
    {
        $agreements = app(Agreements::class);
        $agreements->start($this->brand, ['kind' => 'monthly', 'fee' => '10000', 'starts_on' => '2026-11-01', 'due_day' => 12], $this->admin);
        $late = $agreements->start(null, ['kind' => 'one_time', 'party' => 'Ahmet Usta', 'fee' => '20000', 'starts_on' => '2026-11-02'], $this->admin);

        $first = app(FinanceReminders::class)->run();
        $this->assertSame(1, $first['due']);
        $this->assertSame(1, $first['late']);
        $second = app(FinanceReminders::class)->run();
        $this->assertSame(0, $second['due']);
        $this->assertSame(0, $second['late']);
        $this->assertNotNull($late->payments()->first()->late_reminded_on);
    }

    public function test_the_screen_opens_a_job_takes_a_payment_and_keeps_an_expense(): void
    {
        $page = Livewire::test(FinancePage::class)->assertOk()->assertSee('Finans');

        $page->call('newJob')
            ->set('job.party', 'Ahmet Usta')->set('job.title', 'Web sitesi')->set('job.kind', 'one_time')->set('job.plan', 'schedule')
            ->set('job.installments', [['due_on' => '2026-11-10', 'amount' => '10000'], ['due_on' => '2026-12-10', 'amount' => '10000']])
            ->call('saveJob')->assertHasNoErrors()->assertSee('Ahmet Usta');
        $job = Agreement::query()->firstOrFail();
        $this->assertSame(20000, $job->wholePrice());

        $row = AgreementPayment::query()->orderBy('due_on')->firstOrFail();
        $page->set('tab', 'bu-ay')->assertSee('Ahmet Usta')
            ->call('openPanel', $row->id, 'pay')->set('pay.amount', '6.000')->set('pay.vat', 'no')->call('receive');
        $this->assertSame(14000, $job->fresh('payments')->remaining());
        $page->call('paid', $row->id);
        $this->assertSame(10000, $job->fresh('payments')->remaining());

        $page->call('newExpense')->set('doc.counterparty', 'Hostinger')->set('doc.amount', '1.200,00')->set('doc.category', 'sunucu')->call('saveDoc')->assertHasNoErrors();
        $this->assertSame('1200.00', (string) FinanceDocument::query()->firstOrFail()->amount);
        $page->set('tab', 'giderler')->assertSee('Hostinger');
        $page->set('tab', 'faturalar')->assertOk();
        $page->set('tab', 'anlasmalar')->set('view', 'zam')->assertOk();

        $this->actingAs(User::factory()->create(['is_active' => true]));
        Livewire::test(FinancePage::class)->assertForbidden();
    }

    public function test_finance_is_edited_by_word_over_mcp_and_through_moxi_cards(): void
    {
        MoxdopServer::tool(FinanceTool::class, ['action' => 'add_job', 'data' => ['party' => 'Ahmet Usta', 'title' => 'Web sitesi', 'kind' => 'one_time',
            'installments' => [['due_on' => '2026-11-10', 'amount' => 10000], ['due_on' => '2026-12-10', 'amount' => 20000]]]])->assertOk()->assertSee('Ahmet Usta');
        $job = Agreement::query()->firstOrFail();
        $this->assertSame(30000, $job->wholePrice());

        MoxdopServer::tool(FinanceTool::class, ['action' => 'record_payment', 'preview' => true, 'data' => ['agreement_id' => $job->id, 'amount' => 12000]])->assertOk()->assertSee('Önizleme');
        $this->assertSame(30000, $job->fresh('payments')->remaining());
        MoxdopServer::tool(FinanceTool::class, ['action' => 'record_payment', 'data' => ['agreement_id' => $job->id, 'amount' => 12000]])->assertOk()->assertSee('Kalan 20.000');
        MoxdopServer::tool(FinanceTool::class, ['action' => 'jobs', 'q' => 'ahmet'])->assertOk()->assertSee('Web sitesi');
        MoxdopServer::tool(FinanceTool::class, ['action' => 'summary'])->assertOk()->assertSee('Ahmet Usta');
        MoxdopServer::tool(FinanceTool::class, ['action' => 'add_job', 'data' => ['party' => 'Kim']])->assertHasErrors();
        MoxdopServer::tool(FinanceTool::class, ['action' => 'add_expense', 'data' => ['counterparty' => 'Hostinger', 'amount' => 1200, 'category' => 'sunucu']])->assertOk();
        $this->assertSame(1, FinanceDocument::query()->where('direction', 'in')->count());

        // Moxi: the card shows what will happen; only Yap writes.
        $monthly = app(Agreements::class)->start($this->brand, ['kind' => 'monthly', 'fee' => '12000', 'starts_on' => '2026-01-01', 'due_day' => 5], $this->admin);
        $answer = app(MoxiTools::class)->call('finans_is_degistir', ['agreement_id' => $monthly->id, 'fee' => 15000, 'fee_since' => '2026-12-01'], $this->admin, null);
        $this->assertNotNull($answer['message']);
        $this->assertSame(12000, (int) $monthly->fresh()->fee);
        app(MoxiActions::class)->run($answer['message'], $this->admin);
        $this->assertSame(15000, (int) $monthly->fresh()->fee);
        $this->assertSame('2026-12-01', $monthly->fresh()->feeSince()->toDateString());
        $this->assertStringContainsString('Panorama', app(MoxiTools::class)->call('finans_ozet', [], $this->admin, null)['text']);
        $this->assertStringStartsWith('HATA', app(MoxiTools::class)->call('finans_odeme', ['agreement_id' => 999999, 'amount' => 5], $this->admin, null)['text']);
    }

    public function test_gmail_attachments_are_pulled_once_and_filed(): void
    {
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleWorkspace::GMAIL]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'expires_at' => now()->addHour(),
            'encrypted_payload' => ['access_token' => 'gmail-token', 'refresh_token' => 'r', 'scope' => GoogleWorkspace::GMAIL]]);
        app(FinanceSettings::class)->put(['own_tax_no' => '11111111111']);
        $xml = $this->invoiceXml('9998887776', 'Hostinger Bilişim', '11111111111', 'Moximu', '600.00', '100.00');
        Http::swap(new Factory);
        Http::fake([
            'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1']]]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1/attachments/*' => Http::response(['data' => rtrim(strtr(base64_encode($xml), '+/', '-_'), '=')]),
            'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response(['payload' => ['headers' => [['name' => 'From', 'value' => 'fatura@hostinger.com'], ['name' => 'Subject', 'value' => 'Faturanız']],
                'parts' => [['filename' => 'fatura.xml', 'mimeType' => 'text/xml', 'body' => ['attachmentId' => 'a1', 'size' => strlen($xml)]]]]]),
        ]);

        $gmail = app(GmailInvoices::class);
        $this->assertTrue($gmail->linked());
        $first = $gmail->sync();
        $this->assertTrue($first['ok'], (string) $first['error']);
        $this->assertSame(1, $first['documents']);
        $doc = FinanceDocument::query()->firstOrFail();
        $this->assertSame('gmail', $doc->source);
        $this->assertSame(FinanceDocument::IN, $doc->direction);
        $this->assertSame('600.00', (string) $doc->amount);

        $gmail->sync();
        $this->assertSame(1, FinanceDocument::query()->count());
        $this->assertTrue($gmail->state()['backfill_done']);
    }

    public function test_from_the_mailbox_only_papers_in_our_own_name_are_taken(): void
    {
        $docs = app(FinanceDocuments::class);
        // Written to someone else: nothing of it is kept but a mark, so the mail is not read again.
        $other = $docs->keepSet([['bytes' => $this->invoiceXml('9998887776', 'Hostinger Bilişim', '5554443332', 'Başka Klinik Ltd', '600.00', '100.00'), 'name' => 'a.xml']], ['source' => 'gmail', 'external_id' => 'gmail:m9:a']);
        $this->assertSame('ignored', $other[0]->status);
        $this->assertNull($other[0]->path);
        $this->assertNull($other[0]->counterparty);
        $this->assertNull($other[0]->amount);
        $this->assertSame(FinanceDocuments::NOT_MINE, data_get($other[0]->meta, 'reason'));
        Livewire::test(FinancePage::class)->set('tab', 'faturalar')->set('direction', 'yok')->assertDontSee('a.xml')->call('restoreDoc', $other[0]->id);
        $this->assertSame('ignored', $other[0]->fresh()->status);

        // Written to "UDÜL YAKUP" (no vergi no typed yet) and one we wrote as Moximu: both are taken.
        $mine = $docs->keepSet([['bytes' => $this->invoiceXml('9998887776', 'Hostinger Bilişim', '22222222222', 'UDÜL YAKUP', '700.00', '100.00'), 'name' => 'b.xml']], ['source' => 'gmail', 'external_id' => 'gmail:m9:b']);
        $this->assertSame('new', $mine[0]->status);
        $this->assertNotNull($mine[0]->path);
        $wrote = $docs->keepSet([['bytes' => $this->invoiceXml('33333333333', 'Moximu Dijital', '5554443332', 'Başka Klinik Ltd', '800.00', '100.00'), 'name' => 'c.xml']], ['source' => 'gmail', 'external_id' => 'gmail:m9:c']);
        $this->assertSame(FinanceDocument::OUT, $wrote[0]->direction);
        $this->assertNotSame('ignored', $wrote[0]->status);

        // The same paper uploaded by hand is the operator's own choice.
        $hand = $docs->keep($this->invoiceXml('9998887776', 'Hostinger Bilişim', '5554443332', 'Başka Klinik Ltd', '900.00', '100.00'), 'd.xml');
        $this->assertNotSame('ignored', $hand[0]->status);

        // One taken before the rule is read once more and dropped with its file.
        $xml = $this->invoiceXml('9998887776', 'Natro', '5554443332', 'Başka Klinik Ltd', '950.00', '100.00');
        Storage::disk(FinanceDocuments::DISK)->put('finance/old/e.xml', $xml);
        $old = FinanceDocument::query()->create(['direction' => FinanceDocument::IN, 'source' => 'gmail', 'status' => 'filed', 'original_name' => 'e.xml', 'mime' => 'text/xml', 'path' => 'finance/old/e.xml',
            'sha256' => hash('sha256', $xml), 'doc_date' => '2026-10-01', 'counterparty' => 'Natro', 'amount' => 950, 'currency' => 'TRY', 'meta' => ['subject' => 'Fatura']]);
        $done = $docs->recheckMail();
        $this->assertSame(1, $done['dropped']);
        $this->assertSame(0, $done['left']);
        $this->assertSame('ignored', $old->fresh()->status);
        $this->assertNull($old->fresh()->amount);
        Storage::disk(FinanceDocuments::DISK)->assertMissing('finance/old/e.xml');
        $this->assertSame('new', $mine[0]->fresh()->status);
        $this->assertSame(2, app(GmailInvoices::class)->state()['not_mine']);
    }

    public function test_a_pdf_is_read_by_the_claude_api_and_its_cost_is_kept(): void
    {
        config(['moxdop.anthropic.api_key' => 'configured', 'ai.providers.anthropic.key' => 'configured']);
        $job = app(Agreements::class)->start(null, ['kind' => 'one_time', 'party' => 'Ahmet Usta', 'fee' => '20000', 'starts_on' => '2026-11-10'], $this->admin);
        Http::swap(new Factory);
        Http::fake(['*/messages' => Http::response(['usage' => ['input_tokens' => 1500, 'output_tokens' => 120], 'content' => [['type' => 'tool_use', 'name' => 'belge', 'input' => [
            'kind' => 'receipt', 'doc_no' => 'TR123', 'doc_date' => '2026-11-11', 'seller' => 'AHMET USTA', 'buyer' => 'Moximu', 'amount' => 24000, 'currency' => 'TRY', 'summary' => 'Web sitesi ödemesi']]]])]);
        if (app(FinanceReader::class)->held() !== null) {
            $this->markTestSkipped('Claude API is not configured in this environment: '.app(FinanceReader::class)->held());
        }

        $docs = app(FinanceDocuments::class)->keep("%PDF-1.4\n%dekont", 'dekont.pdf', 'application/pdf', ['agreement_id' => $job->id]);
        $doc = $docs[0]->fresh();
        $this->assertSame(FinanceDocument::RECEIPT, $doc->direction);
        $this->assertSame('24000.00', (string) $doc->amount);
        $this->assertSame('2026-11-11', $doc->doc_date->toDateString());

        // 24.000 with KDV is the 20.000 row: the dekont closes it.
        Livewire::test(FinancePage::class)->call('applyReceipt', $doc->id);
        $this->assertSame(0, $job->fresh('payments')->remaining());
    }

    public function test_the_finance_tick_is_scheduled(): void
    {
        $names = collect(Schedule::events())->map(fn ($event): string => (string) ($event->description ?? ''))->all();
        $this->assertContains('finance-tick', $names);
    }

    private function invoiceXml(string $sellerTax, string $seller, string $buyerTax, string $buyer, string $payable, string $vat): string
    {
        $party = fn (string $name, string $tax): string => '<cac:Party><cac:PartyIdentification><cbc:ID schemeID="'.(strlen($tax) === 11 ? 'TCKN' : 'VKN').'">'.$tax.'</cbc:ID></cac:PartyIdentification>'
            .'<cac:PartyName><cbc:Name>'.$name.'</cbc:Name></cac:PartyName></cac:Party>';

        return '<?xml version="1.0" encoding="UTF-8"?><Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" '
            .'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">'
            .'<cbc:ID>GIB2026000000042</cbc:ID><cbc:IssueDate>2026-11-05</cbc:IssueDate><cbc:DocumentCurrencyCode>TRY</cbc:DocumentCurrencyCode>'
            .'<cac:AccountingSupplierParty>'.$party($seller, $sellerTax).'</cac:AccountingSupplierParty><cac:AccountingCustomerParty>'.$party($buyer, $buyerTax).'</cac:AccountingCustomerParty>'
            .'<cac:TaxTotal><cbc:TaxAmount currencyID="TRY">'.$vat.'</cbc:TaxAmount></cac:TaxTotal>'
            .'<cac:LegalMonetaryTotal><cbc:PayableAmount currencyID="TRY">'.$payable.'</cbc:PayableAmount></cac:LegalMonetaryTotal></Invoice>';
    }
}
