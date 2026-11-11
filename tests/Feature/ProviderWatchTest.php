<?php

namespace Tests\Feature;

use App\Livewire\Operator\Integrations\ConnectionHealthPage;
use App\Models\AgencySetting;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\User;
use App\Services\DataStatus\ProviderWatch;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/** Sağlayıcı nöbeti (Bağlantı Düzeni 4.2 / 4.5): an API version is told 30 days before the provider switches it off. */
class ProviderWatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        AgencySetting::query()->firstOrCreate([], ['agency_name' => 'Moximu']);
        Http::fake();
        $this->actingAs($admin);
        config(['moxdop.google.ads_api_version' => 'v25', 'provider-watch.google_ads' => ['v25' => '2027-08-01']]);
    }

    public function test_a_version_is_quiet_until_thirty_days_before_its_end_then_due_then_over(): void
    {
        $row = fn (): array => collect(app(ProviderWatch::class)->rows())->firstWhere('name', 'Google Ads API v25');
        CarbonImmutable::setTestNow('2026-11-11 10:00:00');
        $this->assertSame('ok', $row()['tone']);
        $this->assertSame([], array_filter(app(ProviderWatch::class)->due(), fn (array $r): bool => $r['kind'] === 'version' && str_starts_with($r['name'], 'Google Ads')));

        CarbonImmutable::setTestNow('2027-07-10 10:00:00');
        $this->assertSame(['warn', 22], [$row()['tone'], $row()['days']]);
        Livewire::test(ConnectionHealthPage::class)->set('view', 'nobet')->assertSeeHtml('data-provider-watch')->assertSee('Google Ads API v25')->assertSee('22 gün kaldı');

        CarbonImmutable::setTestNow('2027-08-02 10:00:00');
        $this->assertSame('bad', $row()['tone']);

        config(['moxdop.google.ads_api_version' => 'v99']);
        $unknown = collect(app(ProviderWatch::class)->rows())->firstWhere('name', 'Google Ads API v99');
        $this->assertSame('muted', $unknown['tone']);
        $this->assertStringContainsString('listede yok', $unknown['detail']);
        CarbonImmutable::setTestNow();
    }

    public function test_an_access_key_that_renews_itself_is_not_an_authorization_about_to_end(): void
    {
        $google = CoreIntegration::factory()->create(['provider' => 'google', 'name' => 'Google']);
        CoreIntegrationCredential::factory()->create(['integration_id' => $google->id, 'credential_type' => 'authorization',
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r'], 'expires_at' => now()->addHour()]);
        $meta = CoreIntegration::factory()->create(['provider' => 'meta', 'name' => 'Meta']);
        CoreIntegrationCredential::factory()->create(['integration_id' => $meta->id, 'credential_type' => 'authorization',
            'encrypted_payload' => ['access_token' => 'a'], 'expires_at' => now()->addDays(3)]);

        $rows = collect(app(ProviderWatch::class)->rows())->keyBy('name');
        $this->assertSame('muted', $rows['Google yetkisi']['tone']);
        $this->assertSame('warn', $rows['Meta yetkisi']['tone']);
    }
}
