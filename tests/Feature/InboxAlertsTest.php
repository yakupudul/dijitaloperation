<?php

namespace Tests\Feature;

use App\Livewire\Operator\Inbox\InboxPage;
use App\Models\AgencySetting;
use App\Models\PushSubscription;
use App\Models\SalesLead;
use App\Models\SocialAccount;
use App\Models\SocialThread;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\Inbox\InboxAlerts;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppIngestion;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Gelen kutusu → telefon (yakup 2026-11-09): every live message from a real person is pushed with who it is, one chat's
 * burst updates one notification quietly, nights are silent and summarised at 08:30, an unanswered chat is reminded
 * once, tapping opens the chat, and the inbox screen redraws by itself when something changed.
 */
final class InboxAlertsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup Udul', 'locale' => 'tr']);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        $this->travelTo('2026-11-09 09:00:00'); // 12:00 in Istanbul
        Http::fake(['*' => Http::response('', 201)]);
        Queue::fake();
        app(WhatsAppConnection::class)->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905388610288', 'business_context' => 'Ajans',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        PushSubscription::query()->create(['user_id' => $this->admin->id, 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
            'endpoint_hash' => PushSubscription::hashEndpoint('https://fcm.googleapis.com/fcm/send/abc'), 'public_key' => null, 'auth_token' => null, 'failures' => 0]);
        $this->actingAs($this->admin);
    }

    /** @return Collection<int, object> */
    private function pushes()
    {
        return DB::table('push_notifications')->where('channel', 'browser')->orderBy('id')->get();
    }

    public function test_a_new_person_is_pushed_with_who_and_the_words_and_a_burst_updates_quietly(): void
    {
        $this->ingest('905551112233', 'Merhaba, diş kliniğimiz için SEO fiyatı alabilir miyim?');
        $conversation = WhatsAppConversation::query()->where('contact_id', '905551112233')->firstOrFail();
        $rows = $this->pushes();
        $this->assertCount(1, $rows);
        $this->assertSame('Yeni kişi: Ayşe', $rows[0]->title);
        $this->assertStringContainsString('SEO fiyatı', $rows[0]->body);
        $this->assertSame('high', $rows[0]->severity);
        $this->assertSame(route('operator.inbox', ['kayit' => 'wa-'.$conversation->id]), $rows[0]->url);

        $this->travel(1)->minutes();
        $this->ingest('905551112233', 'Bir de Google reklamı soracaktım');
        $rows = $this->pushes();
        $this->assertCount(2, $rows);
        $this->assertSame('low', $rows[1]->severity, 'the same chat a moment later comes silently');
        $this->assertSame('2 mesaj · son: Bir de Google reklamı soracaktım', $rows[1]->body);
        $this->assertSame('wa-'.$conversation->id, InboxAlerts::chatOf($rows[1]->dedupe_key));

        $this->travel(10)->minutes();
        $this->ingest('905551112233', 'Merhaba?');
        $last = $this->pushes()->last();
        $this->assertSame('high', $last->severity, 'after the group window it rings again');
        $this->assertSame(SalesLead::STATUSES[SalesLead::query()->where('conversation_id', $conversation->id)->firstOrFail()->status()].': Ayşe', $last->title);

        // The device reads every push it has not shown, one tag per chat, the quiet one marked silent.
        $after = (int) $rows[0]->id;
        $json = $this->getJson(route('push.latest', ['after' => $after]))->assertOk()->json();
        $this->assertCount(2, $json['items']);
        $this->assertSame('inbox-wa-'.$conversation->id, $json['items'][0]['tag']);
        $this->assertTrue($json['items'][0]['silent']);
        $this->assertFalse($json['items'][1]['silent']);
    }

    public function test_history_reactions_own_numbers_and_opted_out_chats_are_not_pushed_and_nights_are_silent(): void
    {
        $this->ingest('905551112233', 'Eski mesaj', history: true);
        $this->ingest('905551112244', '👍', type: 'reaction');
        $this->ingest('905319527518', 'Kendi numaram');
        $this->ingest('905551112255', 'Çok eski', at: now()->subHours(5));
        $this->assertCount(0, $this->pushes());

        $this->travelTo('2026-11-09 20:30:00'); // 23:30 in Istanbul
        $this->ingest('905551112266', 'Gece yazıyorum');
        $this->assertSame('low', $this->pushes()->last()->severity);

        $this->travelTo('2026-11-10 05:30:00'); // 08:30
        $this->assertSame(1, app(InboxAlerts::class)->nightSummary());
        $this->assertStringStartsWith('Gece 1 kişi yazdı', (string) $this->pushes()->last()->title);
    }

    public function test_an_unanswered_chat_is_reminded_once_and_an_answered_one_is_not(): void
    {
        $this->ingest('905551112233', 'Fiyat?');
        $this->ingest('905551112299', 'Merhaba');
        $answered = WhatsAppConversation::query()->where('contact_id', '905551112299')->firstOrFail();
        $this->travel(10)->minutes();
        $this->ingest('905551112299', 'Tabii, hemen bakıyorum', outgoing: true);
        $this->assertTrue($answered->fresh()->last_message_at->gt($answered->fresh()->last_incoming_at));

        $this->travel(10)->minutes();
        $this->assertSame(0, app(InboxAlerts::class)->remindUnanswered(), 'not before 30 minutes');
        $this->travel(15)->minutes();
        $this->assertSame(1, app(InboxAlerts::class)->remindUnanswered());
        $this->assertStringContainsString('cevap bekliyor', (string) $this->pushes()->last()->title);
        $this->travel(10)->minutes();
        $this->assertSame(0, app(InboxAlerts::class)->remindUnanswered(), 'once per message');
    }

    public function test_an_instagram_direct_message_is_pushed_but_a_first_reads_history_is_not(): void
    {
        $account = SocialAccount::query()->create(['platform' => SocialAccount::INSTAGRAM, 'external_id' => 'ig1', 'name' => 'Moximu', 'username' => 'moximucom', 'enabled' => true]);
        $thread = SocialThread::query()->create(['social_account_id' => $account->id, 'kind' => SocialThread::DM, 'external_id' => 't1', 'author_name' => 'drmehmet', 'status' => SocialThread::OPEN]);
        $old = $thread->messages()->create(['external_id' => 'x0', 'direction' => 'in', 'author_name' => 'drmehmet', 'body' => 'Eski', 'sent_at' => now()->subDays(3)]);
        $this->assertFalse(app(InboxAlerts::class)->social($old->id));
        $new = $thread->messages()->create(['external_id' => 'x1', 'direction' => 'in', 'author_name' => 'drmehmet', 'body' => 'Selam, bilgi alabilir miyim?', 'sent_at' => now()]);
        $this->assertTrue(app(InboxAlerts::class)->social($new->id));
        $this->assertSame('drmehmet · Instagram', $this->pushes()->last()->title);
        $this->assertSame(route('operator.inbox', ['kayit' => 'sosyal-'.$thread->id]), $this->pushes()->last()->url);
    }

    public function test_the_inbox_screen_redraws_by_itself_only_when_something_changed(): void
    {
        $stamp = InboxPage::stamp();
        $page = Livewire::test(InboxPage::class)->call('pulse')->assertOk();
        $this->assertSame($stamp, $page->get('stamp'));
        $this->ingest('905551112233', 'Yeni mesaj');
        $this->assertNotSame($stamp, InboxPage::stamp());
        $page->call('pulse')->assertSee('Ayşe');
        $this->assertSame(InboxPage::stamp(), $page->get('stamp'));
    }

    private function ingest(string $from, string $body, bool $history = false, string $type = 'text', ?\DateTimeInterface $at = null, bool $outgoing = false): void
    {
        $at ??= now();
        $this->seq++;
        $message = ['id' => 'wamid.'.$from.'.'.$this->seq, 'from' => $outgoing ? '905388610288' : $from, 'timestamp' => (string) $at->getTimestamp(), 'type' => $type];
        $message += $type === 'reaction' ? ['reaction' => ['emoji' => $body]] : ['text' => ['body' => $body]];
        if ($outgoing) {
            $message['to'] = $from;
        }
        $value = ['messaging_product' => 'whatsapp', 'metadata' => ['phone_number_id' => '222222222'], 'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Ayşe']]]];
        $field = 'messages';
        if ($history) {
            $field = 'history';
            $value['history'] = [['threads' => [['id' => $from, 'messages' => [$message + ['history_context' => ['status' => 'READ']]]]]]];
        } elseif ($outgoing) {
            $field = 'smb_message_echoes';
            $value['message_echoes'] = [$message];
        } else {
            $value['messages'] = [$message];
        }
        $receipt = WhatsAppWebhookReceipt::query()->create([
            'integration_id' => app(WhatsAppConnection::class)->integration()->id, 'payload_hash' => hash('sha256', $from.$body.$this->seq), 'status' => 'pending',
            'payload' => ['entry' => [['id' => '111111111', 'changes' => [['field' => $field, 'value' => $value]]]]],
        ]);
        app(WhatsAppIngestion::class)->process($receipt->id);
    }
}
