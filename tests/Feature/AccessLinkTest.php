<?php

namespace Tests\Feature;

use App\Livewire\Operator\Integrations\AccessLinkPanel;
use App\Livewire\Operator\Integrations\ConnectionHealthPage;
use App\Livewire\Operator\Sales\StartLinkSettings;
use App\Models\AccessLink;
use App\Models\AgencySetting;
use App\Models\Agreement;
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
        $panel->set('channels', ['google_business_profile', 'meta_ads'])->set('google', 'ajans@example.com')->call('save')->assertSee('işletme kimliği');
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

    public function test_meta_can_be_given_to_a_person_by_e_mail_and_the_identities_save_without_a_link(): void
    {
        $panel = Livewire::test(AccessLinkPanel::class, ['brandId' => $this->brand->id]);
        $panel->set('google', 'ajans@example.com')->set('meta', 'bozuk@')->call('saveIdentities')->assertSee('e-posta adresi ya da işletme kimliği');
        $panel->set('meta', 'meta@example.com')->call('saveIdentities')->assertSee('Kaydedildi');
        $this->assertSame(0, AccessLink::query()->count());
        $this->assertSame(['google' => 'ajans@example.com', 'meta' => 'meta@example.com'], app(AccessLinks::class)->identities());

        $link = app(AccessLinks::class)->ask($this->brand, ['meta_ads'], $this->admin ?? null);
        $item = app(AccessLinks::class)->items($link)[0];
        $this->assertSame('E-posta adresi', $item['identity_label']);
        $this->assertSame('meta@example.com', $item['identity']);
        $this->assertStringContainsString('Kişi davet et', $item['steps'][1]);
    }

    public function test_the_link_starts_the_work_details_contract_and_payment_before_the_accesses(): void
    {
        $links = app(AccessLinks::class);
        $links->saveIdentities('ajans@example.com', '');
        $link = $links->ask($this->brand, ['search_console'], $this->admin);
        $this->get('/erisim/'.$link->token)->assertOk()->assertDontSee('Hizmet sözleşmesi');

        $agreement = Agreement::query()->create(['brand_id' => $this->brand->id, 'customer_id' => $this->brand->customer_id, 'kind' => 'monthly', 'status' => 'active', 'fee' => 14000, 'due_day' => 5, 'starts_on' => '2026-11-15', 'services' => ['Klinik SEO', 'İşletme Profili']]);
        Livewire::test(StartLinkSettings::class)->set('holder', 'Yakup Udul')->set('iban', 'TR12 3456')->call('save')->assertHasErrors('iban')
            ->set('iban', 'tr33 0006 1005 1978 6457 8413 26')->call('save')->assertHasNoErrors();
        $this->assertStringContainsString('tek bir sayfa', $links->message($link));

        auth()->logout();
        $url = '/erisim/'.$link->token;
        $this->get($url)->assertOk()->assertSee('çalışmaya başlıyoruz')->assertSee('14.000 TL + KDV')->assertSee('Klinik SEO, İşletme Profili')->assertSee('TR33 0006 1005')
            ->assertSee('16.800 TL')->assertSee('önce fatura bilgilerinizi')->assertDontSee('Ödemeyi yaptım');

        $this->post($url, ['step' => 'accept', 'accept' => 1])->assertRedirect();
        $this->assertNull($agreement->fresh()->contract_on, 'no acceptance without the billing details');

        $this->post($url, ['step' => 'party', 'party' => ['title' => 'Örnek Sağlık Ltd. Şti.', 'tax_office' => 'Manisa', 'tax_no' => '1234567890']])->assertRedirect();
        $this->get($url)->assertSee('Örnek Sağlık Ltd. Şti.')->assertSee('Sözleşmeyi okudum');
        $this->post($url, ['step' => 'accept', 'accept' => 1])->assertRedirect();
        $this->assertNotNull($agreement->fresh()->contract_on);
        $this->assertStringContainsString('Örnek Sağlık Ltd. Şti.', $link->fresh()->start['accepted_text']);

        $this->get($url)->assertSee('tarihinde onayladınız')->assertSee('Ödemeyi yaptım');
        $this->post($url, ['step' => 'paid'])->assertRedirect();
        $this->get($url)->assertSee('hesabı kontrol ediyoruz')->assertSee('Kullanıcılar ve izinler');
        $this->assertNotNull($link->fresh()->start['paid_at']);
    }
}
