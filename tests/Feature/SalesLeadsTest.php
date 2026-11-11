<?php

namespace Tests\Feature;

use App\Ai\Agents\SalesReplyAgent;
use App\Livewire\Operator\Inbox\InboxPage;
use App\Livewire\Operator\Sales\LeadCardPage;
use App\Livewire\Operator\Sales\SalesPage;
use App\Livewire\Operator\Sales\TodayCard;
use App\Livewire\Operator\WhatsApp\Inbox;
use App\Models\AgencySetting;
use App\Models\AiTask;
use App\Models\CoreIntegration;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\User;
use App\Models\WhatsAppBackupImport;
use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\AiTasks\AiWorkers;
use App\Services\Sales\FollowUpSequence;
use App\Services\Sales\PhoneBackupSync;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesReplyWriter;
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
        // The subscription Claude is connected (its server key), as on the live panel.
        config(['moxdop-mcp.token' => 'test-subscription-key']);
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

        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Dr. Ayşe Yılmaz', 'company' => 'Örnek Klinik', 'city' => 'İzmir', 'phone' => '0532 111 22 33', 'website' => 'ornekklinik.com', 'source' => 'phone', 'note' => 'Fuarda tanıştık'], $this->admin);

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

        // No Claude API key in this test: no template text either; the subscription Claude writes it in its round.
        $this->assertNull($lead->draft);
        $this->assertSame('waiting', $lead->draft_status);
        $this->assertStringContainsString('abonelikteki Claude', (string) $lead->draft_note);
        $this->assertNotNull($lead->site_scan);

        // Same phone again: no second card.
        app(SalesLeads::class)->quickAdd(['phone' => '+90 532 111 22 33', 'company' => 'Örnek Klinik'], $this->admin);
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
        $this->assertSame('contacted', $lead->stage, 'our own price does not move the card: the offer stage is the prospect\'s move');
        $this->assertSame(12000, $lead->offer_amount, 'the amount is still remembered');
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
        $this->assertSame(now()->subDays(4)->toDateString(), $lead->fresh()->created_at->toDateString(), 'the card counts from the chat\'s first message (Pazarlama counts the month by it)');
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

    public function test_the_offer_stage_needs_the_prospects_move_and_an_old_wrong_read_goes_back(): void
    {
        Http::fake();
        app(WhatsAppConnection::class)->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905388610288', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        $integration = app(WhatsAppConnection::class)->integration();
        $conversation = WhatsAppConversation::query()->create([
            'integration_id' => $integration->id, 'phone_number_id' => 'backup', 'contact_id' => '905007776655', 'contact_name' => 'Sessiz Aday',
            'last_message_at' => now()->subDays(3), 'last_incoming_at' => now()->subDays(4),
        ]);
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'c1', 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Merhaba', 'sent_at' => now()->subDays(4)]);
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'c2', 'direction' => 'outgoing', 'message_type' => 'text', 'body' => 'Merhaba, paketimiz aylık 14.000 TL + KDV.', 'sent_at' => now()->subDays(3)]);

        $leads = app(SalesLeads::class);
        $lead = $leads->fromConversation($conversation);
        $this->assertSame('talking', $lead->stage, 'we named a price nobody asked for and nobody answered');
        $this->assertSame(14000, $lead->offer_amount);
        $this->assertSame(0, SalesLead::query()->realOffers()->count(), 'not an offer on the table');

        // A card the old rule had read as "teklif verildi" goes back when the chat is read again; one set by hand stays.
        $lead->forceFill(['stage' => 'priced'])->save();
        $leads->fromConversation($conversation);
        $this->assertSame('talking', $lead->fresh()->stage);
        $leads->setStage($lead->fresh(), 'priced', null, $this->admin);
        $leads->fromConversation($conversation);
        $this->assertSame('priced', $lead->fresh()->stage, 'the operator chose it');
        $leads->setStage($lead->fresh(), 'talking', null, $this->admin);

        // They answer after seeing the price: now it is an offer.
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'c3', 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Anladım, eşimle konuşayım', 'sent_at' => now()]);
        $conversation->update(['last_message_at' => now(), 'last_incoming_at' => now()]);
        $this->assertContains($lead->fresh()->stage, ['priced', 'thinking']);
        $this->assertSame(1, SalesLead::query()->realOffers()->count());
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
        $this->assertStringContainsString('botlara kapalı (403)', (string) $blocked->events()->where('kind', 'finding')->value('body'));
        $blocked->delete();

        // Plain text with nothing in it: back to Satış with the text as a quick-add note.
        $this->post('/paylas', ['text' => 'bir not'])->assertRedirect(route('operator.sales'))->assertSessionHas('sales_quick', 'bir not');
        $this->assertSame(1, SalesLead::query()->count());
    }

    public function test_claude_writes_the_first_message_from_the_site_scan_right_after_the_site_is_read(): void
    {
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        SalesReplyAgent::fake([[
            'action' => 'reply', 'reply' => "Ayşe Hocam merhaba, web sitenizi (ornekklinik.com) inceledik.\n\n1. *Sitenizde tek dokunuşla arama yok*\n\nBaşlamak ister misiniz?",
            'send_on' => '2026-11-04', 'note' => 'İlk mesaj; fiyat yazılmadı.', 'summary' => 'Soğuk aday, site okundu.',
        ]]);
        Http::fake([
            'https://ornekklinik.com' => Http::response($this->siteHtml()),
            'https://ornekklinik.com/sitemap.xml' => Http::response('<urlset><url><loc>https://ornekklinik.com/</loc></url><url><loc>https://ornekklinik.com/implant</loc></url></urlset>'),
            'https://ornekklinik.com/' => Http::response($this->siteHtml()),
            'https://ornekklinik.com/implant' => Http::response('<html lang="tr"><head><title>İmplant Tedavisi</title></head><body><h1>İmplant</h1><a href="tel:+902320000000">Ara</a></body></html>'),
            '*' => Http::response('', 404),
        ]);

        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Dr. Ayşe Yılmaz', 'city' => 'İzmir', 'phone' => '0532 111 22 33', 'website' => 'ornekklinik.com', 'source' => 'phone'], $this->admin)->fresh();

        $this->assertSame('ready', $lead->draft_status);
        $this->assertStringStartsWith('Ayşe Hocam merhaba', (string) $lead->draft);
        $this->assertSame($lead->draft, $lead->draft_ai);
        $this->assertSame('İlk mesaj; fiyat yazılmadı.', $lead->draft_note);
        $this->assertSame(2, $lead->site_scan['pages_read']);
        $this->assertSame(1, $lead->site_scan['pages_with_tap_to_call']);
        $this->assertSame(2, $lead->site_scan['titles_without_city']);
        SalesReplyAgent::assertPrompted(fn ($prompt): bool => str_contains((string) $prompt->prompt, '"pages_read":2') && str_contains((string) $prompt->prompt, 'Dr. Ayşe Yılmaz'));

        // Sent after an edit: the edited text is kept as yakup's own example for the next ones.
        $this->actingAs($this->admin);
        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->set('draft', 'Ayşe Hocam merhaba, kendi yazdığım metin.')->call('markSent')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertNull($lead->draft);
        $this->assertNull($lead->draft_status);
        $this->assertTrue((bool) $lead->events()->where('kind', 'message_out')->latest('id')->first()->meta['edited']);
    }

    public function test_a_chat_pasted_on_the_card_is_read_and_claude_answers_at_once_or_says_to_wait(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        SalesReplyAgent::fake([[
            'action' => 'reply', 'reply' => "Yaşar Hocam, fiyatlarımıza KDV dahil değildir.\n\nBaşlayalım mı?",
            'send_on' => '2026-11-04', 'note' => 'KDV sorusuna cevap.', 'summary' => 'Fiyat konuşuldu, KDV sordu.',
        ], [
            'action' => 'wait', 'reply' => 'Yaşar Hocam merhaba, ilk ay planını hazırladık. Başlayalım mı?',
            'send_on' => '2026-11-06', 'note' => 'Son mesaj bizden; 2 gün bekle.', 'summary' => 'Cevap bekleniyor.',
        ]]);
        $this->actingAs($this->admin);
        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Yaşar Özdemir', 'city' => 'Antalya', 'phone' => '0533 500 03 25'], $this->admin);

        $chat = "[22:30, 30.10.2026] Moximu Dijital Pazarlama ve Web Yazılım: Aylık 14.000 TL + KDV.\n[22:35, 30.10.2026] Yaşar Özdemir: Fiyata KDV dahil mi?";
        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])
            ->set('paste', $chat)->set('pasteNote', 'Telefonda iyi geçti')->call('pasteChat')
            ->assertHasNoErrors()->assertSet('draft', "Yaşar Hocam, fiyatlarımıza KDV dahil değildir.\n\nBaşlayalım mı?");
        $lead = $lead->fresh();
        $this->assertSame(2, $lead->events()->whereIn('kind', ['message_in', 'message_out'])->count());
        $this->assertContains('Telefonda iyi geçti', $lead->events()->where('kind', 'note')->pluck('body')->all());
        // What Claude reads: the pasted chat and the operator's word.
        $payload = json_encode(app(SalesReplyWriter::class)->payload($lead), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Fiyata KDV dahil mi?', $payload);
        $this->assertStringContainsString('Telefonda iyi geçti', $payload);
        $this->assertStringContainsString('14.000', $payload);

        // "Yeniden yaz" when the last word is ours: the text waits for its day.
        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->call('writeNow')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertSame('2026-11-06', $lead->draft_send_on->toDateString());
        $this->assertSame('2026-11-06', $lead->next_step_at->timezone('Europe/Istanbul')->toDateString());
    }

    public function test_when_the_claude_api_is_held_the_subscription_claude_writes_and_the_api_takes_over_again(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        SalesReplyAgent::fake([[
            'action' => 'reply', 'reply' => 'Yaşar Hocam merhaba, API yazdı.', 'send_on' => '2026-11-04', 'note' => 'API.', 'summary' => 'API.',
        ]]);
        $workers = app(AiWorkers::class);
        $workers->saveApi(AiWorkers::CLAUDE_API, true, 'claude-sonnet-5-5', 320, 8400);
        $workers->pause(AiWorkers::CLAUDE_API); // held, like its daily TL cap
        $this->actingAs($this->admin);
        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Yaşar Özdemir', 'city' => 'Antalya', 'phone' => '0533 500 03 25'], $this->admin);

        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->call('writeNow')->assertHasNoErrors();
        $lead = $lead->fresh();
        $this->assertSame('waiting', $lead->draft_status);
        $this->assertStringContainsString('abonelikteki Claude', (string) $lead->draft_note);
        $task = AiTask::query()->where('operation', 'sales.reply')->where('status', AiTask::PENDING)->sole();
        $this->assertStringContainsString('Yaşar Özdemir', (string) $task->input);
        // Asking again while it waits keeps one question.
        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->call('writeNow');
        $this->assertSame(1, AiTask::query()->where('operation', 'sales.reply')->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED])->count());

        // The subscription round answers: the card gets the text.
        $task = AiTask::query()->where('operation', 'sales.reply')->where('status', AiTask::PENDING)->sole();
        $this->assertSame([], app(AiTaskQueue::class)->submit($task, [
            'action' => 'reply', 'reply' => 'Yaşar Hocam merhaba, abonelik yazdı.', 'send_on' => '2026-11-04', 'note' => 'Abonelik.', 'summary' => 'Abonelik.',
        ], AiWorkers::CLAUDE_MCP));
        $lead = $lead->fresh();
        $this->assertSame('ready', $lead->draft_status);
        $this->assertSame('Yaşar Hocam merhaba, abonelik yazdı.', $lead->draft);
        $this->assertSame(0, AiTask::query()->where('operation', 'sales.reply')->whereIn('status', [AiTask::PENDING, AiTask::CLAIMED, AiTask::DONE])->count());
        SalesReplyAgent::assertNeverPrompted();

        // The API is back (a new day): it writes at once again.
        $workers->resume(AiWorkers::CLAUDE_API);
        Livewire::test(LeadCardPage::class, ['lead' => (string) $lead->id])->call('writeNow')->assertHasNoErrors();
        $this->assertSame('Yaşar Hocam merhaba, API yazdı.', $lead->fresh()->draft);
    }

    public function test_a_chat_pasted_on_the_sales_page_finds_the_card_by_the_contacts_number(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $this->actingAs($this->admin);
        $lead = app(SalesLeads::class)->quickAdd(['name' => 'Dr. Yaşar', 'phone' => '0533 500 03 25'], $this->admin);

        Livewire::test(SalesPage::class)
            ->set('paste', "[10:00, 01.11.2026] +90 533 500 03 25: Merhaba, sitemiz yasarozdemir.com\n[10:05, 01.11.2026] Moximu: Merhaba Hocam")
            ->call('pasteChat')->assertHasNoErrors()->assertRedirect(route('operator.sales.lead', ['lead' => $lead->id]));

        $lead = $lead->fresh();
        $this->assertSame(1, SalesLead::query()->count());
        $this->assertSame('yasarozdemir.com', $lead->website_host);
        $this->assertSame(2, $lead->events()->whereIn('kind', ['message_in', 'message_out'])->count());
        $this->assertSame('waiting', $lead->draft_status, 'no Claude API key here: the subscription Claude writes it, no template');
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
        // A backup larger than post_max_size is not refused by the framework's POST size check (it was 413 live).
        $this->call('PUT', '/api/telefon-yedek/msgstore.db.crypt15', [], [], [], ['CONTENT_LENGTH' => (string) (300 * 1024 * 1024)], 'x')->assertStatus(401);
        $this->call('POST', '/api/telefon-yedek/x', [], [], [], ['CONTENT_LENGTH' => (string) (300 * 1024 * 1024)])->assertStatus(413);

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

        // FolderSync's temporary-name upload: PUT under a temp name, MOVE to the real one, set the time, check it.
        $this->call('PUT', '/api/telefon-yedek/Databases/msgstore.db.crypt15.tmp', [], [], [], $server, random_bytes(6000))->assertSuccessful();
        $this->call('MOVE', '/api/telefon-yedek/Databases/msgstore.db.crypt15.tmp', [], [], [], $server + ['HTTP_DESTINATION' => 'https://app.moximu.com/api/telefon-yedek/Databases/msgstore.db.crypt15'])->assertStatus(201);
        $this->call('PROPPATCH', '/api/telefon-yedek/Databases/msgstore.db.crypt15', [], [], [], $server)->assertStatus(207);
        $this->call('PROPFIND', '/api/telefon-yedek/Databases/msgstore.db.crypt15', [], [], [], $server + ['HTTP_DEPTH' => '0'])->assertStatus(207)->assertSee('6000', false);
        $log = PhoneBackupSync::recent();
        $this->assertSame('PROPFIND', $log[0]['method']);
        $this->assertSame('MOVE', $log[2]['method']);
        $this->assertSame('msgstore.db.crypt15', $log[2]['destination']);

        // Wrong password after a new one is issued.
        app(PhoneBackupSync::class)->issue($this->admin, $line);
        $this->call('PROPFIND', '/api/telefon-yedek/', [], [], [], $server)->assertStatus(401);
    }

    public function test_the_screens_render_and_the_card_wins_a_customer(): void
    {
        Http::fake();
        $this->actingAs($this->admin);
        $leads = app(SalesLeads::class);
        $lead = $leads->quickAdd(['name' => 'Gözde Zeyrek', 'company' => 'Zeyrek Diş', 'city' => 'Ankara', 'phone' => '0534 000 11 22'], $this->admin);

        $this->get('/satis')->assertOk()->assertSee('Gözde Zeyrek')->assertSee('Gelen kutusu');
        $this->get('/satis?sekme=adaylar')->assertOk()->assertSee('Zeyrek Diş');
        $this->get('/satis?sekme=sonuclar')->assertOk()->assertSee('Aylık gelir');
        // Bugün moved to the Gelen kutusu (Aşama 2); the old address points there and the first text is written from there.
        $this->get('/satis?sekme=bugun')->assertRedirect('/gelen-kutusu?gorunum=adimlar');
        $this->get('/gelen-kutusu?gorunum=adimlar')->assertOk()->assertSee('Gözde Zeyrek')->assertSee('Bugünün adımları');
        $this->get('/gelen-kutusu?gorunum=adimlar&kayit=adim-'.$lead->id)->assertOk()->assertSee('Claude yazsın')->assertSee('Zeyrek Diş');
        $this->get('/satis/'.$lead->id)->assertOk()->assertSee('Sonraki adım')->assertSee('Gönderilecek mesaj')->assertSee('Sohbeti yapıştır');
        // Komuta's Moximu line (Aşama 3) is the inbox in one sentence; the card loads lazily on the page.
        $this->get('/')->assertOk()->assertSee('data-sales-today', false);
        Livewire::test(TodayCard::class)->assertSee('1 adım')->assertSee('Gözde Zeyrek')->assertSee('Gelen kutusu');

        Livewire::test(InboxPage::class, ['view' => 'adimlar'])->call('select', 'adim-'.$lead->id)->call('done')->assertHasNoErrors();
        $this->assertSame('contacted', $lead->fresh()->stage);
        $lead->fresh()->forceFill(['next_step_at' => now()])->save();
        Livewire::test(InboxPage::class, ['view' => 'adimlar'])->call('select', 'adim-'.$lead->id)->call('later')->assertHasNoErrors();
        $this->assertSame('2026-11-05', $lead->fresh()->next_step_at->timezone('Europe/Istanbul')->toDateString());
        $this->assertSame('later', $lead->fresh()->stage);

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
