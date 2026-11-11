<?php

namespace Tests\Feature;

use App\Livewire\Operator\Settings\SettingChangesPage;
use App\Models\AgencySetting;
use App\Models\Brand;
use App\Models\SettingChange;
use App\Models\User;
use App\Services\Operations\ScheduledJobs;
use App\Services\Sales\StandingRules;
use App\Support\Roles;
use App\Support\WriteLevels;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\TestCase;

/** Ayar değişiklik kaydı (Bağlantı Düzeni 2.5): a changed setting is on the record and one tap puts it back. */
final class SettingChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_changed_setting_is_recorded_and_undone(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup']);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        Cache::flush();

        $settings = AgencySetting::query()->first() ?? AgencySetting::query()->create(['agency_name' => 'Moximu']);
        $settings->update(['agency_name' => 'Moximu Dijital', 'mail_password' => 'gizli-sifre']);
        $brand = Brand::factory()->create(['name' => 'Örnek Klinik']);
        WriteLevels::set($brand, 'gbp', 'oku');
        app(ScheduledJobs::class)->set('moxdop:social:inbox', false);
        $prices = StandingRules::prices();
        StandingRules::savePrices("Yeni liste\nKlinik SEO 15.000 TL");

        $rows = SettingChange::query()->orderBy('id')->get()->keyBy('target');
        $this->assertSame(['agency:agency_name', 'agency:mail_password', 'level:'.$brand->id.':gbp', 'job:moxdop:social:inbox', 'sales:prices'], $rows->keys()->all());
        $this->assertTrue($rows['agency:mail_password']->secret);
        $this->assertNull($rows['agency:mail_password']->after, 'a secret is never kept');
        $this->assertStringNotContainsString('Moximu Dijital', (string) $rows['agency:agency_name']->getRawOriginal('after'), 'values are stored encrypted');

        $page = Livewire::test(SettingChangesPage::class)->assertSee('Ajans adı')->assertSee('Moximu Dijital')->assertSee('Örnek Klinik, İşletme Profili yetkisi')
            ->assertSee('Yalnız oku')->assertSee('Yakup')->assertDontSee('gizli-sifre');
        foreach (['agency:agency_name', 'level:'.$brand->id.':gbp', 'job:moxdop:social:inbox', 'sales:prices'] as $target) {
            $page->call('undo', $rows[$target]->id)->assertSee('eski değerine döndü');
        }
        $this->assertSame('Moximu', AgencySetting::query()->value('agency_name'));
        $this->assertNull($brand->fresh()->write_levels);
        $this->assertSame([], ScheduledJobs::off());
        $this->assertSame($prices, StandingRules::prices());
        $this->assertNotNull($rows['sales:prices']->fresh()->undone_at);
        $page->call('undo', $rows['agency:mail_password']->id)->assertSee('Geri alınamadı');
    }
}
