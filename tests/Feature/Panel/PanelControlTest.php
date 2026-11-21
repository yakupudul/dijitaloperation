<?php

namespace Tests\Feature\Panel;

use App\Livewire\Operator\Settings\PanelUsersPage;
use App\Livewire\Panel\HomePage;
use App\Livewire\Panel\LeadsPage;
use App\Livewire\Panel\UsersPage;
use App\Models\Reminder;
use App\Models\User;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelNotification;
use App\Panel\Models\PanelReminder;
use App\Panel\Services\PanelWatch;
use App\Panel\Support\PanelContext;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/** Sürüm 2: permissions for one user alone, closing a user with a handover, the agency's switches, the early warnings. */
class PanelControlTest extends PanelTestCase
{
    private function admin(): User
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);

        return $admin;
    }

    public function test_the_manager_opens_and_closes_permissions_for_one_user_alone(): void
    {
        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $other = $this->member($this->brandA, 'satis2@example.test', role: 'Satış');
        $row = PanelMembership::query()->where('panel_user_id', $sales->id)->firstOrFail();
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(UsersPage::class)->call('editPermissions', $row->id)
            ->assertSet('boxes.leads__phone', true)->assertSet('boxes.menu__performance', false)
            ->set('boxes.leads__phone', false)->set('boxes.menu__performance', true)->call('savePermissions');
        $row->refresh();
        $this->assertSame(['allow' => ['menu.performance'], 'deny' => ['leads.phone']], $row->overrides);
        $this->assertTrue($row->allows('menu.performance') && ! $row->allows('leads.phone'));
        $this->assertTrue(PanelMembership::query()->where('panel_user_id', $other->id)->firstOrFail()->allows('leads.phone'), 'the role itself is untouched');
        Livewire::test(UsersPage::class)->call('editPermissions', $row->id)->call('resetPermissions');
        $this->assertNull($row->fresh()->overrides);

        // Someone who only holds the Kullanıcılar screen cannot hand out permissions.
        $row->forceFill(['overrides' => ['allow' => ['menu.users'], 'deny' => []]])->save();
        $this->enter($sales, $this->brandA);
        Livewire::test(UsersPage::class)->call('editPermissions', PanelMembership::query()->where('panel_user_id', $other->id)->value('id'))->assertForbidden();
    }

    public function test_closing_a_user_hands_their_open_work_to_a_colleague(): void
    {
        $leaving = $this->member($this->brandA, 'giden@example.test', role: 'Satış');
        $staying = $this->member($this->brandA, 'kalan@example.test', role: 'Satış');
        $this->enter($this->managerA, $this->brandA);
        $this->personA->forceFill(['assigned_user_id' => $leaving->id])->save();
        PanelReminder::query()->create(['person_id' => $this->personA->id, 'panel_user_id' => $leaving->id, 'created_by' => $leaving->id, 'title' => 'Ara', 'due_at' => now()->addDay()]);
        $row = PanelMembership::query()->where('panel_user_id', $leaving->id)->firstOrFail();

        $page = Livewire::test(UsersPage::class)->call('toggle', $row->id)->assertSet('closingId', $row->id)->assertSee('1 açık aday ve 1 bekleyen hatırlatma');
        $this->assertTrue($row->fresh()->is_active, 'nothing closes before the work has an owner');
        $page->set('handTo', (string) $staying->id)->call('close')->assertSet('closingId', null);
        $this->assertFalse($row->fresh()->is_active);
        $this->assertSame($staying->id, $this->personA->fresh()->assigned_user_id);
        $this->assertSame($staying->id, PanelReminder::query()->firstOrFail()->panel_user_id);
        $this->assertTrue(PanelNotification::query()->where('panel_user_id', $staying->id)->where('title', 'like', '%size devredildi%')->exists());
    }

    public function test_the_agency_switches_a_module_off_and_makes_a_brand_read_only(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Livewire::test(PanelUsersPage::class)->call('open', $this->brandA->id)->assertSee('Bu markada neler açık')->call('toggleModule', 'inbox')->call('toggleReadOnly');
        $setting = PanelBrandSetting::query()->where('brand_id', $this->brandA->id)->firstOrFail();
        $this->assertFalse($setting->moduleOn('inbox'));
        $this->assertTrue($setting->is_read_only);

        // Even the manager loses the menu and cannot add; the other brand is as it was.
        $this->asPanel($this->managerA)->get($this->url('/gelen-kutusu'))->assertForbidden();
        $this->asPanel($this->managerA)->get($this->url('/'))->assertOk()->assertDontSee('Gelen kutusu');
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(LeadsPage::class)->assertDontSee('Aday ekle')->call('add')->assertForbidden();
        $this->asPanel($this->managerA)->get($this->url('/disa-aktar/adaylar'))->assertOk();
        $this->asPanel($this->managerB)->get($this->url('/gelen-kutusu'))->assertOk();
    }

    public function test_stale_leads_idle_staff_and_silent_brands_are_told_once(): void
    {
        $admin = $this->admin();
        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $this->travelTo(now()->next('Monday')->setTime(10, 0));
        $this->managerA->forceFill(['last_login_at' => now()])->save();
        $sales->forceFill(['last_login_at' => now()->subDays(6)])->save();
        DB::table('panel_people')->where('id', $this->personA->id)->update(['last_activity_at' => now()->subDays(20)]);
        DB::table('panel_brand_settings')->update(['created_at' => now()->subDays(40)]);

        $this->assertSame(['idle_users' => 1, 'summaries' => 2, 'idle_brands' => 1, 'silent_channels' => 0], app(PanelWatch::class)->run());
        $this->assertSame(['idle_users' => 0, 'summaries' => 0, 'idle_brands' => 0, 'silent_channels' => 0], app(PanelWatch::class)->run(), 'the same warning is not repeated the next day');
        $this->assertSame([$this->brandB->id], app(PanelWatch::class)->idleBrands());
        $this->assertTrue(Reminder::query()->where('user_id', $admin->id)->where('title', 'like', '%Begonya%giren yok%')->exists());

        $this->enter($this->managerA, $this->brandA);
        $this->assertTrue(PanelNotification::query()->where('panel_user_id', $this->managerA->id)->where('type', 'idle_user')->where('title', 'like', 'Satis%')->exists());
        $this->assertTrue(PanelNotification::query()->where('panel_user_id', $this->managerA->id)->where('type', 'weekly')->where('title', 'like', '%14 gündür dokunulmadı%')->exists());
        Livewire::test(HomePage::class)->assertSee('1 aday 14 gündür aranmadı');
        Livewire::test(LeadsPage::class)->set('stale', true)->assertSee('Ayşe Akasya')->assertSee('Hepsini göster');
        app(PanelContext::class)->leave();
        $this->actingAs($admin);
        Livewire::test(PanelUsersPage::class)->assertSee('14 gündür giren yok');
    }
}
