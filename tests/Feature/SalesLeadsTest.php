<?php

namespace Tests\Feature;

use App\Livewire\Operator\Sales\LeadCardPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Livewire\Operator\WhatsApp\Inbox;
use App\Models\AgencySetting;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\User;
use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Sales\FollowUpSequence;
use App\Services\Sales\PhoneBackupSync;
use App\Services\Sales\SalesDrafts;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\WhatsAppChatExport;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Satış (2026-11-04): prospects on cards, today's messages ready, the phone's shares and backups coming in by themselves. */
class SalesLeadsTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function siteHtml(): string
    {
        return '<html><head><title>Klinik</title></head><body><h2>Hoş geldiniz</h2><p>Randevu için doktortakvimi</p></body></html>';
    }

    public function test_a_quick_add_with_a_site_opens_the_card_with_findings_and_a_first_message_for_today(): void
    {
        Http::fake(['https://ornekklinik.com' => Http::response($this->siteHtml()), '*' => Http::response('', 404)]);

        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Dr. Ayşe Yılmaz', 'clinic' => 'Örnek Klinik', 'city' => 'İzmir', 'phone' => '0532 111 22 33', 'website' => 'ornekklinik.com', 'source' => 'phone', 'note' => 'Fuarda tanıştık'], $this->admin);

        $lead = $lead->fresh();
        $this->assertSame('5321112233', $lead->phone_key);
        $this->assertSame('ornekklinik.com', $lead->website_host);
        $this->assertSame('https://ornekklinik.com', $lead->website);
        $this->assertSame('new', $lead->stage);
        $this->assertTrue($lead->isDue(), 'the first message is due today');
        $this->assertSame('ready', $lead->findings_status);
        $keys = array_column($lead->findings, 'key');
        $this->assertContains('mobile', $keys);
        $this->assertContains('tel', $keys);
        $this->assertContains('platform', $keys);
        $this->assertContains('seo_basics', $keys);
        $this->assertSame('905321112233', $lead->waNumber());
        $this->assertTrue($lead->events()->where('kind', 'note')->exists());

        // A cold first message: numbered problems, no price (yakup 2026-11-03).
        $text = SalesDrafts::forLead($lead);
        $this->assertStringContainsString('Hocam merhaba', $text);
        $this->assertStringContainsString('Google İşletme Profilinizi', $text);
        $this->assertStringContainsString('1. *', $text);
        $this->assertStringNotContainsString('12.000', $text);
        $this->assertStringNotContainsString('en az 6 ay', $text);

        // They answered on WhatsApp: one finding, the price, a yes/no close, at most five sentences.
        $lead->forceFill(['source' => 'whatsapp', 'last_incoming_at' => now()])->save();
        $hot = SalesDrafts::forLead($lead->fresh());
        $this->assertStringContainsString('Aylık 12.000 TL + KDV', $hot);
        $this->assertLessThanOrEqual(5, preg_match_all('/[.?!](\s|$)/u', $hot));

        // Same phone again: no second card.
        app(SalesLeads::class)->quickAdd(['phone' => '+90 532 111 22 33', 'clinic' => 'Örnek Klinik'], $this->admin);
        $this->assertSame(1, SalesLead::query()->count());
    }

    public function test_marking_the_message_sent_schedules_the_follow_ups_two_seven_and_twenty_one_days_later(): void
    {
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Nuri Bey', 'phone' => '0533 222 33 44'], $this->admin);

        $leads->markSent($lead, 'Hocam merhaba, ilk mesaj.', $this->admin);
        $lead = $lead->fresh();
        $this->assertSame('contacted', $lead->stage);
        $this->assertSame(1, $lead->followup_step);
        $this->assertSame('2026-11-06', $lead->next_step_at->timezone('Europe/Istanbul')->toDateString());
        $this->assertSame(1, $lead->events()->where('kind', 'message_out')->count());

        $leads->markSent($lead, 'Aylık 12.000 TL + KDV.', $this->admin);
        $lead = $lead->fresh();
        $this->assertSame('priced', $lead->stage, 'a price in our message moves the card');
        $this->assertSame('2026-11-11', $lead->next_step_at->timezone('Europe/Istanbul')->toDateString());

        $leads->markSent($lead, 'Üçüncü mesaj.', $this->admin);
        $this->assertSame('2026-11-25', $lead->fresh()->next_step_at->timezone('Europe/Istanbul')->toDateString());

        $leads->markSent($lead, 'Dördüncü.', $this->admin);
        $this->assertSame(CarbonImmutable::parse('2026-11-04', 'Europe/Istanbul')->addDays(FollowUpSequence::QUARTER_DAYS)->toDateString(), $lead->fresh()->next_step_at->timezone('Europe/Istanbul')->toDateString(), 'then once a quarter');
    }

    public function test_a_whatsapp_chat_that_is_not_a_customers_opens_a_card_by_itself_and_their_answer_makes_it_due_today(): void
    {
        Http::fake();
        app(WhatsAppConnection::class)->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905388610288', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        $integration = app(WhatsAppConnection::class)->integration();

        $conversation = WhatsAppConversation::query()->create([
            'integration_id' => $integration->id, 'phone_number_id' => 'backup', 'contact_id' => '905009998877', 'contact_name' => 'Şaban Bey',
            'last_message_at' => now()->subDays(3), 'last_incoming_at' => now()->subDays(4),
        ]);
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'b1', 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Merhaba, fiyat?', 'sent_at' => now()->subDays(4)]);
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'b2', 'direction' => 'outgoing', 'message_type' => 'text', 'body' => 'Aylık 12.000 TL + KDV.', 'sent_at' => now()->subDays(3)]);

        $lead = app(SalesLeads::class)->fromConversation($conversation);
        $this->assertNotNull($lead);
        $this->assertSame('Şaban Bey', $lead->name);
        $this->assertSame('whatsapp', $lead->source);
        $this->assertSame('priced', $lead->stage);
        $this->assertSame('5009998877', $lead->phone_key, 'phone key is the last ten digits');
        $this->assertSame('2026-11-04', $lead->next_step_at->timezone('Europe/Istanbul')->toDateString(), 'a follow-up computed from an old message is due now, not in the past');

        // They write again: the observer moves the card to "answer today".
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'b3', 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Başlayalım', 'sent_at' => now()]);
        $conversation->update(['last_message_at' => now(), 'last_incoming_at' => now()]);
        $lead = $lead->fresh();
        $this->assertTrue($lead->isDue());
        $this->assertStringContainsString('Cevap', $lead->next_step);

        // A customer chat never becomes a card; the whole scan counts only new cards.
        WhatsAppConversation::query()->create(['integration_id' => $integration->id, 'phone_number_id' => 'backup', 'contact_id' => '905001112233', 'customer_id' => 1, 'last_message_at' => now()]);
        $this->assertSame(0, app(SalesLeads::class)->scanConversations());
        $this->assertSame(1, SalesLead::query()->count());

        $this->actingAs($this->admin);
        $timeline = app(SalesLeads::class)->timeline($lead);
        $kinds = $timeline->pluck('kind')->all();
        sort($kinds);
        $this->assertSame(['message_in', 'message_in', 'message_out', 'system'], $kinds, 'the chat and the card events are one timeline');
    }

    public function test_sharing_an_exported_chat_from_the_phone_fills_the_card_once(): void
    {
        $this->actingAs($this->admin);
        $export = implode("\n", [
            '04.11.2026 09:12 - Moximu: Hocam merhaba, sitenizde WhatsApp butonu yok.',
            '04.11.2026 09:40 - Dr. Ercan Akpınar: Merhaba, fiyat nedir?',
            '04.11.2026 09:41 - Dr. Ercan Akpınar: Bodrum için.',
        ]);
        $file = UploadedFile::fake()->createWithContent('WhatsApp Chat with Dr. Ercan Akpınar.txt', $export);

        $response = $this->post('/paylas', ['files' => [$file]]);

        $lead = SalesLead::query()->firstOrFail();
        $response->assertRedirect(route('operator.sales.lead', ['lead' => $lead->id]));
        $this->assertSame('Dr. Ercan Akpınar', $lead->name);
        $this->assertSame(3, $lead->events()->whereIn('kind', ['message_in', 'message_out'])->count());
        $this->assertSame(1, $lead->events()->where('kind', 'message_out')->count());
        $this->assertTrue($lead->isDue(), 'they wrote last: answer today');

        $this->post('/paylas', ['files' => [UploadedFile::fake()->createWithContent('WhatsApp Chat with Dr. Ercan Akpınar.txt', $export)]]);
        $this->assertSame(1, SalesLead::query()->count());
        $this->assertSame(3, SalesLeadEvent::query()->whereIn('kind', ['message_in', 'message_out'])->count(), 'the same export never doubles messages');
    }

    public function test_sharing_a_site_link_opens_a_card_and_checks_the_site(): void
    {
        $this->actingAs($this->admin);
        Http::fake(['https://aslialay.com.tr' => Http::response($this->siteHtml()), 'https://kapali.com' => Http::response('forbidden', 403), '*' => Http::response('', 404)]);

        $this->post('/paylas', ['url' => 'https://aslialay.com.tr', 'text' => 'Instagram\'da gördüm'])->assertRedirect();

        $lead = SalesLead::query()->firstOrFail();
        $this->assertSame('link', $lead->source);
        $this->assertSame('aslialay.com.tr', $lead->website_host);
        $this->assertSame('ready', $lead->findings_status);
        $this->assertNotEmpty($lead->findings);

        // A site that blocks robots (403, geo-block) is not a finding to send: the card says it could not be read.
        $this->post('/paylas', ['url' => 'https://kapali.com'])->assertRedirect();
        $blocked = SalesLead::query()->where('website_host', 'kapali.com')->firstOrFail();
        $this->assertSame('failed', $blocked->findings_status);
        $this->assertSame([], $blocked->findings);
        $this->assertStringNotContainsString('403', SalesDrafts::forLead($blocked));
        $this->assertStringContainsString('botlara kapalı (403)', (string) $blocked->events()->where('kind', 'finding')->value('body'));
        $blocked->delete();

        // Plain text with nothing in it: back to Satış with the text as a quick-add note.
        $this->post('/paylas', ['text' => 'bir not'])->assertRedirect(route('operator.sales'))->assertSessionHas('sales_quick', 'bir not');
        $this->assertSame(1, SalesLead::query()->count());
    }

    public function test_the_chat_export_parser_reads_android_and_iphone_lines(): void
    {
        $android = WhatsAppChatExport::parse("4.11.2026 09:12 - Moximu: Merhaba\n4.11.2026 09:13 - Ayşe: Selam\nikinci satır\n", 'WhatsApp Chat with Ayşe.txt');
        $this->assertSame('Ayşe', $android['contact']);
        $this->assertCount(2, $android['messages']);
        $this->assertSame("Selam\nikinci satır", $android['messages'][1]['body']);

        $iphone = WhatsAppChatExport::parse("[04/11/2026, 09:12:05] Moximu: Merhaba\n[04/11/2026, 09:13:00] Ayşe: Selam\n");
        $this->assertCount(2, $iphone['messages']);
        $this->assertSame('2026-11-04 09:13', $iphone['messages'][1]['at']->format('Y-m-d H:i'));
    }

    public function test_the_phone_puts_the_backup_on_the_webdav_address_with_the_rows_password(): void
    {
        $line = WhatsAppBackupLine::query()->create(['name' => 'Moximu telefonu']);
        $password = app(PhoneBackupSync::class)->issue($this->admin, $line);
        $this->assertSame(28, strlen($password));

        // No password: 401 with the Basic challenge FolderSync expects.
        $this->call('PROPFIND', '/api/telefon-yedek/')->assertStatus(401)->assertHeader('WWW-Authenticate');
        $this->call('OPTIONS', '/api/telefon-yedek/')->assertStatus(200)->assertHeader('DAV');

        $server = ['PHP_AUTH_USER' => 'moxdop', 'PHP_AUTH_PW' => $password];
        $this->call('PROPFIND', '/api/telefon-yedek/', [], [], [], $server)->assertStatus(207)->assertSee('Databases', false);
        $this->call('MKCOL', '/api/telefon-yedek/Databases', [], [], [], $server)->assertStatus(201);

        $content = random_bytes(5000);
        $this->call('PUT', '/api/telefon-yedek/Databases/msgstore.db.crypt15', [], [], [], $server, $content)->assertStatus(201);
        $import = WhatsAppBackupImport::query()->firstOrFail();
        $this->assertSame('uploaded', $import->status, 'without a saved key the file waits for Çıkar');
        $this->assertSame($line->id, $import->line_id);
        $this->assertSame(5000, $import->size);
        $this->assertSame(5000, filesize($import->path()));
        $line = $line->fresh();
        $this->assertNotNull($line->phone_sync_received_at);
        $this->assertSame('msgstore.db.crypt15', json_decode((string) $line->phone_sync_last, true)['name']);

        // The same file again: taken, not stored twice.
        $this->call('PUT', '/api/telefon-yedek/Databases/msgstore.db.crypt15', [], [], [], $server, $content)->assertStatus(204);
        $this->assertSame(1, WhatsAppBackupImport::query()->count());
        $this->call('PROPFIND', '/api/telefon-yedek/Databases/', [], [], [], $server)->assertStatus(207)->assertSee('msgstore.db.crypt15', false);
        $this->call('PUT', '/api/telefon-yedek/Databases/foto.jpg', [], [], [], $server, 'x')->assertStatus(415);
        @unlink($import->path());

        // Wrong password after a new one is issued.
        app(PhoneBackupSync::class)->issue($this->admin, $line);
        $this->call('PROPFIND', '/api/telefon-yedek/', [], [], [], $server)->assertStatus(401);
    }

    public function test_the_screens_render_and_the_card_wins_a_customer(): void
    {
        Http::fake();
        $this->actingAs($this->admin);
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Gözde Zeyrek', 'clinic' => 'Zeyrek Diş', 'city' => 'Ankara', 'phone' => '0534 000 11 22'], $this->admin);

        $this->get('/satis')->assertOk()->assertSee('Gözde Zeyrek')->assertSee('wa.me/905340001122', false);
        $this->get('/satis?sekme=adaylar')->assertOk()->assertSee('Zeyrek Diş');
        $this->get('/satis?sekme=sonuclar')->assertOk()->assertSee('Aylık gelir');
        $this->get('/satis/'.$lead->id)->assertOk()->assertSee('Sonraki adım')->assertSee('Hazır metin');
        $this->get('/')->assertOk()->assertSee('Satış');

        Livewire::test(SalesPage::class)->call('markSent', $lead->id)->assertHasNoErrors();
        $this->assertSame('contacted', $lead->fresh()->stage);
        Livewire::test(SalesPage::class)->call('snooze', $lead->id)->assertHasNoErrors();
        $this->assertSame('2026-11-05', $lead->fresh()->next_step_at->timezone('Europe/Istanbul')->toDateString());

        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])
            ->set('note', 'Aradım, pazartesi dönecek')->call('addNote')->assertHasNoErrors()
            ->set('nextStep', 'Pazartesi ara')->set('nextStepAt', '2026-11-09')->call('saveNextStep')->assertHasNoErrors()
            ->call('win')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertTrue($lead->events()->where('kind', 'call')->exists());
        $this->assertSame('won', $lead->stage);
        $this->assertNotNull($lead->customer_id);
        $this->assertNotNull($lead->brand_id);
        $this->assertSame('Zeyrek Diş', $lead->brand->name);
        $this->assertSame(0, $leads->due()->count());
    }

    public function test_the_phone_connection_card_shows_the_password_once(): void
    {
        $this->actingAs($this->admin);
        $line = WhatsAppBackupLine::query()->create(['name' => 'Moximu telefonu']);

        $component = Livewire::test(Inbox::class, ['tab' => 'setup'])->call('issuePhoneSync', $line->id)->assertHasNoErrors()
            ->assertSee('/api/telefon-yedek/')->assertSee('moxdop');
        $this->assertTrue(app(PhoneBackupSync::class)->enabled($line->fresh()));
        $component->call('revokePhoneSync', $line->id)->assertHasNoErrors();
        $this->assertFalse(app(PhoneBackupSync::class)->enabled($line->fresh()));
    }
}
