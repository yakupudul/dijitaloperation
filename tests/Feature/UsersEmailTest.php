<?php

namespace Tests\Feature;

use App\Livewire\Operator\Settings\UsersPage;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Ayarlar › Kullanıcılar: an admin sets a user's real login e-mail (yakup 2026-11-11). */
class UsersEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_changes_a_login_email_and_a_member_cannot(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $other = User::factory()->create(['is_active' => true, 'email' => 'staging-admin@moxdop.local']);
        $other->assignRole(Roles::ADMIN);
        $hash = $other->password;

        $this->actingAs($admin);
        Livewire::test(UsersPage::class)->call('editEmail', $other->id)->assertSet('email', 'staging-admin@moxdop.local')
            ->set('email', $admin->email)->call('saveEmail')->assertHasErrors('email')
            ->set('email', ' Yakup@Moximu.com ')->call('saveEmail')->assertHasNoErrors()->assertSee('yakup@moximu.com');
        $this->assertSame('yakup@moximu.com', $other->fresh()->email);
        $this->assertSame($hash, $other->fresh()->password, 'the password stays');

        $member = User::factory()->create(['is_active' => true]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);
        Livewire::test(UsersPage::class)->call('editEmail', $other->id)->assertForbidden();
    }
}
