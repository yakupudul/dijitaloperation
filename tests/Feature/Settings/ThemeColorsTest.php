<?php

namespace Tests\Feature\Settings;

use App\Livewire\Demo\SettingsPage;
use App\Models\AgencySetting;
use App\Models\User;
use App\Services\Operator\AgencySettingService;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Panel colors in Ayarlar › Genel › Görünüm (yakup 2026-10-20): Moximu logo colors by default, admin can change and
 * reset them, every page head carries them as CSS variables.
 */
class ThemeColorsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        DemoState::reset();
    }

    public function test_defaults_are_the_moximu_logo_colors(): void
    {
        $theme = app(AgencySettingService::class)->theme();

        $this->assertSame('#fdcf3c', $theme['accent']);
        $this->assertSame('#1e7a78', $theme['second']);
        $this->assertSame('#141416', $theme['accent_ink']);

        $this->get(route('operator.settings'))
            ->assertOk()
            ->assertSee('--moxi-accent:#fdcf3c', false)
            ->assertSee('data-theme-settings', false)
            ->assertSee('Moximu renklerine dön');
    }

    public function test_admin_saves_colors_and_every_page_uses_them(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('theme_accent', '#2C35E5')
            ->set('theme_second', '#0e7490')
            ->call('saveTheme')
            ->assertHasNoErrors();

        $settings = AgencySetting::query()->findOrFail(1);
        $this->assertSame('#2c35e5', $settings->theme_accent);
        $this->assertSame('#0e7490', $settings->theme_second);

        app(AgencySettingService::class)->forget();
        $this->assertSame('#ffffff', app(AgencySettingService::class)->theme()['accent_ink']);

        $this->get(route('operator.dashboard'))
            ->assertOk()
            ->assertSee('--moxi-accent:#2c35e5;--moxi-accent-ink:#ffffff;--moxi-second:#0e7490', false);
    }

    public function test_invalid_color_is_refused(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('theme_accent', 'red')
            ->call('saveTheme')
            ->assertHasErrors(['theme_accent']);

        $this->assertNull(AgencySetting::query()->find(1)?->theme_accent);
    }

    public function test_reset_returns_to_moximu_colors(): void
    {
        app(AgencySettingService::class)->updateTheme('#ff7a59', '#1f2a44');

        Livewire::test(SettingsPage::class)->call('resetTheme');

        $settings = AgencySetting::query()->findOrFail(1);
        $this->assertNull($settings->theme_accent);
        $this->assertNull($settings->theme_second);
    }

    public function test_team_member_cannot_change_colors(): void
    {
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(SettingsPage::class)
            ->set('theme_accent', '#ff7a59')
            ->call('saveTheme')
            ->assertForbidden();
    }
}
