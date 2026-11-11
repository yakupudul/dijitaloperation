<?php

namespace Tests\Feature;

use App\Ai\Agents\SalesReplyAgent;
use App\Livewire\Operator\Sales\SalesPage;
use App\Models\AgencySetting;
use App\Models\AiTask;
use App\Models\CoreIntegration;
use App\Models\PushSubscription;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Sales\DoorScreen;
use App\Services\Sales\SalesLeads;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Kapıda eleme (Satış Düzeni 2.2): a patient, a job seeker and a wrong number never become a card. */
class SalesDoorScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $integrationId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        Http::fake();
        $this->actingAs($this->admin);
        app(WhatsAppConnection::class)->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905388610288', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        $this->integrationId = app(WhatsAppConnection::class)->integration()->id;
    }

    private function chat(string $phone, string ...$said): WhatsAppConversation
    {
        $conversation = WhatsAppConversation::query()->create([
            'integration_id' => $this->integrationId, 'phone_number_id' => '222222222', 'contact_id' => $phone, 'contact_name' => null,
            'last_message_at' => now(), 'last_incoming_at' => now(),
        ]);
        foreach ($said as $i => $body) {
            WhatsAppMessage::query()->create(['conversation_id' => $conversation->id, 'message_id' => $phone.'-'.$i, 'direction' => 'incoming',
                'message_type' => 'text', 'body' => $body, 'sent_at' => now()->subMinutes(10 - $i), 'is_history' => false]);
        }

        return $conversation;
    }

    public function test_the_rules_keep_anyone_who_talks_business(): void
    {
        $this->assertSame('patient', DoorScreen::judge('Merhaba, yarın için randevu almak istiyorum')['kind']);
        $this->assertSame('patient', DoorScreen::judge('Dişim çok ağrıyor, bugün gelebilir miyim?')['kind']);
        $this->assertSame('job', DoorScreen::judge('Merhabalar iş arıyorum, CV göndereyim mi')['kind']);
        $this->assertSame('wrong', DoorScreen::judge('Pardon yanlış numara')['kind']);
        $this->assertNull(DoorScreen::judge('Merhaba, kliniğimiz için web sitesi ve randevu almak isteyen hasta sayısını artırmak istiyoruz'));
        $this->assertNull(DoorScreen::judge('Merhaba fiyat alabilir miyim'));
        $this->assertNull(DoorScreen::judge('Google reklam için randevu alabilir miyim'));
        $this->assertNull(DoorScreen::judge('Merhaba'));
    }

    public function test_a_patient_gets_no_card_no_answer_and_stays_out_until_brought_back(): void
    {
        $leads = app(SalesLeads::class);
        $patient = $this->chat('905001112233', 'Merhaba', 'Yarın için randevu almak istiyorum');
        $prospect = $this->chat('905004445566', 'Merhaba, sitemiz için SEO fiyatı alabilir miyim');

        $leads->refreshFromConversation($patient);
        $leads->refreshFromConversation($prospect);

        $out = SalesLead::query()->where('conversation_id', $patient->id)->firstOrFail();
        $this->assertSame('irrelevant', $out->lifecycle);
        $this->assertSame('Hasta', DoorScreen::verdict($out));
        $this->assertNull($out->research_status, 'nobody is looked up for a patient');
        $this->assertSame(0, AiTask::query()->where('subject_type', SalesLead::class)->where('subject_id', $out->id)->count());
        $in = SalesLead::query()->where('conversation_id', $prospect->id)->firstOrFail();
        $this->assertSame('prospect', $in->lifecycle);
        $this->assertSame(1, SalesLead::query()->open()->relevant()->count());

        // They write again: still not a card.
        WhatsAppMessage::query()->create(['conversation_id' => $patient->id, 'message_id' => 'again', 'direction' => 'incoming', 'message_type' => 'text',
            'body' => 'Orada mısınız?', 'sent_at' => now()->addMinute(), 'is_history' => false]);
        $leads->refreshFromConversation($patient->fresh());
        $this->assertSame('irrelevant', $out->fresh()->lifecycle);

        Livewire::test(SalesPage::class)->assertSeeHtml('data-sales-door-week')
            ->call('showStatus', 'aday_degil')
            ->assertSee('Kapıda elendi · Hasta')
            ->call('setStatus', $out->id, 'devam');
        $back = $out->fresh();
        $this->assertSame('prospect', $back->lifecycle);
        $this->assertTrue($back->isOpen());
        $this->assertNull(DoorScreen::verdict($back));

        // Brought back by hand: the door never judges this record again.
        $leads->refreshFromConversation($patient->fresh());
        $this->assertSame('prospect', $out->fresh()->lifecycle);
    }

    public function test_a_new_persons_first_answer_is_pushed_once_and_the_tap_opens_whatsapp_with_the_text(): void
    {
        $this->travelTo('2026-11-09 09:00:00'); // 12:00 in Istanbul
        config(['moxdop.anthropic.api_key' => 'sk-ant-test']);
        CoreIntegration::factory()->anthropic()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        PushSubscription::query()->create(['user_id' => $this->admin->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/abc'), 'public_key' => null, 'auth_token' => null, 'failures' => 0]);
        SalesReplyAgent::fake([[
            'action' => 'reply', 'reply' => 'Merhaba, memnuniyetle. Hangi işletme için düşünüyorsunuz, sitenizi yazar mısınız?',
            'send_on' => '2026-11-09', 'note' => 'İlk cevap.', 'summary' => 'SEO fiyatı sordu.',
        ]]);
        $chat = $this->chat('905004445566', 'Merhaba, sitemiz için SEO fiyatı alabilir miyim');
        app(SalesLeads::class)->refreshFromConversation($chat);

        $lead = SalesLead::query()->where('conversation_id', $chat->id)->firstOrFail();
        $this->assertSame('ready', $lead->draft_status);
        $push = DB::table('push_notifications')->where('dedupe_key', 'first-answer:'.$lead->id)->get();
        $this->assertCount(1, $push);
        $this->assertStringStartsWith('İlk cevap hazır', (string) $push[0]->title);
        $this->assertStringContainsString('/satis/'.$lead->id.'/ilk-cevap', (string) $push[0]->url);

        $this->get(route('operator.sales.first-answer', ['lead' => $lead->id]))
            ->assertRedirect('https://wa.me/905004445566?text='.rawurlencode((string) $lead->draft));

        // Once we have answered, a later draft is not a "first answer" any more.
        $lead->forceFill(['draft' => null])->save();
        $this->get(route('operator.sales.first-answer', ['lead' => $lead->id]))->assertRedirect(route('operator.sales.lead', ['lead' => $lead->id]));
    }
}
