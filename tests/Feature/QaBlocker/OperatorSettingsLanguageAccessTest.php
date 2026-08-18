<?php

namespace Tests\Feature\QaBlocker;

use App\Livewire\Demo\Portfolio\CustomerCreate;
use App\Livewire\Demo\SettingsPage;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\ReportSnapshot;
use App\Models\User;
use App\Support\Demo\DemoState;
use App\Support\Operator\AgencySettingCatalog;
use App\Support\Operator\OperatorMailStatus;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class OperatorSettingsLanguageAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create([
            'name' => 'Office QA Admin',
            'email' => 'qa-admin@example.test',
            'locale' => 'en',
        ]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    public function test_general_settings_persist_in_database_across_session(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('agency_name', 'Northwind Agency')
            ->set('portal_name', 'Northwind Portal')
            ->set('default_locale', 'tr')
            ->set('default_timezone', 'Europe/Istanbul')
            ->set('default_display_currency', 'TRY')
            ->set('week_starts_on', 'monday')
            ->set('default_analytical_date_range', 'last_14')
            ->call('saveGeneral')
            ->assertHasNoErrors()
            ->assertDontSee('TempPass-should-not-appear');

        $this->assertDatabaseHas('agency_settings', [
            'agency_name' => 'Northwind Agency',
            'portal_name' => 'Northwind Portal',
            'locale' => 'tr',
            'timezone' => 'Europe/Istanbul',
            'display_currency' => 'TRY',
            'week_starts_on' => 'monday',
            'analytical_date_range' => 'last_14',
        ]);
        $this->assertSame([], DemoState::settingsOverrides());

        $this->flushSession();
        $this->actingAs($this->admin);

        $this->get('/settings')
            ->assertOk()
            ->assertSee('Northwind Agency')
            ->assertSee('Northwind Portal');

        $this->assertSame('Northwind Agency', AgencySetting::query()->first()?->agency_name);
    }

    public function test_turkish_locale_localizes_operator_chrome_without_translating_user_data(): void
    {
        $customer = Customer::factory()->create(['name' => 'Northwind Clinics']);
        Brand::factory()->create([
            'customer_id' => $customer->id,
            'name' => 'Summer Sale Campaign',
        ]);

        $this->admin->forceFill(['locale' => 'tr'])->save();

        $this->get('/')
            ->assertOk()
            ->assertSee('Kontrol Paneli')
            ->assertSee('Müşteriler')
            ->assertSee('Ayarlar')
            ->assertDontSee('Needs Attention');

        $this->get('/customers')
            ->assertOk()
            ->assertSee('Müşteriler')
            ->assertSee('Northwind Clinics')
            ->assertDontSee('Create your first customer');

        $this->get('/brands')
            ->assertOk()
            ->assertSee('Markalar')
            ->assertSee('Summer Sale Campaign');

        $this->get('/settings')
            ->assertOk()
            ->assertSee('Ayarlar')
            ->assertSee('Ekip ve Yetkiler')
            ->assertSee('Yapılandırılmadı', false);

        $this->get('/integrations')
            ->assertOk()
            ->assertSee('Entegrasyonlar')
            ->assertSee('Yapılandır');

        Livewire::test(CustomerCreate::class)
            ->assertSee('Müşteri adı')
            ->assertSee('Müşteri türü')
            ->assertSee('Sorumlu ekip');
    }

    public function test_english_locale_keeps_english_operator_chrome(): void
    {
        $this->admin->forceFill(['locale' => 'en'])->save();

        $this->get('/')
            ->assertOk()
            ->assertSee('Dashboard')
            ->assertSee('Customers')
            ->assertSee('Settings');

        $this->get('/customers')->assertOk()->assertSee('Customers');
        $this->get('/brands')->assertOk()->assertSee('Brands');
        $this->get('/settings')->assertOk()->assertSee('Team & Access');
        $this->get('/integrations')->assertOk()->assertSee('Integrations');
    }

    public function test_team_access_lists_real_users_and_admin_can_add_without_rendering_password(): void
    {
        $password = 'TempPass-002C-xyz';

        Livewire::test(SettingsPage::class)
            ->set('section', 'team')
            ->assertSee('Office QA Admin')
            ->assertDontSee('Ayşe Demir')
            ->assertDontSee('Mert Kaya')
            ->set('new_name', 'Operator Two')
            ->set('new_email', 'operator-two@example.test')
            ->set('new_role', Roles::TEAM_MEMBER)
            ->set('new_password', $password)
            ->set('new_password_confirmation', $password)
            ->set('new_is_active', true)
            ->call('addTeamMember')
            ->assertHasNoErrors()
            ->assertDontSee($password)
            ->assertSet('new_password', '')
            ->assertSee('Operator Two');

        $created = User::query()->where('email', 'operator-two@example.test')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->hasRole(Roles::TEAM_MEMBER));
        $this->assertTrue($created->is_active);
        $this->assertNotSame($password, $created->password);
    }

    public function test_unauthorized_operator_cannot_change_settings_or_team(): void
    {
        $member = User::factory()->create(['name' => 'Team Operator']);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(SettingsPage::class)
            ->set('agency_name', 'Hijacked Agency')
            ->call('saveGeneral')
            ->assertForbidden();

        Livewire::test(SettingsPage::class)
            ->set('new_name', 'Escalated')
            ->set('new_email', 'escalated@example.test')
            ->set('new_role', Roles::ADMIN)
            ->set('new_password', 'TempPass-002C-esc')
            ->set('new_password_confirmation', 'TempPass-002C-esc')
            ->call('addTeamMember')
            ->assertForbidden();

        $this->assertDatabaseMissing('users', ['email' => 'escalated@example.test']);
        $this->assertDatabaseMissing('agency_settings', ['agency_name' => 'Hijacked Agency']);
    }

    public function test_deactivation_preserves_user_and_customer_responsibility_and_protects_last_admin(): void
    {
        $member = User::factory()->create(['name' => 'Responsible Operator', 'email' => 'responsible@example.test']);
        $member->assignRole(Roles::TEAM_MEMBER);

        $customer = Customer::factory()->create(['name' => 'Kept Customer']);
        $customer->responsibleUsers()->sync([$member->id]);

        Livewire::test(SettingsPage::class)
            ->call('deactivateUser', $member->id)
            ->assertHasNoErrors();

        $member->refresh();
        $this->assertFalse($member->is_active);
        $this->assertDatabaseHas('users', ['id' => $member->id, 'email' => 'responsible@example.test']);
        $this->assertTrue($customer->responsibleUsers()->where('users.id', $member->id)->exists());

        Livewire::test(SettingsPage::class)
            ->call('deactivateUser', $this->admin->id)
            ->assertHasErrors(['user']);

        $this->admin->refresh();
        $this->assertTrue($this->admin->is_active);
    }

    public function test_white_label_branding_persists_rejects_invalid_files_and_does_not_rewrite_snapshots(): void
    {
        Storage::fake('public');

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $snapshot = ReportSnapshot::query()->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'report_type' => 'client_value_story',
            'period_start' => now()->subDays(30)->toDateString(),
            'period_end' => now()->toDateString(),
            'title_snapshot' => 'Frozen Snapshot',
            'customer_name_snapshot' => $customer->name,
            'brand_name_snapshot' => $brand->name,
            'locale' => 'en',
            'reporting_timezone' => 'UTC',
            'snapshot_schema_version' => 'client_value_story_v1',
            'source_manifest_fingerprint' => hash('sha256', 'manifest'),
            'content_checksum' => hash('sha256', 'frozen-content'),
            'content_payload' => ['heading' => 'Historical value'],
            'source_manifest_payload' => ['sources' => ['a']],
            'generated_by' => $this->admin->id,
            'generated_at' => now(),
            'created_at' => now(),
            'idempotency_key' => 'qa-002c-snapshot',
        ]);

        Livewire::test(SettingsPage::class)
            ->set('agency_name', '<script>alert(1)</script>')
            ->set('portal_name', 'Safe Portal')
            ->set('default_locale', 'en')
            ->set('default_timezone', 'Europe/London')
            ->set('default_display_currency', 'GBP')
            ->set('week_starts_on', 'sunday')
            ->set('default_analytical_date_range', 'last_7')
            ->set('logo', UploadedFile::fake()->create('evil.php', 20, 'application/x-php'))
            ->call('saveGeneral')
            ->assertHasErrors(['logo']);

        Livewire::test(SettingsPage::class)
            ->set('agency_name', '<script>alert(1)</script>')
            ->set('portal_name', 'Safe Portal')
            ->set('default_locale', 'en')
            ->set('default_timezone', 'Europe/London')
            ->set('default_display_currency', 'GBP')
            ->set('week_starts_on', 'sunday')
            ->set('default_analytical_date_range', 'last_7')
            ->call('saveGeneral')
            ->assertHasNoErrors();

        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringContainsString('Safe Portal', $html);

        $snapshot->refresh();
        $this->assertSame('Frozen Snapshot', $snapshot->title_snapshot);
        $this->assertSame(hash('sha256', 'frozen-content'), $snapshot->content_checksum);
        $this->assertSame(['heading' => 'Historical value'], $snapshot->content_payload);
    }

    public function test_settings_payload_does_not_expose_deployment_or_provider_secrets(): void
    {
        config([
            'database.connections.sqlite.password' => 'db-secret-must-not-leak',
            'database.redis.default.password' => 'redis-secret-must-not-leak',
            'mail.mailers.smtp.password' => 'smtp-secret-must-not-leak',
        ]);

        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString('smtp-secret-must-not-leak', $html);
        $this->assertStringNotContainsString('db-secret-must-not-leak', $html);
        $this->assertStringNotContainsString('redis-secret-must-not-leak', $html);
    }

    public function test_mail_status_does_not_claim_production_smtp_for_log_or_array_mailers(): void
    {
        config(['mail.default' => 'array']);
        $this->assertSame(OperatorMailStatus::NOT_CONFIGURED, OperatorMailStatus::state());

        Livewire::test(SettingsPage::class)
            ->assertSee(__('operator.mail.not_configured'))
            ->assertDontSee('Email delivery active')
            ->assertDontSee(__('operator.mail.configured_deployment'));
    }

    public function test_production_routes_do_not_use_demo_names(): void
    {
        $demoNames = collect(app('router')->getRoutes())
            ->map(static fn ($route): ?string => $route->getName())
            ->filter(static fn (?string $name): bool => is_string($name) && str_starts_with($name, 'demo.'))
            ->values()
            ->all();

        $this->assertSame([], $demoNames);
    }

    public function test_controlled_settings_reject_arbitrary_values(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('agency_name', 'Valid Agency')
            ->set('portal_name', 'Valid Portal')
            ->set('default_locale', 'fr')
            ->set('default_timezone', 'Not/AZone')
            ->set('default_display_currency', 'XXX')
            ->set('week_starts_on', 'friday')
            ->set('default_analytical_date_range', 'last_365')
            ->call('saveGeneral')
            ->assertHasErrors([
                'default_locale',
                'default_timezone',
                'default_display_currency',
                'week_starts_on',
                'default_analytical_date_range',
            ]);

        $this->assertTrue(AgencySettingCatalog::isLocale('tr'));
        $this->assertFalse(AgencySettingCatalog::isLocale('fr'));
    }

    public function test_inactive_operator_cannot_sign_in(): void
    {
        $member = User::factory()->create([
            'email' => 'inactive@example.test',
            'password' => 'password',
            'is_active' => false,
        ]);
        $member->assignRole(Roles::TEAM_MEMBER);

        auth()->logout();
        $this->flushSession();

        $this->post(route('app.login.store'), [
            'email' => 'inactive@example.test',
            'password' => 'password',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }
}
