<?php

namespace Tests\Feature;

use App\Livewire\Operator\Integrations\AccessLinkPanel;
use App\Livewire\Operator\Integrations\ConnectionHealthPage;
use App\Models\AccessLink;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\User;
use App\Services\Access\AccessLinks;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Erişim bağlantısı (Bağlantı Düzeni 4.1): one link per brand, the steps for the customer, "Yetkiyi verdim" told to the operator. */
class AccessLinkTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        Http::fake();
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['name' => 'Örnek Klinik']);
    }

    public function test_a_link_needs_the_identity_the_customer_adds_and_keeps_its_address(): void
    {
        $panel = Livewire::test(AccessLinkPanel::class, ['brandId' => $this->brand->id]);
        $this->assertContains('google_business_profile', $panel->get('channels'), 'what the map shows as missing is ticked');
        $panel->set('channels', ['google_business_profile', 'meta_ads'])->set('google', 'ajans@example.com')->call('save')->assertSee('Meta işletme kimliği');
        $this->assertSame(0, AccessLink::query()->count());

        $panel->set('meta', '1234 5678 9012 345')->call('save')->assertSee('Bağlantı hazır')->assertSee('/erisim/');
        $link = AccessLink::query()->sole();
        $this->assertSame(['google_business_profile', 'meta_ads'], $link->asked);
        $this->assertSame('123456789012345', app(AccessLinks::class)->identities()['meta']);

        $panel->set('channels', ['google_business_profile'])->call('save');
        $this->assertSame($link->token, AccessLink::query()->sole()->token);
        $this->assertSame(['google_business_profile'], AccessLink::query()->sole()->asked);

        Livewire::test(ConnectionHealthPage::class)->set('view', 'harita')->assertSee('Müşteriden erişim iste')->set('accessBrand', $this->brand->id)->assertSeeHtml('data-access-link');
    }

    public function test_the_customer_sees_the_steps_and_marks_the_access_as_given(): void
    {
        $links = app(AccessLinks::class);
        $links->saveIdentities('ajans@example.com', '');
        $link = $links->ask($this->brand, ['search_console', 'google_business_profile'], $this->admin);

        $this->get('/erisim/'.$link->token)->assertOk()->assertSee('ajans@example.com');
        $this->assertNull($link->fresh()->opened_at, 'the operator\'s own preview is not an opening');

        auth()->logout();
        $this->get('/erisim/'.$link->token)->assertOk()->assertSee('Örnek Klinik')->assertSee('Kullanıcılar ve izinler')->assertSee('Kişiler ve erişim')->assertSee('Yetkiyi verdim');
        $this->assertNotNull($link->fresh()->opened_at);
        $this->post('/erisim/'.$link->token, ['channel' => 'google_ads'])->assertRedirect();
        $this->assertSame([], (array) $link->fresh()->done, 'a channel that was not asked cannot be marked');
        $this->post('/erisim/'.$link->token, ['channel' => 'search_console'])->assertRedirect();
        $this->assertSame(['search_console'], array_keys((array) $link->fresh()->done));
        $this->get('/erisim/'.$link->token)->assertSee('Yetkiyi verdiniz, kontrol ediyoruz');
        $this->get('/erisim/'.str_repeat('b', 48))->assertNotFound();
        $this->assertSame('marked', collect($links->items($link->fresh()))->firstWhere('channel', 'search_console')['state']);
    }
}
