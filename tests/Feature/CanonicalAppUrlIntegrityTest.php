<?php

namespace Tests\Feature;

use App\Livewire\Demo\Dashboard;
use App\Livewire\Demo\Files\FilesIndex;
use App\Livewire\Demo\Integrations\SiteConnectorsIndex;
use App\Livewire\Demo\LocaleSwitcher;
use App\Livewire\Demo\ProfilePage;
use App\Models\User;
use App\Support\Demo\DemoMenu;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CanonicalAppUrlIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['locale' => 'en']);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        Filament::setCurrentPanel('app');
    }

    public function test_demo_menu_routes_stay_on_the_operator_origin_and_exclude_system_admin(): void
    {
        foreach (DemoMenu::groups() as $group) {
            foreach ($group['items'] as $item) {
                $url = route($item['route']);
                $path = parse_url($url, PHP_URL_PATH) ?: '/';
                $this->assertDoesNotMatchRegularExpression('#^/(app|system)(/|$)#', $path, $url);
                $this->assertStringNotContainsString('/admin', $path);
            }
        }

        $this->assertTrue(collect(DemoMenu::groups())->pluck('items')->flatten(1)->contains(
            fn (array $item): bool => ($item['route'] ?? '') === 'operator.files'
        ));
    }

    public function test_representative_app_surfaces_do_not_emit_system_links(): void
    {
        Livewire::test(Dashboard::class)
            ->assertOk()
            ->assertDontSee('/system')
            ->assertDontSee('/admin');

        Livewire::test(FilesIndex::class)->assertOk()->assertDontSee('/system');
        Livewire::test(SiteConnectorsIndex::class)->assertOk()->assertDontSee('/system');
        Livewire::test(ProfilePage::class)->assertOk()->assertDontSee('/system');

        $this->get('/settings?section=ai')->assertOk()
            ->assertDontSee('href="/system')
            ->assertDontSee("href='/system");
    }

    public function test_filament_home_points_to_canonical_app_and_demo_ui_avoids_system_links(): void
    {
        $panel = Filament::getPanel('app');
        $this->assertSame('/', $panel->getHomeUrl());

        // Technical Filament routes may still exist under /admin for admin tooling,
        // but the operator product must not emit those links.
        $this->get('/settings')->assertOk()
            ->assertDontSee('href="/system')
            ->assertDontSee("href='/system");

        $this->get('/')->assertOk()
            ->assertDontSee('href="/system')
            ->assertDontSee('href="/admin');
    }

    public function test_locale_switcher_persists_turkish(): void
    {
        Livewire::test(LocaleSwitcher::class)
            ->call('setLocale', 'tr');

        $this->admin->refresh();
        $this->assertSame('tr', $this->admin->locale);
    }

    public function test_instagram_and_profile_and_files_routes_smoke(): void
    {
        $this->get('/assets/instagram')->assertNotFound();
        $this->get('/profile')->assertOk();
        $this->get('/files')->assertOk();
        $this->get('/integrations/site-connectors')->assertOk();
        $this->get('/integrations/site-connectors/wordpress')->assertOk();
    }
}
