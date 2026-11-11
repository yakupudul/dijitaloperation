<?php

namespace Tests\Feature;

use App\Livewire\Operator\Inbox\InboxPage;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SocialAccount;
use App\Models\SocialThread;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\Inbox\InboxItem;
use App\Services\Inbox\InboxQueue;
use App\Services\Sales\Agreements;
use App\Services\Sales\SalesLeads;
use App\Services\Social\SocialInbox;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppIngestion;
use App\Support\OperatorMenu;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gelen kutusu (Aşama 2, 2026-11-07): WhatsApp chats, Instagram / Facebook threads and today's sales steps in one
 * queue with four views; every line leaves with Yapıldı, Sonra or İlgisiz, and comes back when they write again.
 */
final class InboxQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup Udul', 'locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        CarbonImmutable::setTestNow('2026-11-09 09:00:00');
        config(['moxdop-mcp.token' => 'test-subscription-key']);
        Http::fake();
        Queue::fake();
        app(WhatsAppConnection::class)->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905388610288', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function chat(string $phone, string $name, ?int $customerId = null, int $hoursAgo = 2): WhatsAppConversation
    {
        $integration = app(WhatsAppConnection::class)->integration();
        $conversation = WhatsAppConversation::query()->create([
            'integration_id' => $integration->id, 'phone_number_id' => 'backup', 'contact_id' => $phone, 'contact_name' => $name, 'customer_id' => $customerId,
            'last_message_at' => now()->subHours($hoursAgo), 'last_incoming_at' => now()->subHours($hoursAgo),
        ]);
        WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => 'm-'.$phone, 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Merhaba, sitemiz için fiyat alabilir miyim?', 'sent_at' => now()->subHours($hoursAgo)]);

        return $conversation;
    }

    public function test_sohbetler_lists_every_chat_like_whatsapp_and_send_goes_out_through_the_live_number(): void
    {
        $live = $this->chat('905001112233', 'Dr. Ayşe');
        $live->update(['phone_number_id' => '222222222']);
        $old = $this->chat('905007778899', 'Eski Müşteri', null, 72);
        $old->update(['phone_number_id' => '222222222']);
        WhatsAppMessage::query()->create(['conversation_id' => $old->id, 'message_id' => 'o-1', 'direction' => 'outgoing', 'message_type' => 'text', 'body' => 'Teşekkürler', 'sent_at' => now()->subHours(71)]);
        $old->update(['last_message_at' => now()->subHours(71)]);

        $page = Livewire::test(InboxPage::class)->assertSet('view', 'sohbetler')->assertSee('Dr. Ayşe')->assertSee('Eski Müşteri')->assertSee('Sen: Teşekkürler');
        $page->set('search', 'ayşe')->assertSee('Dr. Ayşe')->assertDontSee('Eski Müşteri');
        $page->set('search', '7778')->assertSee('Eski Müşteri')->assertDontSee('Dr. Ayşe');

        // Window closed (72 h): no Gönder, the phone link instead; open window: sent through the Cloud API and stored.
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$old->id)->assertDontSee('data-inbox-send', false)->assertSee('WhatsApp\'ta aç', false);
        Http::swap(new Factory);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SENT1']]])]);
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$live->id)->set('reply', 'Ayşe Hocam merhaba, fiyatımız 14.000 TL + KDV.')
            ->call('send')->assertHasNoErrors()->assertSet('item', 'wa-'.$live->id)->assertSee('Gönderildi.');
        Http::assertSent(fn ($request) => str_contains($request->url(), '222222222/messages') && $request['to'] === '905001112233'
            && json_decode($request['text'], true)['body'] === 'Ayşe Hocam merhaba, fiyatımız 14.000 TL + KDV.');
        $sent = WhatsAppMessage::query()->where('message_id', 'wamid.SENT1')->sole();
        $this->assertSame('outgoing', $sent->direction);
        $this->assertNotNull($live->fresh()->done_at);

        // A chat that asked to stop is never sent to.
        $live->update(['opted_out_at' => now(), 'last_incoming_at' => now()]);
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$live->id)->set('reply', 'Merhaba')->call('send')->assertHasErrors('reply');
    }

    public function test_the_queue_gathers_chats_threads_and_steps_into_four_views(): void
    {
        $leads = app(SalesLeads::class);
        $prospectChat = $this->chat('905001112233', 'Dr. Ayşe');
        $customer = Customer::factory()->create(['name' => 'Avrupadent', 'status' => 'active', 'monthly_fee' => 60000]);
        $brand = Brand::query()->create(['customer_id' => $customer->id, 'name' => 'Avrupadent']);
        app(Agreements::class)->start($brand, ['kind' => 'monthly', 'fee' => 60000, 'due_day' => 5, 'starts_on' => '2026-10-01'], $this->admin);
        $customerChat = $this->chat('905004445566', 'Avrupadent Ofis', $customer->id, 1);
        $step = $leads->quickAdd(['name' => 'Gözde Zeyrek', 'company' => 'Zeyrek Diş', 'phone' => '0534 000 11 22'], $this->admin);
        $step->forceFill(['next_step_at' => now()->subDay(), 'next_step' => 'İlk mesajı gönder'])->save();
        $later = $leads->quickAdd(['name' => 'Sonraki Hafta', 'phone' => '0534 000 11 33'], $this->admin);
        $later->forceFill(['next_step_at' => now()->addDays(5)])->save();
        $account = SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 't', 'page_id' => 'PAGE1']);
        $thread = SocialThread::query()->create(['social_account_id' => $account->id, 'kind' => 'dm', 'external_id' => 'CONV1', 'author_id' => 'U9', 'author_name' => 'drayse',
            'last_text' => 'Fiyatlarınız nedir?', 'last_direction' => 'in', 'last_in_at' => now()->subMinutes(30), 'last_at' => now()->subMinutes(30), 'status' => 'open']);
        $answered = SocialThread::query()->create(['social_account_id' => $account->id, 'kind' => 'comment', 'external_id' => 'C1', 'author_name' => 'klinikx',
            'last_text' => 'Biz teşekkür ederiz', 'last_direction' => 'out', 'last_at' => now()->subHours(3), 'status' => 'answered']);

        $queue = app(InboxQueue::class);
        $items = $queue->items();
        $keys = $items->pluck('key')->all();
        $this->assertContains('wa-'.$prospectChat->id, $keys);
        $this->assertContains('wa-'.$customerChat->id, $keys);
        $this->assertContains('sosyal-'.$thread->id, $keys);
        $this->assertContains('adim-'.$step->id, $keys);
        $this->assertNotContains('adim-'.$later->id, $keys, 'a step five days away is not today\'s work');
        $this->assertNotContains('sosyal-'.$answered->id, $keys);

        $prospect = $items->firstWhere('key', 'wa-'.$prospectChat->id);
        $this->assertNotNull($prospect->lead, 'the chat opened its own record');
        $this->assertTrue($prospect->owed);
        $this->assertFalse($prospect->fromCustomer);
        $fromCustomer = $items->firstWhere('key', 'wa-'.$customerChat->id);
        $this->assertTrue($fromCustomer->fromCustomer);
        $this->assertSame('Avrupadent', $fromCustomer->title);

        // Müşteri dönemi (Aşama 3): the customer's October payment (past the 15th) and October report are two lines of work.
        $this->assertContains(InboxItem::riskKey($brand->id, 'odeme_gecikti', '2026-10'), $keys);
        $this->assertContains(InboxItem::riskKey($brand->id, 'rapor_yok', '2026-10'), $keys);
        $this->assertSame(['borclu' => 3, 'adimlar' => 4, 'musteriler' => 3, 'hepsi' => 6], $queue->counts($items));
        $this->assertSame('adim-'.$step->id, $queue->view('adimlar', $items)->first()->key, 'the overdue step comes first among the steps');
        $this->assertSame(6, $queue->openCount());
        $this->assertSame(6, OperatorMenu::computeBadges()['operator.inbox']['count'] ?? null);

        $this->get('/gelen-kutusu?gorunum=hepsi')->assertOk()->assertSee('Cevap borçlu')->assertSee('Dr. Ayşe')->assertSee('drayse')->assertSee('Instagram mesajı');
        $this->get('/gelen-kutusu?gorunum=musteriler')->assertOk()->assertSee('Avrupadent')->assertSee('Ekim raporu gönderilmedi')->assertDontSee('Dr. Ayşe');
        $this->get('/gelen-kutusu?gorunum=adimlar&kayit=adim-'.$step->id)->assertOk()->assertSee('gecikti')->assertSee('Zeyrek Diş');
        $this->get('/gelen-kutusu?kayit=wa-'.$customerChat->id)->assertOk()->assertSee('Müşteri')->assertSee('Anlaşma')->assertSee('60.000 ₺');
        $this->get('/')->assertOk()->assertSee('Gelen kutusu');
    }

    public function test_a_message_whatsapp_did_not_share_owes_no_answer(): void
    {
        $unreadable = $this->chat('905550000091', 'Okunamayan');
        WhatsAppMessage::query()->where('conversation_id', $unreadable->id)->update(['message_type' => 'errors']);
        $answered = $this->chat('905550000092', 'Cevaplanan', null, 5);
        WhatsAppMessage::query()->create(['conversation_id' => $answered->id, 'message_id' => 'out-92', 'direction' => 'outgoing', 'message_type' => 'text', 'body' => 'Merhaba', 'sent_at' => now()->subHours(4)]);
        WhatsAppMessage::query()->create(['conversation_id' => $answered->id, 'message_id' => 'err-92', 'direction' => 'incoming', 'message_type' => 'unsupported', 'body' => '[WhatsApp bu mesajın içeriğini paylaşmadı · telefonda görün]', 'sent_at' => now()->subHours(1)]);
        $answered->update(['last_message_at' => now()->subHours(1), 'last_incoming_at' => now()->subHours(1)]);
        $readable = $this->chat('905550000093', 'Okunan');

        $owed = app(InboxQueue::class)->view('borclu')->map(fn (InboxItem $item): string => $item->title)->all();

        $this->assertSame(['Okunan'], $owed);
        $this->assertNotNull($readable->id);
    }

    public function test_done_later_and_irrelevant_take_a_line_out_and_a_new_message_brings_it_back(): void
    {
        $chat = $this->chat('905001112233', 'Dr. Ayşe');
        $lead = SalesLead::query()->where('conversation_id', $chat->id)->sole();
        $lead->forceFill(['draft' => 'Ayşe Hocam merhaba, siteyi inceledim.', 'draft_status' => 'ready'])->save();
        $second = $this->chat('905009998877', 'Tedarikçi', null, 5);
        $queue = app(InboxQueue::class);

        // Yapıldı: the chat is done, the record's follow-up is scheduled with the text in hand, the next line is selected.
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$chat->id)->assertSet('reply', 'Ayşe Hocam merhaba, siteyi inceledim.')
            ->call('done')->assertHasNoErrors()->assertSet('item', 'wa-'.$second->id);
        $this->assertNotNull($chat->fresh()->done_at);
        $lead = $lead->fresh();
        $this->assertSame('contacted', $lead->stage);
        $this->assertSame(1, $lead->followup_step);
        $this->assertTrue($lead->events()->where('kind', 'message_out')->exists());
        $this->assertNotContains('wa-'.$chat->id, $queue->items()->pluck('key')->all());

        // İlgisiz: a supplier leaves the queue and the counts; they write again → prospect again, back in the queue.
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$second->id)->call('irrelevant')->assertHasNoErrors();
        $supplier = SalesLead::query()->where('conversation_id', $second->id)->sole();
        $this->assertSame('irrelevant', $supplier->lifecycle);
        $this->assertSame(0, $queue->items()->count());
        CarbonImmutable::setTestNow('2026-11-09 11:00:00');
        WhatsAppMessage::query()->create(['conversation_id' => $second->id, 'message_id' => 'm2', 'direction' => 'incoming', 'message_type' => 'text', 'body' => 'Aslında müşteri olmak istiyoruz', 'sent_at' => now()]);
        $second->update(['last_message_at' => now(), 'last_incoming_at' => now(), 'done_at' => null]);
        $this->assertSame('prospect', $supplier->fresh()->lifecycle);
        $this->assertContains('wa-'.$second->id, $queue->items()->pluck('key')->all());

        // Sonra: three days; the chat waits and the record's step moves there. A customer's request becomes a task too.
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$second->id)->set('laterDate', '2026-11-12')->call('later')->assertHasNoErrors();
        $this->assertSame('2026-11-12', $second->fresh()->snoozed_until->timezone('Europe/Istanbul')->toDateString());
        $this->assertSame('later', $supplier->fresh()->stage);
        $this->assertSame(0, $queue->items()->count());
        CarbonImmutable::setTestNow('2026-11-12 10:30:00');
        $this->assertContains('wa-'.$second->id, $queue->items()->pluck('key')->all(), 'the line is back on the day');
        CarbonImmutable::setTestNow('2026-11-09 11:00:00');

        $customer = Customer::factory()->create(['name' => 'Avrupadent', 'status' => 'active', 'monthly_fee' => 60000]);
        $customerChat = $this->chat('905004445566', 'Avrupadent Ofis', $customer->id, 1);
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$customerChat->id)->call('irrelevant')->assertHasErrors(['item']);
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$customerChat->id)->call('later')->assertHasNoErrors();
        $this->assertSame('2026-11-10', $customerChat->fresh()->snoozed_until->timezone('Europe/Istanbul')->toDateString());
        $this->assertSame(1, Reminder::query()->where('customer_id', $customer->id)->where('kind', 'task')->count());
        Livewire::test(InboxPage::class)->call('select', 'wa-'.$customerChat->id)->call('openRecord')->assertHasNoErrors();
        $this->assertSame('customer', SalesLead::query()->where('customer_id', $customer->id)->sole()->lifecycle);
    }

    public function test_a_chat_from_a_meta_ad_keeps_the_ad_and_the_record_reads_its_source(): void
    {
        $integration = app(WhatsAppConnection::class)->integration();
        $receipt = WhatsAppWebhookReceipt::query()->create([
            'integration_id' => $integration->id, 'payload_hash' => hash('sha256', 'ad-1'), 'status' => 'pending',
            'payload' => ['entry' => [['id' => '111111111', 'changes' => [['field' => 'messages', 'value' => [
                'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '222222222'],
                'contacts' => [['wa_id' => '905007778899', 'profile' => ['name' => 'Dt. Eslem']]],
                'messages' => [[
                    'id' => 'wamid.ad1', 'from' => '905007778899', 'timestamp' => (string) now()->timestamp, 'type' => 'text',
                    'text' => ['body' => 'Reklamınızı gördüm, bilgi alabilir miyim?'],
                    'referral' => ['source_url' => 'https://fb.me/abc', 'source_id' => '1200001', 'source_type' => 'ad', 'headline' => 'Kliniğinize ayda 25 yeni hasta', 'body' => 'SEO + İşletme Profili', 'media_type' => 'image', 'ctwa_clid' => 'clid-1'],
                ]],
            ]]]]]],
        ]);
        app(WhatsAppIngestion::class)->process($receipt->id);

        $conversation = WhatsAppConversation::query()->where('contact_id', '905007778899')->sole();
        $this->assertSame('1200001', $conversation->referral['source_id']);
        $this->assertSame('Kliniğinize ayda 25 yeni hasta', $conversation->referral['headline']);
        $lead = SalesLead::query()->where('conversation_id', $conversation->id)->sole();
        $this->assertSame('meta_ads', $lead->heard_from);
        $this->assertSame('whatsapp', $lead->source);
        $this->assertSame(['meta', 'ctwa', 'Kliniğinize ayda 25 yeni hasta', '1200001'], [$lead->origin['utm_source'], $lead->origin['utm_medium'], $lead->origin['utm_campaign'], $lead->origin['ad_id']]);
        $this->assertTrue($lead->events()->where('kind', 'system')->get()->contains(fn ($e): bool => str_contains((string) $e->body, 'Reklamdan yazdı')));
    }

    public function test_the_channel_rail_splits_the_queue_by_where_it_talks_and_where_it_came_from(): void
    {
        $leads = app(SalesLeads::class);
        $chat = $this->chat('905001112233', 'Dr. Ayşe');
        $form = $leads->quickAdd(['name' => 'Form Hanım', 'phone' => '0534 000 11 22', 'source' => 'form'], $this->admin);
        $manual = $leads->quickAdd(['name' => 'Elle Bey', 'phone' => '0534 000 11 33', 'source' => 'phone'], $this->admin);
        $afc = $leads->quickAdd(['name' => 'Afc Hocam', 'phone' => '0534 000 11 44', 'source' => 'afc'], $this->admin);
        foreach ([$form, $manual, $afc] as $lead) {
            $lead->forceFill(['next_step_at' => now()->subHour()])->save();
        }
        $chatLead = SalesLead::query()->where('conversation_id', $chat->id)->firstOrFail();
        $chatLead->forceFill(['heard_from' => 'referral'])->save();
        $account = SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 't', 'page_id' => 'PAGE1']);
        $thread = SocialThread::query()->create(['social_account_id' => $account->id, 'kind' => 'dm', 'external_id' => 'CONV1', 'author_id' => 'U9', 'author_name' => 'drayse',
            'last_text' => 'Fiyatlarınız nedir?', 'last_direction' => 'in', 'last_in_at' => now()->subMinutes(30), 'last_at' => now()->subMinutes(30), 'status' => 'open']);

        $meta = $leads->quickAdd(['name' => 'Meta Hanım', 'phone' => '0534 000 11 55', 'source' => 'meta_form'], $this->admin);
        $meta->forceFill(['next_step_at' => now()->addWeek()])->save(); // not waiting today: still listed under Meta form

        $queue = app(InboxQueue::class);
        $items = $queue->items()->keyBy('key');
        $this->assertSame(['whatsapp'], $items['wa-'.$chat->id]->channels(), 'a referral is the record\'s Kimden, not a channel');
        $this->assertSame(['instagram'], $items['sosyal-'.$thread->id]->channels());
        $this->assertSame(['form'], $items['adim-'.$form->id]->channels());
        $this->assertSame(['cagri'], $items['adim-'.$manual->id]->channels());
        $this->assertSame([], $items['adim-'.$afc->id]->channels());

        $counts = $queue->channelCounts($queue->items());
        $this->assertSame(1, $counts['whatsapp']['open']);
        $this->assertSame(1, $counts['cagri']['open']);
        $this->assertSame(1, $counts['form']['month']);
        $this->assertSame(1, $counts['meta_form']['month']);
        $this->assertArrayNotHasKey('referans', $counts);

        Livewire::test(InboxPage::class)->call('pickChannel', 'form')->assertSet('channel', 'form')
            ->assertSee('Form Hanım')->assertDontSee('Elle Bey')->assertDontSee('drayse');
        Livewire::test(InboxPage::class)->call('pickChannel', 'meta_form')
            ->assertSee('Meta Hanım')->assertDontSee('Form Hanım');
        Livewire::test(InboxPage::class)->call('pickChannel', 'referans')->assertSet('channel', '');
        $this->get('/gelen-kutusu?kanal=instagram')->assertOk()->assertSee('Instagram')->assertSee('drayse')->assertDontSee('Form Hanım');
    }

    public function test_a_call_is_typed_in_on_a_record_and_kim_shows_what_is_known_about_the_person(): void
    {
        $leads = app(SalesLeads::class);
        $known = $leads->quickAdd(['name' => 'Dt. Kemal Arslan', 'phone' => '0534 000 22 33', 'source' => 'whatsapp', 'company' => 'Arslan Diş'], $this->admin);

        $page = Livewire::test(InboxPage::class)->call('pickChannel', 'cagri')->set('callOpen', true)
            ->set('callSearch', 'Kemal')->assertSee('Dt. Kemal Arslan')
            ->call('pickCallLead', $known->id)->set('call.note', 'Fiyat sordu, haftaya dönecek.')->call('addCall')
            ->assertHasNoErrors()->assertSet('item', 'adim-'.$known->id)->assertSet('callOpen', false);
        $this->assertSame('Fiyat sordu, haftaya dönecek.', $known->events()->where('kind', 'call')->sole()->body);
        $this->assertNotNull($known->fresh()->last_contact_at);
        $page->assertSee('Kim? · Dt. Kemal Arslan')->assertSee('Arslan Diş')->assertSee('Telefon / görüşme: 1 kayıt');

        Livewire::test(InboxPage::class)->call('pickChannel', 'cagri')->set('callOpen', true)
            ->set('call.note', 'Kimse yok')->call('addCall')->assertHasErrors('call.name');
        Livewire::test(InboxPage::class)->call('pickChannel', 'cagri')->set('callOpen', true)
            ->set('call.name', 'Yeni Arayan')->set('call.phone', '0534 999 88 77')->set('call.note', 'Siteden bulmuş.')->call('addCall')->assertHasNoErrors()
            ->assertSee('Yeni Arayan');
        $fresh = SalesLead::query()->where('name', 'Yeni Arayan')->sole();
        $this->assertSame('phone', $fresh->source);
        $this->assertSame(['adim-'.$fresh->id], app(InboxQueue::class)->received('cagri')->pluck('key')->filter(fn ($k) => $k === 'adim-'.$fresh->id)->values()->all());
    }

    public function test_threads_of_a_switched_off_account_never_reach_the_queue_badges_or_bulk_send(): void
    {
        $own = SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG1', 'username' => 'moximucom', 'access_token' => 't', 'page_id' => 'PAGE1']);
        $customer = SocialAccount::query()->create(['platform' => 'instagram', 'external_id' => 'IG2', 'username' => 'bir.klinik', 'access_token' => 't', 'page_id' => 'PAGE2', 'enabled' => false]);
        SocialThread::query()->create(['social_account_id' => $own->id, 'kind' => 'dm', 'external_id' => 'CONV1', 'author_name' => 'drayse',
            'last_text' => 'Fiyatlarınız nedir?', 'last_direction' => 'in', 'last_in_at' => now()->subMinutes(30), 'last_at' => now()->subMinutes(30), 'status' => 'open']);
        $foreign = SocialThread::query()->create(['social_account_id' => $customer->id, 'kind' => 'comment', 'external_id' => 'C9', 'author_name' => 'berisbora',
            'last_text' => 'Ruj yorumu', 'last_direction' => 'in', 'last_in_at' => now()->subMinutes(10), 'last_at' => now()->subMinutes(10), 'status' => 'open',
            'draft' => 'Hazır cevap', 'draft_status' => 'ready']);

        $items = app(InboxQueue::class)->items();
        $this->assertSame(['drayse'], $items->where('type', InboxItem::SOCIAL)->pluck('title')->values()->all());
        $this->assertNull(app(InboxQueue::class)->find('sosyal-'.$foreign->id));
        $this->assertSame(0, app(SocialInbox::class)->sendAllReady()['sent'] + app(SocialInbox::class)->sendAllReady()['failed']);
    }

    public function test_the_record_panel_does_what_the_card_did_status_offer_note_and_next_step(): void
    {
        $chat = $this->chat('905321112233', 'Serap Hanım');
        $lead = app(SalesLeads::class)->fromConversation($chat);
        $this->assertStringContainsString('kayit=wa-'.$chat->id, $lead->fresh()->workUrl(), 'a record with a chat is worked in the inbox');

        $page = Livewire::test(InboxPage::class)->call('select', 'wa-'.$chat->id)->assertSeeHtml('data-inbox-record-actions');
        $page->set('offer', '14.000')->call('saveLeadOffer');
        $this->assertSame(14000, $lead->fresh()->offer_amount);
        // 2026-11-09 is a Monday: "perşembe" is the 12th.
        $page->set('told', 'perşembe arayacak')->call('tellLead')->assertSet('told', '');
        $this->assertSame('2026-11-12', $lead->fresh()->next_step_at->timezone('Europe/Istanbul')->toDateString());
        $page->call('setLeadStatus', 'guclu');
        $this->assertTrue((bool) $lead->fresh()->is_strong);
        $page->call('setLeadStatus', 'olumsuz')->assertSeeHtml('data-inbox-closing')->set('closing', 'price')->call('closeLead');
        $this->assertSame('lost', $lead->fresh()->stage);
        $this->assertSame('price', $lead->fresh()->lost_reason);
    }
}
