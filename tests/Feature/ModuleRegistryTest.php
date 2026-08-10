<?php

namespace Tests\Feature;

use App\Filament\App\Resources\Modules\Pages\EditModule;
use App\Filament\App\Resources\Modules\Pages\ListModules;
use App\Models\ModuleRegistry;
use App\Models\User;
use App\Support\Modules\ModuleCatalog;
use App\Support\Roles;
use Database\Seeders\ModuleRegistrySeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class ModuleRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_registries_table_exists_after_migration(): void
    {
        $this->assertTrue(Schema::hasTable('module_registries'));
        $this->assertTrue(Schema::hasColumns('module_registries', [
            'id',
            'module_id',
            'enabled',
            'installed_version',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_toggling_enabled_on_model_persists(): void
    {
        $module = ModuleRegistry::query()->create([
            'module_id' => 'sample-module',
            'enabled' => true,
            'installed_version' => '1.0',
        ]);

        $this->assertTrue($module->enabled);
        $this->assertTrue(ModuleRegistry::isEnabled('sample-module'));

        $module->update(['enabled' => false]);

        $this->assertFalse($module->fresh()->enabled);
        $this->assertFalse(ModuleRegistry::isEnabled('sample-module'));
        $this->assertDatabaseHas('module_registries', [
            'module_id' => 'sample-module',
            'enabled' => false,
        ]);
    }

    public function test_seeder_registers_sample_module_from_app_modules_directory(): void
    {
        $this->seed(ModuleRegistrySeeder::class);

        $this->assertDatabaseHas('module_registries', [
            'module_id' => 'sample-module',
            'enabled' => true,
        ]);

        $module = ModuleRegistry::query()->where('module_id', 'sample-module')->firstOrFail();

        $this->assertSame('1.0', $module->installed_version);
        $this->assertTrue(
            ModuleRegistry::query()->enabled()->where('module_id', 'sample-module')->exists()
        );
        $this->assertTrue(ModuleCatalog::isDeveloperFixture('sample-module'));
        $this->assertFalse(
            ModuleRegistry::query()->operatorVisible()->where('module_id', 'sample-module')->exists()
        );
    }

    public function test_admin_module_registry_lists_product_modules_and_hides_sample_module(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(ModuleRegistrySeeder::class);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $this->actingAs($admin);
        Filament::setCurrentPanel('app');

        $website = ModuleRegistry::query()->where('module_id', 'website')->firstOrFail();
        $googleAds = ModuleRegistry::query()->where('module_id', 'google-ads')->firstOrFail();
        $gbp = ModuleRegistry::query()->where('module_id', 'google-business-profile')->firstOrFail();
        $metaAds = ModuleRegistry::query()->where('module_id', 'meta-ads')->firstOrFail();
        $sample = ModuleRegistry::query()->where('module_id', 'sample-module')->firstOrFail();

        Livewire::test(ListModules::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$website, $googleAds, $gbp, $metaAds])
            ->assertCanNotSeeTableRecords([$sample])
            ->assertSee('website')
            ->assertSee('google-ads')
            ->assertSee('google-business-profile')
            ->assertSee('meta-ads')
            ->assertDontSee('sample-module');
    }

    public function test_admin_can_toggle_enabled_via_filament_edit_and_it_persists(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $module = ModuleRegistry::query()->create([
            'module_id' => 'website',
            'enabled' => true,
            'installed_version' => '1.0',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $this->actingAs($admin);
        Filament::setCurrentPanel('app');

        Livewire::test(EditModule::class, [
            'record' => $module->getRouteKey(),
        ])
            ->assertOk()
            ->fillForm([
                'enabled' => false,
                'installed_version' => '1.0',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('module_registries', [
            'module_id' => 'website',
            'enabled' => false,
        ]);
    }

    public function test_admin_can_toggle_enabled_via_list_toggle_column(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $module = ModuleRegistry::query()->create([
            'module_id' => 'website',
            'enabled' => true,
            'installed_version' => '1.0',
        ]);

        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        $this->actingAs($admin);
        Filament::setCurrentPanel('app');

        Livewire::test(ListModules::class)
            ->assertOk()
            ->call('updateTableColumnState', 'enabled', $module->getKey(), false);

        $this->assertDatabaseHas('module_registries', [
            'module_id' => 'website',
            'enabled' => false,
        ]);
    }

    public function test_team_member_cannot_access_modules_resource(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $this->seed(ModuleRegistrySeeder::class);

        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);

        $this->actingAs($member);
        Filament::setCurrentPanel('app');

        Livewire::test(ListModules::class)
            ->assertForbidden();
    }
}
