<?php

namespace Tests\Feature;

use App\Http\Controllers\Auth\OperatorTwoFactorChallengeController;
use App\Livewire\Demo\ProfilePage;
use App\Models\User;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

final class OperatorTwoFactorLoginTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'JBSWY3DPEHPK3PXP';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    public function test_login_without_two_factor_signs_in_directly(): void
    {
        $user = $this->operator();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])->assertRedirect('/');

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_alone_does_not_sign_in_when_two_factor_is_on(): void
    {
        $user = $this->operator(withTwoFactor: true);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect(route('app.login.two-factor'));
        $this->assertGuest();

        $this->get(route('app.login.two-factor'))->assertOk()->assertSee(__('two_factor.challenge_title'));

        $this->post(route('app.login.two-factor.store'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post(route('app.login.two-factor.store'), ['code' => $this->currentCode()])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_a_used_code_cannot_be_replayed(): void
    {
        $user = $this->operator(withTwoFactor: true);
        $code = $this->currentCode();

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
        $this->post(route('app.login.two-factor.store'), ['code' => $code])->assertRedirect('/');
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
        $this->post(route('app.login.two-factor.store'), ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_recovery_code_works_once(): void
    {
        $user = $this->operator(withTwoFactor: true);
        app(AppAuthentication::class)->saveRecoveryCodes($user, ['alpha-code', 'beta-code']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
        $this->post(route('app.login.two-factor.store'), ['recovery_code' => 'alpha-code'])->assertRedirect('/');
        $this->assertAuthenticatedAs($user);
        $this->assertCount(1, $user->fresh()->getAppAuthenticationRecoveryCodes());
        $this->post('/logout');

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password']);
        $this->post(route('app.login.two-factor.store'), ['recovery_code' => 'alpha-code'])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_challenge_without_pending_or_expired_login_returns_to_login(): void
    {
        $user = $this->operator(withTwoFactor: true);

        $this->get(route('app.login.two-factor'))->assertRedirect(route('app.login'));

        $this->withSession([OperatorTwoFactorChallengeController::SESSION_KEY => [
            'user_id' => $user->id, 'remember' => false, 'expires_at' => now()->subMinute()->getTimestamp(),
        ]])->post(route('app.login.two-factor.store'), ['code' => $this->currentCode()])->assertRedirect(route('app.login'));
        $this->assertGuest();
    }

    public function test_operator_turns_two_factor_on_and_off_from_profile(): void
    {
        $user = $this->operator();
        $this->actingAs($user);

        $component = Livewire::test(ProfilePage::class)
            ->call('startTwoFactorSetup')
            ->assertSee(__('two_factor.setup_key'));
        $secret = (string) $component->get('twoFactorPendingSecret');

        $component->set('twoFactorCode', '123')->call('confirmTwoFactor')->assertHasErrors('twoFactorCode');
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());

        $component->set('twoFactorCode', app(Google2FA::class)->getCurrentOtp($secret))->call('confirmTwoFactor')->assertHasNoErrors();
        $this->assertSame($secret, $user->fresh()->getAppAuthenticationSecret());
        $this->assertCount(8, $component->get('twoFactorRecoveryCodes'));
        $this->assertCount(8, $user->fresh()->getAppAuthenticationRecoveryCodes());
        $this->assertNotContains($component->get('twoFactorRecoveryCodes')[0], $user->fresh()->getAppAuthenticationRecoveryCodes(), 'recovery codes are stored hashed');

        // The confirming code cannot be reused; the next 30-second step's code turns it off.
        $google2fa = app(Google2FA::class);
        Livewire::test(ProfilePage::class)
            ->set('twoFactorCode', $google2fa->oathTotp($secret, $google2fa->getTimestamp() + 1))
            ->call('disableTwoFactor')
            ->assertHasNoErrors();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
        $this->assertNull($user->fresh()->getAppAuthenticationRecoveryCodes());
    }

    private function operator(bool $withTwoFactor = false): User
    {
        $user = User::factory()->create(['password' => 'secret-password', 'is_active' => true]);
        $user->assignRole(Roles::ADMIN);
        if ($withTwoFactor) {
            $user->saveAppAuthenticationSecret(self::SECRET);
        }

        return $user;
    }

    private function currentCode(): string
    {
        return app(Google2FA::class)->getCurrentOtp(self::SECRET);
    }
}
