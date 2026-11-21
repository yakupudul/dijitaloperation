<?php

namespace Tests\Feature\Panel;

use App\Livewire\Operator\Settings\PanelUsersPage;
use App\Livewire\Panel\Bell;
use App\Livewire\Panel\InboxPage;
use App\Livewire\Panel\PersonPage;
use App\Models\User;
use App\Panel\Models\PanelChannel;
use App\Panel\Models\PanelConversation;
use App\Panel\Models\PanelMessage;
use App\Panel\Models\PanelNotification;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelRequest;
use App\Panel\Models\PanelWebhookReceipt;
use App\Panel\Services\PanelChannels;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelWatch;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelStages;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;

/** Sürüm 3: a brand's own channels. What Meta sends becomes that brand's conversations and leads, nobody else's. */
class PanelChannelsTest extends PanelTestCase
{
    private string $secret = 'app-secret-for-tests';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        app(WhatsAppConnection::class)->save($admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905550000000', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => $this->secret, 'verify_token' => 'verify-me-1234567890',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
    }

    private function hook(array $payload, ?string $secret = null): TestResponse
    {
        $raw = json_encode($payload);

        return $this->call('POST', route('api.panel.channels.receive'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $secret ?? $this->secret)], $raw);
    }

    private function whatsapp(string $phoneId, string $from, string $id, string $text, int $at, ?array $referral = null, bool $echo = false): array
    {
        $message = array_filter(['id' => $id, 'from' => $echo ? '905550000000' : $from, 'to' => $echo ? $from : null, 'timestamp' => (string) $at, 'type' => 'text', 'text' => ['body' => $text], 'referral' => $referral]);

        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => '777', 'changes' => [['field' => $echo ? 'smb_message_echoes' : 'messages', 'value' => [
            'messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => $phoneId], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Zehra Yazan']]],
            $echo ? 'message_echoes' : 'messages' => [$message],
        ]]]]]];
    }

    private function connect(string $channel, string $id, ?string $token = null): PanelChannel
    {
        return app(PanelChannels::class)->connect($this->brandA->id, $channel, $id, null, null, $token, null);
    }

    public function test_a_whatsapp_message_opens_a_lead_in_its_brand_and_an_answer_clears_the_wait(): void
    {
        $this->connect('whatsapp', '555001');
        $this->hook($this->whatsapp('555001', '905359990001', 'wamid.1', 'Merhaba, bilgi almak istiyorum', now()->timestamp), 'wrong')->assertForbidden();
        $this->hook($this->whatsapp('999999', '905359990001', 'wamid.0', 'Bağlı olmayan numara', now()->timestamp))->assertOk();
        $this->assertSame(0, PanelWebhookReceipt::query()->count(), 'an event for nobody\'s channel is dropped');
        $payload = $this->whatsapp('555001', '905359990001', 'wamid.1', 'Merhaba, bilgi almak istiyorum', now()->timestamp);
        $this->hook($payload)->assertOk()->assertSee('EVENT_RECEIVED');
        $this->hook($payload)->assertOk();

        $this->enter($this->managerA, $this->brandA);
        $person = PanelPerson::query()->where('phone_key', '5359990001')->firstOrFail();
        $this->assertSame(['Zehra Yazan', 'whatsapp', PanelStages::NEW], [$person->name, $person->source, $person->stage]);
        $chat = PanelConversation::query()->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame([$person->id, 1, 'live', null], [$chat->person_id, $chat->message_count, $chat->origin, $chat->handled_at]);
        $this->assertStringNotContainsString('bilgi almak', (string) DB::table('panel_messages')->value('body'));
        $this->assertNull(PanelWebhookReceipt::query()->firstOrFail()->getRawOriginal('payload'), 'the raw event is wiped once it is read');
        Livewire::test(Bell::class)->assertSee('Yeni talep: Zehra Yazan, WhatsApp');
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->assertSee('WhatsApp bağlı')->assertSee('Zehra Yazan')->set('open', $chat->id)->assertSee('Merhaba, bilgi almak istiyorum');
        Livewire::test(PersonPage::class, ['person' => (string) $person->id])->assertSee('WhatsApp yazışmasını aç');

        // A second line in the same minute is one more message, not a second lead; the brand's own answer clears the wait.
        app(PanelContext::class)->leave();
        $this->hook($this->whatsapp('555001', '905359990001', 'wamid.2', 'Fiyat nedir?', now()->timestamp + 20))->assertOk();
        $this->hook($this->whatsapp('555001', '905359990001', 'wamid.3', 'Merhaba, hemen yazıyorum', now()->timestamp + 60, null, true))->assertOk();
        $this->enter($this->managerA, $this->brandA);
        $this->assertSame(1, PanelRequest::query()->where('person_id', $person->id)->count());
        $this->assertSame(3, PanelMessage::query()->count());
        $this->assertNotNull($chat->fresh()->handled_at);
        $this->assertTrue((bool) PanelMessage::query()->where('external_id', 'wamid.3')->firstOrFail()->outgoing);

        $this->enter($this->managerB, $this->brandB);
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->assertDontSee('Zehra Yazan')->assertDontSee('WhatsApp bağlı');
    }

    public function test_an_ad_message_writes_its_source_and_a_returning_person_is_a_new_request(): void
    {
        $this->connect('instagram', '17840000001');
        $event = fn (string $mid, string $text, int $ms, ?array $referral = null): array => ['object' => 'instagram', 'entry' => [['id' => '17840000001', 'time' => $ms, 'messaging' => [[
            'sender' => ['id' => 'igsid-42'], 'recipient' => ['id' => '17840000001'], 'timestamp' => $ms, 'message' => array_filter(['mid' => $mid, 'text' => $text, 'referral' => $referral]),
        ]]]]];
        $this->hook($event('m1', 'Reklamınızı gördüm', now()->getTimestampMs(), ['source' => 'ADS', 'ad_id' => '2385', 'ads_context_data' => ['ad_title' => 'Kasım kampanyası']]))->assertOk();
        $this->enter($this->managerA, $this->brandA);
        $chat = PanelConversation::query()->where('channel', 'instagram')->firstOrFail();
        $person = $chat->person;
        $this->assertSame(['meta_ads', 'Instagram kullanıcısı'], [$person->source, $person->name]);
        $this->assertSame(['id' => '2385', 'title' => 'Kasım kampanyası'], PanelRequest::query()->where('person_id', $person->id)->firstOrFail()->meta['ad']);

        // Ten days later the same account writes again: the same person, a second request, lost becomes new again.
        app(PanelLeads::class)->setStage($person, PanelStages::LOST, 'price', $this->managerA->id);
        app(PanelContext::class)->leave();
        $this->travel(10)->days();
        $this->hook($event('m2', 'Hâlâ geçerli mi?', now()->getTimestampMs()))->assertOk();
        $this->enter($this->managerA, $this->brandA);
        $this->assertSame(1, PanelPerson::query()->where('source', 'meta_ads')->count());
        $this->assertSame(2, PanelRequest::query()->where('person_id', $person->id)->count());
        $this->assertSame(PanelStages::NEW, $person->fresh()->stage);
    }

    public function test_a_meta_lead_form_becomes_a_lead_and_a_dead_token_cuts_the_channel(): void
    {
        $this->connect('meta_form', '10900000001', 'page-token');
        $leadgen = fn (string $id): array => ['object' => 'page', 'entry' => [['id' => '10900000001', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => $id, 'page_id' => '10900000001', 'form_id' => '77']]]]]];
        Http::fake(['graph.facebook.com/*/444*' => Http::response(['id' => '444', 'form_id' => '77', 'ad_id' => '9001', 'ad_name' => 'Form reklamı', 'field_data' => [
            ['name' => 'full_name', 'values' => ['Kerem Form']], ['name' => 'phone_number', 'values' => ['+905359990077']], ['name' => 'hangi_gun_uygun', 'values' => ['Cumartesi']],
        ]]), 'graph.facebook.com/*/555*' => Http::response(['error' => ['message' => 'Error validating access token', 'code' => 190]], 400)]);
        $this->hook($leadgen('444'))->assertOk();
        $this->enter($this->managerA, $this->brandA);
        $person = PanelPerson::query()->where('phone_key', '5359990077')->firstOrFail();
        $request = PanelRequest::query()->where('person_id', $person->id)->firstOrFail();
        $this->assertSame(['Kerem Form', 'meta_form', 'hangi gun uygun: Cumartesi', 'Form reklamı'], [$person->name, $request->source, $request->message, $request->meta['ad']['title']]);
        Livewire::test(InboxPage::class)->set('channel', 'meta_form')->assertSee('Kerem Form')->assertSee('Meta formu bağlı');

        app(PanelContext::class)->leave();
        $this->hook($leadgen('555'))->assertOk();
        $this->enter($this->managerA, $this->brandA);
        $this->assertSame(PanelChannel::DISCONNECTED, PanelChannel::query()->where('channel', 'meta_form')->firstOrFail()->status);
        Livewire::test(InboxPage::class)->set('channel', 'meta_form')->assertSee('Meta formu bağlantısı kesildi');
        $this->assertTrue(PanelNotification::query()->where('title', 'like', 'Meta formu bağlantısı kesildi%')->exists());
    }

    public function test_the_agency_connects_a_channel_once_and_hears_when_it_goes_silent(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $page = Livewire::test(PanelUsersPage::class)->call('open', $this->brandA->id)->assertSee('Mesaj kanalları')->call('startChannel', 'whatsapp')
            ->set('channelForm.external_id', 'abc')->call('connectChannel')->assertHasErrors('channelForm.external_id')
            ->set('channelForm.external_id', '555001')->set('channelForm.label', '+90 535 000 00 00')->call('connectChannel')->assertHasNoErrors()->assertSee('Bağlantıyı kes');
        Livewire::test(PanelUsersPage::class)->call('open', $this->brandB->id)->call('startChannel', 'whatsapp')->set('channelForm.external_id', '555001')->call('connectChannel')
            ->assertHasErrors('channelForm.external_id');
        $channel = app(PanelContext::class)->system(fn () => PanelChannel::query()->firstOrFail());
        $this->assertSame([$this->brandA->id, 'connected', $admin->id], [$channel->brand_id, $channel->status, $channel->connected_by]);

        // It spoke once and has been silent for four days: the brand's manager and the agency hear it once.
        app(PanelContext::class)->system(fn () => $channel->forceFill(['last_event_at' => now()->subDays(4)])->save());
        $this->assertSame(1, app(PanelWatch::class)->run()['silent_channels']);
        $this->assertSame(0, app(PanelWatch::class)->run()['silent_channels']);
        $this->assertTrue(app(PanelContext::class)->system(fn () => PanelNotification::query()->where('panel_user_id', $this->managerA->id)->where('title', 'like', 'WhatsApp kanalından 3 gündür%')->exists()));

        $page->call('disconnectChannel', $channel->id)->assertSee('Yeniden bağla');
        $this->hook($this->whatsapp('555001', '905359990001', 'wamid.9', 'Kesikken gelen', now()->timestamp))->assertOk();
        $this->assertSame(0, app(PanelContext::class)->system(fn () => PanelMessage::query()->count()), 'a cut channel feeds nothing');
    }

    public function test_the_agency_webhook_hands_a_brands_number_to_the_panel_and_keeps_its_own_inbox_clean(): void
    {
        $this->connect('whatsapp', '555001');
        $raw = json_encode($this->whatsapp('555001', '905359990001', 'wamid.1', 'Ajans adresine düşen marka mesajı', now()->timestamp));
        $this->call('POST', route('api.whatsapp.receive'), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $raw, $this->secret)], $raw)->assertOk();
        $this->assertSame(1, app(PanelContext::class)->system(fn () => PanelMessage::query()->count()));
        $this->assertSame(0, DB::table('whatsapp_messages')->count(), 'the agency inbox does not read a brand\'s chat');
        $this->get(route('api.panel.channels.webhook', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'verify-me-1234567890', 'hub.challenge' => '4242']))->assertOk()->assertSee('4242');
        $this->get(route('api.panel.channels.webhook', ['hub.mode' => 'subscribe', 'hub.verify_token' => 'nope', 'hub.challenge' => '4242']))->assertForbidden();
    }
}
