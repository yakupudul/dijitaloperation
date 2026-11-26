<?php

namespace Tests\Feature\Panel;

use App\Livewire\Panel\InboxPage;
use App\Models\User;
use App\Panel\Models\PanelChannel;
use App\Panel\Models\PanelNotification;
use App\Panel\Models\PanelTicket;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** "Bağla": the brand's manager connects the brand's own channels from Gelen kutusu, without asking the agency. */
class PanelChannelLinkTest extends PanelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $connection = app(WhatsAppConnection::class);
        $connection->save($admin, [
            'waba_id' => '111111111', 'phone_number_id' => '222222222', 'business_phone' => '905550000000', 'business_context' => 'Ajans',
            'access_token' => 'agency-token', 'app_secret' => 'app-secret-for-tests', 'verify_token' => 'verify-me-1234567890',
            'enabled' => true, 'automatic_suggestions' => false,
        ]);
        $row = $connection->integration();
        $row->update(['config' => array_merge((array) $row->config, ['app_id' => '1376608000853003', 'signup_config_id' => '1757572378897162'])]);
    }

    private function debugToken(array $scopes, array $granular = []): array
    {
        return ['data' => ['is_valid' => true, 'app_id' => '1376608000853003', 'scopes' => $scopes, 'granular_scopes' => $granular]];
    }

    public function test_the_manager_sees_connect_and_never_a_request_button_and_a_salesperson_sees_neither(): void
    {
        $this->enter($this->managerA, $this->brandA);
        foreach (['whatsapp', 'instagram', 'facebook', 'meta_form'] as $channel) {
            Livewire::test(InboxPage::class)->set('channel', $channel)->assertSee('bağlı değil')->assertSeeHtml('data-panel-connect')->assertDontSee('Bağlantı iste');
        }
        Livewire::test(InboxPage::class)->set('channel', 'web_form')->assertSeeHtml('data-panel-form-link')->assertSee('/api/f/')->assertDontSee('Bağlantı iste');

        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $this->enter($sales, $this->brandA);
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->assertDontSeeHtml('data-panel-connect')->assertSee('markanızın yöneticisine')
            ->call('linkWhatsApp', ['code' => 'x'])->assertForbidden();
        $this->assertSame(0, PanelTicket::query()->count());
    }

    public function test_whatsapp_connects_with_the_code_from_meta_and_subscribes_the_app(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'client-business-token']),
            'graph.facebook.com/*/debug_token*' => Http::response($this->debugToken(['whatsapp_business_management', 'whatsapp_business_messaging'])),
            'graph.facebook.com/*/333000333/phone_numbers*' => Http::response(['data' => [['id' => '444000444', 'display_phone_number' => '+90 532 000 11 22', 'verified_name' => 'Akasya Klinik']]]),
            'graph.facebook.com/*/333000333/subscribed_apps*' => Http::response(['success' => true]),
        ]);
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')
            ->call('linkWhatsApp', ['code' => 'meta-code', 'waba_id' => '333000333', 'phone_number_id' => '444000444'])
            ->assertDispatched('operator-notice', message: 'WhatsApp bağlandı: +90 532 000 11 22 · Akasya Klinik. Yeni gelenler buraya düşecek.')
            ->assertSee('WhatsApp bağlı')->assertSee('Numarayı değiştir');
        $channel = PanelChannel::query()->where('channel', 'whatsapp')->firstOrFail();
        $this->assertSame(['444000444', '333000333', 'client-business-token', 'panel'], [$channel->external_id, $channel->account_id, $channel->token, $channel->meta['via']]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_contains($request->url(), '333000333/subscribed_apps'));

        // Brand B can neither see it nor bind the same number.
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->assertDontSee('Akasya Klinik')
            ->call('linkWhatsApp', ['code' => 'meta-code', 'waba_id' => '333000333', 'phone_number_id' => '444000444'])
            ->assertDispatched('operator-notice', tone: 'error');
        $this->assertSame(1, PanelChannel::query()->withoutGlobalScopes()->where('external_id', '444000444')->count());
    }

    public function test_several_numbers_ask_which_one_and_the_agency_number_is_refused(): void
    {
        Http::fake([
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'client-business-token']),
            'graph.facebook.com/*/debug_token*' => Http::response($this->debugToken(['whatsapp_business_management', 'whatsapp_business_messaging'], [['scope' => 'whatsapp_business_management', 'target_ids' => ['333000333']]])),
            'graph.facebook.com/*/333000333/phone_numbers*' => Http::response(['data' => [
                ['id' => '444000444', 'display_phone_number' => '+90 532 000 11 22', 'verified_name' => 'Akasya'],
                ['id' => '222222222', 'display_phone_number' => '+90 555 000 00 00', 'verified_name' => 'Moximu'],
            ]]),
            'graph.facebook.com/*/333000333/subscribed_apps*' => Http::response(['success' => true]),
        ]);
        $this->enter($this->managerA, $this->brandA);
        $page = Livewire::test(InboxPage::class)->set('channel', 'whatsapp')->call('linkWhatsApp', ['code' => 'meta-code'])
            ->assertSee('Hangi numara bağlansın?')->assertSee('+90 532 000 11 22 · Akasya');
        $page->call('chooseLink', '222222222')->assertDispatched('operator-notice', tone: 'error');
        $this->assertSame(0, PanelChannel::query()->count());
        // The pick is remembered once; after a refusal the manager starts again.
        $page->call('linkWhatsApp', ['code' => 'meta-code'])->call('chooseLink', '444000444')->assertSee('WhatsApp bağlı');
        $this->assertSame('444000444', PanelChannel::query()->firstOrFail()->external_id);
    }

    public function test_instagram_connects_from_the_facebook_login_and_a_missing_permission_is_named(): void
    {
        Http::fake([
            'graph.facebook.com/*/debug_token*' => Http::response($this->debugToken(['instagram_basic'])),
            'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'long-user-token']),
            'graph.facebook.com/*/me/permissions*' => Http::sequence()
                ->push(['data' => [['permission' => 'pages_show_list', 'status' => 'granted'], ['permission' => 'instagram_basic', 'status' => 'granted']]])
                ->push(['data' => collect(['pages_show_list', 'pages_manage_metadata', 'business_management', 'instagram_basic', 'instagram_manage_messages'])->map(fn ($p) => ['permission' => $p, 'status' => 'granted'])->all()]),
            'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
                ['id' => '10900000001', 'name' => 'Akasya Klinik', 'access_token' => 'page-token', 'instagram_business_account' => ['id' => '17840000001', 'username' => 'akasyaklinik']],
                ['id' => '10900000002', 'name' => 'Başka Sayfa', 'access_token' => 'page-token-2'],
            ]]),
            'graph.facebook.com/*/10900000001/subscribed_apps*' => Http::response(['success' => true]),
        ]);
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(InboxPage::class)->set('channel', 'instagram')->call('linkMeta', 'user-token')
            ->assertDispatched('operator-notice', tone: 'error')->assertSee('Instagram bağlı değil');
        Livewire::test(InboxPage::class)->set('channel', 'instagram')->call('linkMeta', 'user-token')->assertSee('Instagram bağlı')->assertSee('@akasyaklinik');
        $channel = PanelChannel::query()->where('channel', 'instagram')->firstOrFail();
        $this->assertSame(['17840000001', '10900000001', 'page-token'], [$channel->external_id, $channel->account_id, $channel->token]);
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '10900000001/subscribed_apps') && ($request['subscribed_fields'] ?? null) === 'messages');
    }

    public function test_the_manager_closes_a_channel_and_the_brand_hears_it_from_itself(): void
    {
        $this->enter($this->managerA, $this->brandA);
        app(\App\Panel\Services\PanelChannels::class)->connect($this->brandA->id, 'facebook', '10900000001', null, 'Akasya Klinik', 'page-token', null);
        Livewire::test(InboxPage::class)->set('channel', 'facebook')->assertSee('Facebook bağlı')->call('unlink')->assertSee('Facebook bağlı değil')->assertDontSee('bağlantısı kesildi');
        $this->assertSame(PanelChannel::DISCONNECTED, PanelChannel::query()->firstOrFail()->status);
        $this->assertTrue(PanelNotification::query()->where('title', 'like', 'Facebook bağlantısı kapatıldı%')->exists());
    }
}
