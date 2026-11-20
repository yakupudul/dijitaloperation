<?php

namespace Tests\Feature\Panel;

use App\Models\Brand;
use App\Panel\Models\AccessRole;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelUser;
use App\Panel\Services\PanelLeads;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Two brands with their own users and people: every panel test asks what one of them can see of the other. */
abstract class PanelTestCase extends TestCase
{
    use RefreshDatabase;

    protected Brand $brandA;

    protected Brand $brandB;

    protected PanelUser $managerA;

    protected PanelUser $managerB;

    protected PanelPerson $personA;

    protected PanelPerson $personB;

    protected function setUp(): void
    {
        parent::setUp();
        AccessRole::ensureDefaults();
        $this->brandA = Brand::factory()->create(['name' => 'Akasya']);
        $this->brandB = Brand::factory()->create(['name' => 'Begonya']);
        $this->managerA = $this->member($this->brandA, 'a@example.test', manager: true);
        $this->managerB = $this->member($this->brandB, 'b@example.test', manager: true);
        $this->personA = app(PanelLeads::class)->record($this->brandA->id, ['name' => 'Ayşe Akasya', 'phone' => '0532 111 22 33', 'interest' => 'Kontrol'], 'web_form');
        $this->personB = app(PanelLeads::class)->record($this->brandB->id, ['name' => 'Bora Begonya', 'phone' => '0533 444 55 66', 'interest' => 'Bilgi'], 'call');
    }

    protected function member(Brand $brand, string $email, bool $manager = false, ?string $role = null): PanelUser
    {
        PanelBrandSetting::forBrand($brand->id);
        $user = PanelUser::query()->firstOrCreate(['email' => $email], ['name' => ucfirst(strtok($email, '@')).' Kullanıcı', 'password' => 'secret-pass-1', 'is_active' => true]);
        PanelMembership::query()->create([
            'panel_user_id' => $user->id, 'brand_id' => $brand->id, 'is_manager' => $manager, 'is_active' => true,
            'access_role_id' => $role ? AccessRole::query()->where('scope', PanelPermissions::SCOPE_PANEL)->where('name', $role)->value('id') : null,
        ]);

        return $user;
    }

    protected function url(string $path = '/'): string
    {
        return 'http://'.config('panel.domain').'/'.ltrim($path, '/');
    }

    protected function asPanel(PanelUser $user): static
    {
        return $this->actingAs($user, 'client');
    }

    /** For Livewire::test: the request is not real, so the brand is entered by hand as the host wall would do. */
    protected function enter(PanelUser $user, Brand $brand): void
    {
        $this->actingAs($user, 'client');
        app(PanelContext::class)->enter(PanelMembership::query()->with('role')->where('panel_user_id', $user->id)->where('brand_id', $brand->id)->firstOrFail());
    }
}
