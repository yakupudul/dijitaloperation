<?php

namespace Tests\Feature\Work;

use App\Enums\CustomerStatus;
use App\Livewire\Operator\Work\AlertsPage;
use App\Models\AssetAlert;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class AlertsPageTest extends TestCase
{
    use RefreshDatabase;

    private DigitalAsset $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($user);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id, 'name' => 'Atlas Dental']);
        $this->site = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'name' => 'Atlas Site', 'domain' => 'atlasdis.com']);
    }

    public function test_page_lists_open_alerts_by_severity_and_snoozes_them(): void
    {
        $critical = $this->alert('conversions_stopped', 'critical', 'Dönüşüm gelmiyor');
        $this->alert('traffic_drop', 'medium', 'Trafik düştü');
        $this->alert('stale_data', 'low', 'Eski veri', now()->subDay());

        $this->get(route('operator.alerts'))->assertOk()->assertSee('Uyarılar');
        $page = Livewire::test(AlertsPage::class)
            ->assertSee('Dönüşüm gelmiyor')->assertSeeInOrder(['Dönüşüm gelmiyor', 'Trafik düştü'])->assertDontSee('Eski veri')
            ->set('severity', 'medium')->assertSee('Trafik düştü')->assertDontSee('Dönüşüm gelmiyor')
            ->set('severity', '')->call('snooze', $critical->id, 7)->assertSee('7 gün sessize alındı')->assertDontSee('snooze('.$critical->id.', 1)', false);

        $this->assertSame(now()->addDays(7)->toDateString(), $critical->fresh()->snoozed_until->toDateString());
        $this->assertSame(0, AssetAlert::query()->active()->where('id', $critical->id)->count());
        $page->set('show', 'snoozed');
        $page->assertSee('Dönüşüm gelmiyor')->call('unsnooze', $critical->id);
        $this->assertNull($critical->fresh()->snoozed_until);

        $page->set('show', 'resolved')->assertSee('Eski veri');
        $this->travel(8)->days();
        $critical->forceFill(['snoozed_until' => now()->subDay()])->save();
        $this->assertSame(1, AssetAlert::query()->active()->where('id', $critical->id)->count(), 'comes back after the date');
    }

    public function test_only_allowed_snooze_lengths(): void
    {
        $alert = $this->alert('traffic_drop', 'high', 'Trafik düştü');

        Livewire::test(AlertsPage::class)->call('snooze', $alert->id, 365)->assertStatus(422);
        $this->assertNull($alert->fresh()->snoozed_until);
    }

    private function alert(string $kind, string $severity, string $title, mixed $resolvedAt = null): AssetAlert
    {
        return AssetAlert::query()->create(['digital_asset_id' => $this->site->id, 'brand_id' => $this->site->brand_id, 'alert_key' => hash('sha256', $kind), 'kind' => $kind,
            'severity' => $severity, 'title' => $title, 'message' => 'm', 'first_detected_at' => now()->subDays(2), 'last_detected_at' => now(), 'resolved_at' => $resolvedAt]);
    }
}
