<?php

namespace Tests\Feature;

use App\Livewire\Demo\ProfilePage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/** Panel audit D4 (2026-10-21): an open session alone cannot change the e-mail or the password. */
final class ProfileSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_email_and_password_changes_need_the_current_password_and_rotate_the_remember_token(): void
    {
        $user = User::factory()->create(['email' => 'eski@moximu.com', 'password' => 'Eski-Sifre-2026!', 'is_active' => true, 'remember_token' => 'old-remember']);
        $this->actingAs($user);

        Livewire::test(ProfilePage::class)->set('email', 'yeni@moximu.com')->call('save')->assertHasErrors(['current_password' => 'required']);
        Livewire::test(ProfilePage::class)->set('email', 'yeni@moximu.com')->set('current_password', 'yanlis')->call('save')->assertHasErrors('current_password');
        $this->assertSame('eski@moximu.com', $user->fresh()->email);

        // Name only: no password asked.
        Livewire::test(ProfilePage::class)->set('name', 'Yakup U.')->call('save')->assertHasNoErrors();

        Livewire::test(ProfilePage::class)
            ->set('password', 'Yeni-Sifre-2026!')->set('password_confirmation', 'Yeni-Sifre-2026!')->set('current_password', 'Eski-Sifre-2026!')
            ->call('save')->assertHasNoErrors()->assertSet('current_password', '');
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('Yeni-Sifre-2026!', $fresh->password));
        $this->assertNotSame('old-remember', $fresh->remember_token);
    }
}
