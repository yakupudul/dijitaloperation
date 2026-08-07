<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Roles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_admin_command_creates_admin_user(): void
    {
        $this->artisan('dop:create-admin')
            ->expectsQuestion('Name', 'Agency Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'secret-password')
            ->assertSuccessful();

        $user = User::query()->where('email', 'admin@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole(Roles::ADMIN));
        $this->assertTrue(Hash::check('secret-password', $user->password));
    }
}
