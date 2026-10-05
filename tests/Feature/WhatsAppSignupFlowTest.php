<?php

namespace Tests\Feature;

use App\Jobs\WhatsApp\CompleteWhatsAppSignup;
use App\Livewire\Operator\WhatsApp\Inbox;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppSignupAttempt;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Services\WhatsApp\WhatsAppSignup;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Connecting the WhatsApp number through Meta's popup: where the popup stopped is kept and shown, Meta's short-lived
 * code is exchanged at once, the shared account is found even without the popup's account message, a WhatsApp
 * Business app (coexistence) number's chat history is asked on the operator's click, and the screen explains Meta
 * errors in Turkish.
 */
final class WhatsAppSignupFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $sessionId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->sessionId = Str::random(40);
        app(WhatsAppSignup::class)->saveSetup($this->admin, [
            'app_id' => '123456', 'signup_config_id' => '987654', 'signup_mode' => 'coexistence',
            'app_secret' => 'test-app-secret', 'verify_token' => 'test-verify-token-12345',
        ]);
    }

    public function test_a_popup_closed_at_a_step_is_recorded_and_shown_on_the_screen(): void
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);

        $this->browser()->post(route('operator.whatsapp.report', $attempt->id), ['event' => 'CANCEL', 'current_step' => 'PHONE_NUMBER_SETUP'])
            ->assertOk();

        $fresh = $attempt->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('PHONE_NUMBER_SETUP', $fresh->details['meta_step']);
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->assertSee('Meta penceresinde yarıda kaldı')->assertSee('telefon numarası ekleme');
    }

    public function test_an_error_shown_in_the_popup_is_kept_with_its_meta_reference(): void
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);

        $this->browser()->post(route('operator.whatsapp.report', $attempt->id), [
            'event' => 'ERROR', 'error_message' => 'Bu numara zaten kayıtlı', 'error_id' => '524126', 'session_id' => 'f34b',
        ])->assertOk();

        $details = $attempt->fresh()->details;
        $this->assertStringContainsString('Bu numara zaten kayıtlı', $details['message']);
        $this->assertSame(['524126', 'f34b'], [$details['error_id'], $details['session_id']]);
    }

    public function test_a_popup_that_returns_at_once_points_to_the_allowed_domains(): void
    {
        config(['app.url' => 'https://app.moximu.com']);
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);

        $this->browser()->post(route('operator.whatsapp.report', $attempt->id), ['event' => 'LOGIN_REFUSED'])->assertOk();

        $this->assertStringContainsString('Allowed Domains', $attempt->fresh()->details['message']);
        $this->assertStringContainsString('https://app.moximu.com', $attempt->fresh()->details['message']);
    }

    public function test_the_meta_code_is_exchanged_right_away_even_without_an_account_message(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'business-token'])]);
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);

        $this->browser()->postJson(route('operator.whatsapp.complete', $attempt->id), ['code' => 'meta-code', 'event' => 'CODE_ONLY'])
            ->assertOk()->assertJson(['queued' => true]);

        $fresh = $attempt->fresh();
        $this->assertSame('queued', $fresh->status);
        $this->assertSame('business-token', $fresh->payload['access_token']);
        $this->assertArrayNotHasKey('code', $fresh->payload);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'oauth/access_token') && str_contains($request->url(), 'code=meta-code'));
        Queue::assertPushed(CompleteWhatsAppSignup::class, fn (CompleteWhatsAppSignup $job): bool => $job->attemptId === $attempt->id);
    }

    public function test_an_expired_code_fails_the_attempt_with_the_reason(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'This authorization code has expired.', 'code' => 100, 'error_subcode' => 36007]], 400)]);
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);

        $this->browser()->postJson(route('operator.whatsapp.complete', $attempt->id), ['code' => 'old-code', 'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', 'waba_id' => '555555'])
            ->assertOk();

        $fresh = $attempt->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('expired', $fresh->details['message']);
        Queue::assertNothingPushed();
    }

    public function test_the_finish_kind_is_kept_when_meta_sends_no_account_id(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'business-token'])]);
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);

        $this->browser()->postJson(route('operator.whatsapp.complete', $attempt->id), [
            'code' => 'meta-code', 'event' => 'CODE_ONLY', 'finish_event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING',
        ])->assertOk();

        $this->assertSame('FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING', $attempt->fresh()->payload['event']);
        $this->assertNull($attempt->fresh()->payload['waba_id']);
    }

    public function test_a_business_app_number_is_bound_and_its_history_is_asked_on_the_operators_click(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => '555555', 'phone_number_id' => null, 'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING']);
        $this->fakeGraph();

        app(WhatsAppSignup::class)->complete($attempt->id);

        $config = app(WhatsAppConnection::class)->integration()->config;
        $this->assertSame('completed', $attempt->fresh()->status);
        $this->assertSame(['555555', '666666', '905321112233'], [$config['waba_id'], $config['phone_number_id'], $config['business_phone']]);
        $this->assertSame('signup_reported', $config['coexistence_state']);
        $this->assertNull($config['history_sync']);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'smb_app_data'));

        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)
            ->assertSee('Telefondaki geçmiş mesajları alın')
            ->call('requestHistory')
            ->assertSee('geçmiş mesajlar istendi')
            ->assertDontSee('Geçmiş mesajları al');

        $this->assertSame('requested', app(WhatsAppConnection::class)->integration()->config['history_sync']);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '666666/smb_app_data') && $request['sync_type'] === 'history');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'smb_app_data') && $request['sync_type'] === 'smb_app_state_sync');
    }

    public function test_a_refused_history_request_can_be_retried_inside_the_window(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => '555555', 'phone_number_id' => null, 'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING']);
        Http::fake(fn (Request $request) => str_contains($request->url(), 'smb_app_data')
            ? Http::response(['error' => ['message' => 'History sharing was declined', 'code' => 100]], 400)
            : $this->graphResponse($request));
        app(WhatsAppSignup::class)->complete($attempt->id);
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)
            ->call('requestHistory')
            ->assertSee('Meta geçmiş mesaj aktarımını başlatmadı')
            ->assertSee('History sharing was declined')
            ->assertSee('Tekrar dene');
        $this->assertSame('failed', app(WhatsAppConnection::class)->integration()->config['history_sync']);
    }

    public function test_history_cannot_be_asked_after_the_24_hour_window(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => '555555', 'phone_number_id' => null, 'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING']);
        $this->fakeGraph();
        app(WhatsAppSignup::class)->complete($attempt->id);
        $this->travel(25)->hours();
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)
            ->assertDontSee('Telefondaki geçmiş mesajları alın')
            ->call('requestHistory')
            ->assertHasErrors('history');
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'smb_app_data'));
    }

    public function test_without_an_account_message_the_only_shared_account_is_found_from_the_token(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => null, 'phone_number_id' => null, 'event' => 'CODE_ONLY']);
        $this->fakeGraph();

        app(WhatsAppSignup::class)->complete($attempt->id);

        $config = app(WhatsAppConnection::class)->integration()->config;
        $this->assertSame('completed', $attempt->fresh()->status);
        $this->assertSame(['555555', '666666', 'unknown'], [$config['waba_id'], $config['phone_number_id'], $config['coexistence_state']]);
        $this->assertNotNull(app(WhatsAppConnection::class)->historyDeadline(app(WhatsAppConnection::class)->integration()),
            'coexistence mode: history can be asked even when Meta did not say which path was used');
    }

    public function test_a_number_found_among_several_shared_accounts_waits_for_the_operators_choice(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => null, 'phone_number_id' => null, 'event' => 'CODE_ONLY']);
        Http::fake(fn (Request $request) => match (true) {
            str_contains($request->url(), '/debug_token') => Http::response(['data' => [
                'is_valid' => true, 'app_id' => '123456', 'expires_at' => 0,
                'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
                'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['555555', '777777']]],
            ]]),
            str_contains($request->url(), '777777/phone_numbers') => Http::response(['data' => []]),
            default => $this->graphResponse($request),
        });

        app(WhatsAppSignup::class)->complete($attempt->id);

        $fresh = $attempt->fresh();
        $this->assertSame('choose_phone', $fresh->status);
        $this->assertSame(['666666'], array_column($fresh->payload['phones'], 'id'));
        $this->assertStringContainsString('hangi hesabı seçtiğinizi bildirmedi', $fresh->details['message']);
        $this->assertNull(app(WhatsAppConnection::class)->integration()->config['phone_number_id'] ?? null);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'subscribed_apps'));
    }

    public function test_a_cloud_api_finish_in_business_app_mode_is_accepted_and_marked(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => '555555', 'phone_number_id' => '666666', 'event' => 'FINISH']);
        $this->fakeGraph();

        app(WhatsAppSignup::class)->complete($attempt->id);

        $this->assertSame('completed', $attempt->fresh()->status);
        $this->assertSame('not_used', app(WhatsAppConnection::class)->integration()->config['coexistence_state']);
        $this->assertNull(app(WhatsAppConnection::class)->historyDeadline(app(WhatsAppConnection::class)->integration()));
    }

    public function test_an_unregistered_new_number_is_refused_and_the_saved_number_kept(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => '555555', 'phone_number_id' => '666666', 'event' => 'FINISH']);
        Http::fake(fn (Request $request) => str_contains($request->url(), '/666666?')
            ? Http::response(['id' => '666666', 'platform_type' => 'NOT_APPLICABLE', 'is_on_biz_app' => false])
            : $this->graphResponse($request));

        app(WhatsAppSignup::class)->complete($attempt->id);

        $fresh = $attempt->fresh();
        $this->assertSame('failed', $fresh->status);
        $this->assertStringContainsString('Mevcut WhatsApp Business uygulamanızı bağlayın', $fresh->details['message']);
        $this->assertNull(app(WhatsAppConnection::class)->integration()->config['phone_number_id'] ?? null);
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'subscribed_apps'));
    }

    public function test_a_business_app_number_is_recognised_even_without_metas_finish_event(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => null, 'phone_number_id' => null, 'event' => 'CODE_ONLY']);
        Http::fake(fn (Request $request) => str_contains($request->url(), '/666666?')
            ? Http::response(['id' => '666666', 'platform_type' => 'CLOUD_API', 'is_on_biz_app' => true])
            : $this->graphResponse($request));

        app(WhatsAppSignup::class)->complete($attempt->id);

        $this->assertSame('completed', $attempt->fresh()->status);
        $this->assertSame('signup_reported', app(WhatsAppConnection::class)->integration()->config['coexistence_state']);
    }

    public function test_a_number_entered_by_hand_closes_the_business_app_history_window(): void
    {
        $attempt = $this->queuedAttempt(['access_token' => 'business-token', 'waba_id' => '555555', 'phone_number_id' => null, 'event' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING']);
        $this->fakeGraph();
        app(WhatsAppSignup::class)->complete($attempt->id);
        $this->assertNotNull(app(WhatsAppConnection::class)->historyDeadline(app(WhatsAppConnection::class)->integration()));

        $this->bindManually();

        $config = app(WhatsAppConnection::class)->integration()->config;
        $this->assertSame('manual', $config['connected_via']);
        $this->assertArrayNotHasKey('coexistence_state', $config);
        $this->assertNull(app(WhatsAppConnection::class)->historyDeadline(app(WhatsAppConnection::class)->integration()));
    }

    public function test_a_reconnect_started_from_a_connected_number_shows_its_progress(): void
    {
        $this->bindManually();
        $this->actingAs($this->admin);
        // Started from this screen's session: only that session gets the link to the connect page.
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, session()->getId());
        $attempt->update(['status' => 'choose_phone', 'details' => ['message' => 'Meta birden fazla numara paylaştı. Bağlamak istediğiniz numarayı seçin.']]);

        Livewire::test(Inbox::class)
            ->assertSee('Bağlı · +905551112233')
            ->assertSee('Numarayı yeniden bağlama')
            ->assertSee('Numara seçmeniz gerekiyor')
            ->assertSee(route('operator.whatsapp.connect', ['attempt' => $attempt->id]))
            ->assertSee('Kayıtlı numara bu sırada çalışmaya devam ediyor');

        $attempt->update(['status' => 'failed', 'details' => ['message' => 'Bağlantı sırasında ayarlar değişti. Yeniden bağlayın.']]);
        Livewire::test(Inbox::class)->assertSee('Tamamlanamadı')->assertSee('Bağlantı sırasında ayarlar değişti');
    }

    public function test_an_expired_saved_token_is_explained_in_turkish_with_the_fix(): void
    {
        $this->bindManually();
        $integration = app(WhatsAppConnection::class)->integration();
        $integration->update(['config' => [...$integration->config, 'connection_check' => 'failed', 'connection_error' => [
            'http_status' => 401, 'code' => 190, 'subcode' => 463, 'message' => 'Error validating access token: Session has expired on Thursday, 10-Sep-26.',
        ]]]);
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)
            ->assertSee('Bağlantı sorunu')
            ->assertSee('Kayıtlı erişim anahtarının süresi dolmuş')
            ->assertSee('Numarayı yeniden bağlayın')
            ->call('openManual')
            ->assertSee('Yanıt önerileri')
            ->assertSee('Elle bağla')
            ->assertSee('Son mesaj aktarımları');
    }

    public function test_a_new_install_shows_the_three_setup_steps(): void
    {
        WhatsAppSignupAttempt::query()->delete();
        app(WhatsAppConnection::class)->integration()->delete();
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)
            ->assertSee('WhatsApp numaranızı bağlayın')
            ->assertSee('Meta uygulama bilgileri')
            ->assertSee('Mesajların MoxDOP');
    }

    public function test_a_connected_number_shows_the_conversation_and_the_draft_to_copy(): void
    {
        $this->bindManually();
        $integration = app(WhatsAppConnection::class)->integration();
        $conversation = WhatsAppConversation::query()->create([
            'integration_id' => $integration->id, 'phone_number_id' => '222222222', 'contact_id' => '905009998877',
            'contact_name' => 'Mehmet Bey', 'last_message_at' => now(), 'last_incoming_at' => now(), 'revision' => 1,
            'suggested_revision' => 1, 'suggestion_status' => 'ready', 'suggestion' => 'Merhaba Mehmet Bey, fiyatı hemen iletiyorum.',
            'summary' => 'Fiyat sorusu', 'rationale' => 'Fiyat sordu.', 'suggested_at' => now(),
        ]);
        WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id, 'message_id' => 'wamid.1', 'direction' => 'incoming', 'message_type' => 'text',
            'body' => 'Merhaba, fiyat öğrenebilir miyim?', 'sent_at' => now(),
        ]);
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)
            ->assertSee('Bağlı · +905551112233')
            ->assertDontSee('WhatsApp numaranızı bağlayın')
            ->call('selectConversation', $conversation->id)
            ->assertSee('Merhaba, fiyat öğrenebilir miyim?')
            ->assertSee('Merhaba Mehmet Bey, fiyatı hemen iletiyorum.')
            ->assertSee('Cevabı kopyala');
    }

    public function test_the_connect_pages_diary_shows_the_open_popup_and_a_page_left_mid_way(): void
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);
        $this->browser()->get(route('operator.whatsapp.connect', $attempt->id))
            ->assertOk()->assertSee('WhatsApp Business\'ta kullandığınız numarayı girin.')->assertDontSee('daha önce açılmıştı');

        $this->browser()->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'LAUNCHED'])->assertOk()->assertJson(['saved' => true]);
        $this->browser()->get(route('operator.whatsapp.connect', $attempt->id))->assertOk()->assertSee('Meta penceresi daha önce açılmıştı');
        $fresh = $attempt->fresh();
        $this->assertSame('prepared', $fresh->status);
        $this->assertNotNull($fresh->launched_at);
        $this->assertTrue($fresh->expires_at->greaterThan(now()->addMinutes(29)));
        $this->actingAs($this->admin);
        Livewire::test(Inbox::class)->assertSee('Meta penceresi açıldı, sonuç bekleniyor')->assertSee('Bağlantı sayfasının kaydı (1)');

        $this->browser()->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'MESSAGE', 'note' => 'FINISH_WHATSAPP_BUSINESS_APP_ONBOARDING · waba 555555'])->assertOk();
        // The page's beacon on leaving is a plain form post.
        $this->browser()->post(route('operator.whatsapp.report', $attempt->id), ['event' => 'PAGE_LEFT', 'note' => 'Meta penceresi açıktı'])->assertOk();

        $this->assertSame(['LAUNCHED', 'MESSAGE', 'PAGE_LEFT'], array_column($attempt->fresh()->trace, 'event'));
        $this->assertSame('prepared', $attempt->fresh()->status);
        Livewire::test(Inbox::class)
            ->assertSee('Bağlantı sayfasının kaydı (3)')
            ->assertSee('Meta bildirimi geldi')
            ->assertSee('waba 555555')
            ->assertSee('bağlantı sayfasından çıkıldı');
    }

    public function test_an_attempt_never_launched_says_the_facebook_window_did_not_open(): void
    {
        app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)->assertSee('Facebook penceresi açılmadı')->assertSee('Facebook ile bağla" düğmesine basılmadı', false);
    }

    public function test_a_new_launch_after_a_cancel_waits_for_the_new_popup_and_keeps_the_earlier_ending(): void
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);
        $this->browser()->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'CANCEL', 'current_step' => 'PHONE_NUMBER_SETUP'])->assertOk();

        $this->browser()->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'LAUNCHED'])->assertOk();

        $fresh = $attempt->fresh();
        $this->assertSame('prepared', $fresh->status);
        $this->assertNull($fresh->details);
        $this->assertSame(['CANCEL', 'LAUNCHED'], array_column($fresh->trace, 'event'));
        $this->assertStringContainsString('PHONE_NUMBER_SETUP', $fresh->trace[0]['note']);
    }

    public function test_a_popup_closed_without_any_answer_from_facebook_is_explained(): void
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);

        $this->browser()->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'NO_CALLBACK'])->assertOk();

        $fresh = $attempt->fresh();
        $this->assertSame('cancelled', $fresh->status);
        $this->assertStringContainsString('Facebook bu sayfaya hiçbir sonuç iletmedi', $fresh->details['message']);
    }

    public function test_a_report_the_attempt_no_longer_takes_is_refused_with_the_reason(): void
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);
        $attempt->update(['status' => 'queued']);

        // Along-the-way entries are still kept while the connection completes; an ending is not.
        $this->browser()->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'SDK_CALLBACK', 'note' => 'durum connected · kod var'])->assertOk();
        $this->browser()->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'POPUP_CLOSED'])
            ->assertStatus(409)->assertJson(['saved' => false])->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'yeniden başlatın'));
        $this->assertSame('queued', $attempt->fresh()->status);

        $attempt->update(['status' => 'expired']);
        $this->browser()->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'LAUNCHED'])->assertStatus(409);
        $this->assertNull($attempt->fresh()->launched_at);
        $this->assertSame(['SDK_CALLBACK'], array_column($attempt->fresh()->trace, 'event'));
    }

    public function test_the_connect_page_from_another_session_goes_back_to_the_whatsapp_screen_with_the_reason(): void
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);
        $otherBrowser = $this->actingAs($this->admin)->withCredentials()->withCookie((string) config('session.cookie'), Str::random(40));

        $otherBrowser->get(route('operator.whatsapp.connect', $attempt->id))
            ->assertRedirect(route('operator.whatsapp'))
            ->assertSessionHas('whatsapp_notice', fn (string $notice): bool => str_contains($notice, 'başka bir oturumda'));
        $otherBrowser->postJson(route('operator.whatsapp.complete', $attempt->id), ['code' => 'meta-code', 'event' => 'CODE_ONLY'])
            ->assertForbidden()->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'başka bir oturumda'));
        $this->assertSame('prepared', $attempt->fresh()->status);
        Livewire::test(Inbox::class)->assertSee('Facebook penceresi açılmadı')->assertDontSee(route('operator.whatsapp.connect', $attempt->id));
    }

    public function test_the_connect_page_gets_json_when_the_operator_is_signed_out(): void
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);
        auth()->logout();

        $this->postJson(route('operator.whatsapp.report', $attempt->id), ['event' => 'LAUNCHED'])->assertUnauthorized();
        $this->postJson(route('operator.whatsapp.complete', $attempt->id), ['code' => 'meta-code', 'event' => 'CODE_ONLY'])->assertUnauthorized();
    }

    public function test_reset_removes_the_meta_app_number_secrets_attempts_and_messages_but_keeps_the_reply_settings(): void
    {
        $this->bindManually();
        $integration = app(WhatsAppConnection::class)->integration();
        $integration->update(['config' => [...$integration->config, 'ai_model' => 'gpt-5-mini', 'business_context' => 'Web sitesi paketi 20.000 TL.',
            'connected_via' => 'embedded_signup', 'coexistence_state' => 'signup_reported', 'webhook_verified_at' => now()->toIso8601String()]]);
        app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId)->update(['status' => 'cancelled']);
        $conversation = WhatsAppConversation::query()->create([
            'integration_id' => $integration->id, 'phone_number_id' => '222222222', 'contact_id' => '905009998877',
            'contact_name' => 'Mehmet Bey', 'last_message_at' => now(), 'revision' => 1,
        ]);
        WhatsAppMessage::query()->create([
            'conversation_id' => $conversation->id, 'message_id' => 'wamid.1', 'direction' => 'incoming', 'message_type' => 'text',
            'body' => 'Merhaba', 'sent_at' => now(),
        ]);
        WhatsAppWebhookReceipt::query()->create(['integration_id' => $integration->id, 'payload_hash' => str_repeat('c', 64), 'status' => 'completed']);
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)
            ->set('showSettings', true)
            ->assertSee('Bağlantıyı sıfırla')
            ->assertSee('1 görüşme ve mesajları da silinir')
            ->call('resetConnection')
            ->assertHasNoErrors()
            ->assertSee('WhatsApp bağlantısı sıfırlandı')
            ->assertSee('WhatsApp numaranızı bağlayın')
            ->assertSet('app_id', '')
            ->assertSet('waba_id', '');

        $integration = app(WhatsAppConnection::class)->integration();
        $this->assertSame('setup', app(WhatsAppConnection::class)->state($integration));
        $this->assertSame(['access_token' => false, 'app_secret' => false, 'verify_token' => false], app(WhatsAppConnection::class)->credentialStatus($integration));
        $this->assertSame('gpt-5-mini', $integration->config['ai_model']);
        $this->assertSame('Web sitesi paketi 20.000 TL.', $integration->config['business_context']);
        foreach (['waba_id', 'phone_number_id', 'business_phone', 'connected_via', 'coexistence_state', 'webhook_verified_at', 'subscription_state', 'last_signup_id'] as $key) {
            $this->assertArrayNotHasKey($key, $integration->config);
        }
        $this->assertSame(0, WhatsAppSignupAttempt::query()->count());
        $this->assertSame(0, WhatsAppConversation::query()->count());
        $this->assertSame(0, WhatsAppMessage::query()->count());
        $this->assertSame(0, WhatsAppWebhookReceipt::query()->count());

        // An old configuration id left in the environment is not offered again.
        config(['whatsapp.signup_config_id' => '1757572378897162']);
        Livewire::test(Inbox::class)->assertSet('signup_config_id', '')->assertSee('Meta uygulama bilgileri');
    }

    public function test_reset_waits_while_a_connection_is_being_completed(): void
    {
        $this->queuedAttempt(['access_token' => 'business-token'])->update(['status' => 'running']);
        $this->actingAs($this->admin);

        Livewire::test(Inbox::class)->call('resetConnection')->assertHasErrors('connection')->assertSee('arka planda tamamlanıyor');

        $this->assertSame('123456', app(WhatsAppConnection::class)->integration()->config['app_id']);
        $this->assertSame(1, WhatsAppSignupAttempt::query()->count());
    }

    /** The operator's browser: signed in, on the session the attempt was started from. */
    private function browser(): self
    {
        return $this->actingAs($this->admin)->withCredentials()->withCookie((string) config('session.cookie'), $this->sessionId);
    }

    /** @param  array<string, mixed>  $payload */
    private function queuedAttempt(array $payload): WhatsAppSignupAttempt
    {
        $attempt = app(WhatsAppSignup::class)->begin($this->admin, $this->sessionId);
        $attempt->update(['status' => 'queued', 'step' => 'verify_token', 'payload' => $payload]);

        return $attempt;
    }

    private function bindManually(): void
    {
        Http::fake();
        app(WhatsAppConnection::class)->save($this->admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905551112233',
            'access_token' => 'test-access-token', 'app_secret' => '', 'verify_token' => '', 'enabled' => true,
        ]);
    }

    private function fakeGraph(): void
    {
        Http::fake(fn (Request $request) => $this->graphResponse($request));
    }

    private function graphResponse(Request $request): mixed
    {
        $url = $request->url();

        return match (true) {
            str_contains($url, '/debug_token') => Http::response(['data' => [
                'is_valid' => true, 'app_id' => '123456', 'expires_at' => 0,
                'scopes' => ['whatsapp_business_management', 'whatsapp_business_messaging'],
                'granular_scopes' => [['scope' => 'whatsapp_business_management', 'target_ids' => ['555555']]],
            ]]),
            str_contains($url, '555555/phone_numbers') => Http::response(['data' => [['id' => '666666', 'display_phone_number' => '+90 532 111 22 33', 'verified_name' => 'Moximu']]]),
            str_contains($url, '555555/subscribed_apps') => $request->method() === 'POST'
                ? Http::response(['success' => true])
                : Http::response(['data' => [['whatsapp_business_api_data' => ['id' => '123456']]]]),
            str_contains($url, '666666/smb_app_data') => Http::response(['messaging_product' => 'whatsapp', 'request_id' => 'req-1']),
            str_contains($url, '/666666?') => Http::response(['id' => '666666', 'platform_type' => 'CLOUD_API']),
            default => Http::response(['error' => ['message' => 'unexpected '.$url]], 500),
        };
    }
}
