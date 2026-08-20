<?php

namespace Tests\Feature;

use App\Livewire\Demo\ProfilePage;
use App\Livewire\Demo\SettingsPage;
use App\Mail\OperatorTestMail;
use App\Models\AgencySetting;
use App\Models\User;
use App\Notifications\OperatorResetPasswordNotification;
use App\Services\Notifications\NotificationPreferenceService;
use App\Services\Operator\AgencySettingService;
use App\Services\Operator\OperatorMailConfigService;
use App\Support\Demo\DemoState;
use App\Support\Operator\AgencySettingCatalog;
use App\Support\Operator\OperatorClock;
use App\Support\Operator\OperatorMailStatus;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class PhaseDOperationalSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);

        $this->admin = User::factory()->create([
            'name' => 'Phase D Admin',
            'email' => 'phase-d-admin@example.test',
            'locale' => 'en',
            'timezone' => null,
        ]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
    }

    public function test_team_member_cannot_escalate_self_or_change_mail(): void
    {
        $member = User::factory()->create([
            'name' => 'Phase D Member',
            'email' => 'phase-d-member@example.test',
        ]);
        $member->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($member);

        Livewire::test(SettingsPage::class)
            ->call('updateUserRole', $member->id, Roles::ADMIN)
            ->assertForbidden();

        Livewire::test(SettingsPage::class)
            ->set('mail_enabled', true)
            ->set('mail_host', 'smtp.example.test')
            ->set('mail_from_address', 'ops@example.test')
            ->set('mail_password', 'should-not-save')
            ->call('saveMail')
            ->assertForbidden();

        Livewire::test(SettingsPage::class)
            ->call('sendTestEmail')
            ->assertForbidden();

        Livewire::test(SettingsPage::class)
            ->call('clearOperatorMail')
            ->assertForbidden();

        Livewire::test(SettingsPage::class)
            ->set('agency_name', 'Hijacked Phase D')
            ->call('saveGeneral')
            ->assertForbidden();

        Livewire::test(SettingsPage::class)
            ->call('deactivateUser', $this->admin->id)
            ->assertForbidden();

        Livewire::test(SettingsPage::class)
            ->call('reactivateUser', $this->admin->id)
            ->assertForbidden();

        $member->refresh();
        $this->assertTrue($member->hasRole(Roles::TEAM_MEMBER));
        $this->assertFalse($member->hasRole(Roles::ADMIN));
        $this->assertTrue($this->admin->fresh()->is_active);
        $this->assertFalse((bool) AgencySetting::query()->first()?->mail_enabled);
        $this->assertDatabaseMissing('agency_settings', ['agency_name' => 'Hijacked Phase D']);
    }

    public function test_last_admin_cannot_deactivate_and_error_is_visible(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('section', 'team')
            ->call('deactivateUser', $this->admin->id)
            ->assertHasErrors(['user'])
            ->assertSee(__('operator.team.last_admin_deactivate'));

        $this->admin->refresh();
        $this->assertTrue($this->admin->is_active);
    }

    public function test_last_admin_cannot_demote_self_via_direct_livewire_call(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('section', 'team')
            ->call('updateUserRole', $this->admin->id, Roles::TEAM_MEMBER)
            ->assertHasErrors(['role'])
            ->assertSee(__('operator.team.last_admin_role'));

        $this->admin->refresh();
        $this->assertTrue($this->admin->hasRole(Roles::ADMIN));
        $this->assertFalse($this->admin->hasRole(Roles::TEAM_MEMBER));
    }

    public function test_agency_timezone_is_used_for_operator_date_rendering(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('default_timezone', 'America/New_York')
            ->call('saveGeneral')
            ->assertHasNoErrors();

        $this->assertSame('America/New_York', app(AgencySettingService::class)->defaultTimezone());

        $storageTimezone = (string) config('app.timezone');
        $this->get('/settings')->assertOk();
        $this->assertSame($storageTimezone, config('app.timezone'));
        $this->assertSame('America/New_York', OperatorClock::timezone());
        $this->assertSame('America/New_York', OperatorClock::now()->timezoneName);

        $this->admin->forceFill([
            'timezone' => 'America/Los_Angeles',
            'last_login_at' => CarbonImmutable::parse('2026-08-20T16:00:00Z'),
        ])->save();

        $fresh = $this->admin->fresh();
        $this->assertNotNull($fresh?->last_login_at);
        $this->assertSame(
            $fresh->last_login_at->timezone('America/Los_Angeles')->format('Y-m-d H:i'),
            OperatorClock::formatDateTime($fresh->last_login_at, $fresh),
        );
        $this->assertNotSame(
            $fresh->last_login_at->timezone('UTC')->format('Y-m-d H:i'),
            OperatorClock::formatDateTime($fresh->last_login_at, $fresh),
        );

        $this->get('/settings')->assertOk();
        $this->assertSame($storageTimezone, config('app.timezone'));
        $this->assertSame('America/Los_Angeles', OperatorClock::timezone($fresh));
    }

    public function test_default_analytical_range_updates_session_period(): void
    {
        Livewire::test(SettingsPage::class)
            ->set('default_analytical_date_range', 'last_7')
            ->call('saveGeneral')
            ->assertHasNoErrors();

        $this->assertSame('last_7', app(AgencySettingService::class)->defaultAnalyticalDateRange());
        $this->assertSame('last_7', DemoState::all()['period_preset']);
    }

    public function test_operator_smtp_is_write_only_encrypted_and_does_not_copy_env_secret(): void
    {
        config([
            'mail.default' => 'log',
            'mail.mailers.smtp.password' => 'env-smtp-secret-must-stay-out',
        ]);

        $secret = 'operator-smtp-secret-phase-d';

        $this->saveOperatorMail([
            'mail_enabled' => true,
            'mail_from_name' => 'MoxDOP Ops',
            'mail_from_address' => 'ops@example.test',
            'mail_host' => 'smtp.example.test',
            'mail_port' => '587',
            'mail_username' => 'ops@example.test',
            'mail_encryption' => 'tls',
            'mail_password' => $secret,
        ])
            ->assertHasNoErrors()
            ->assertSet('mail_password', '')
            ->assertDontSee($secret)
            ->assertDontSee('env-smtp-secret-must-stay-out');

        $settings = AgencySetting::query()->first();
        $this->assertNotNull($settings);
        $this->assertTrue((bool) $settings->mail_enabled);
        $this->assertSame('smtp.example.test', $settings->mail_host);
        $this->assertSame($secret, $settings->mail_password);

        $raw = DB::table('agency_settings')->where('id', $settings->id)->value('mail_password');
        $this->assertIsString($raw);
        $this->assertNotSame($secret, $raw);
        $this->assertNotSame('env-smtp-secret-must-stay-out', $raw);

        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringNotContainsString('env-smtp-secret-must-stay-out', $html);
        $this->assertSame(OperatorMailStatus::OPERATOR_CONFIGURED, OperatorMailStatus::state());

        $this->saveOperatorMail([
            'mail_from_name' => 'MoxDOP Ops Updated',
            'mail_enabled' => true,
            'mail_from_address' => 'ops@example.test',
            'mail_host' => 'smtp.example.test',
            'mail_port' => '587',
            'mail_username' => 'ops@example.test',
            'mail_encryption' => 'tls',
            'mail_password' => '',
        ])
            ->assertHasNoErrors()
            ->assertSet('mail_password', '');

        $this->assertSame($secret, AgencySetting::query()->first()?->mail_password);
    }

    public function test_smtp_test_action_sends_without_exposing_credentials(): void
    {
        Mail::fake();

        $this->saveOperatorMail([
            'mail_enabled' => true,
            'mail_from_address' => 'ops@example.test',
            'mail_host' => 'smtp.example.test',
            'mail_port' => '587',
            'mail_password' => 'operator-smtp-secret-phase-d',
        ])->assertHasNoErrors();

        Livewire::test(SettingsPage::class)
            ->call('sendTestEmail')
            ->assertHasNoErrors()
            ->assertSee(__('operator.mail.test_sent'))
            ->assertDontSee('operator-smtp-secret-phase-d');

        Mail::assertSent(OperatorTestMail::class, 1);
    }

    public function test_test_email_is_honest_when_mail_is_not_configured(): void
    {
        config(['mail.default' => 'array']);

        Livewire::test(SettingsPage::class)
            ->call('sendTestEmail')
            ->assertSee(__('operator.mail.test_not_configured'));
    }

    public function test_notification_preferences_toggle_in_app_and_do_not_claim_push(): void
    {
        $page = Livewire::test(SettingsPage::class)
            ->set('section', 'notifications')
            ->assertSee(__('operator.notifications.events.operation_failed'))
            ->assertSee(__('operator.notifications.footnote'))
            ->assertDontSee('mobile push is enabled')
            ->assertDontSee('push notifications are live')
            ->assertDontSee('Slack')
            ->assertDontSee('PWA')
            ->assertDontSee('whitelabel');

        $page->call('toggleNotification', 0)->assertHasNoErrors();

        $prefs = app(NotificationPreferenceService::class)->listForUser($this->admin);
        $this->assertFalse($prefs[0]['in_app_enabled']);
    }

    public function test_forgot_password_page_renders_for_guests(): void
    {
        $this->post('/logout');

        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee(__('operator.auth.send_reset'));
    }

    public function test_forgot_password_post_notifies_active_operator(): void
    {
        Notification::fake();

        $active = User::factory()->create([
            'email' => 'active-reset@example.test',
            'is_active' => true,
        ]);
        $active->assignRole(Roles::TEAM_MEMBER);

        $inactive = User::factory()->create([
            'email' => 'inactive-reset@example.test',
            'is_active' => false,
        ]);
        $inactive->assignRole(Roles::TEAM_MEMBER);

        $this->post('/logout');

        $unknown = 'nobody-phase-d@example.test';
        $this->post('/forgot-password', ['email' => $active->email])
            ->assertRedirect()
            ->assertSessionHas('status', __('operator.auth.reset_sent'));
        Notification::assertSentTo($active, OperatorResetPasswordNotification::class);

        $this->post('/forgot-password', ['email' => $inactive->email])
            ->assertRedirect()
            ->assertSessionHas('status', __('operator.auth.reset_sent'));
        Notification::assertNotSentTo($inactive, OperatorResetPasswordNotification::class);

        $this->post('/forgot-password', ['email' => $unknown])
            ->assertRedirect()
            ->assertSessionHas('status', __('operator.auth.reset_sent'));
        Notification::assertSentTimes(OperatorResetPasswordNotification::class, 1);
    }

    public function test_operator_password_reset_completes_for_active_user(): void
    {
        Notification::fake();

        $active = User::factory()->create([
            'email' => 'active-reset-complete@example.test',
            'is_active' => true,
            'password' => 'OldPass-phase-d-1',
            'remember_token' => 'old-remember-token-phase-d',
        ]);
        $active->assignRole(Roles::TEAM_MEMBER);

        $this->post('/logout');
        $this->post('/forgot-password', ['email' => $active->email])->assertRedirect();

        $token = null;
        Notification::assertSentTo(
            $active,
            OperatorResetPasswordNotification::class,
            function (OperatorResetPasswordNotification $notification) use (&$token): bool {
                $token = $notification->token;

                return $token !== '';
            },
        );
        $this->assertIsString($token);

        $this->get('/reset-password/'.$token.'?email='.urlencode($active->email))->assertOk();

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $active->email,
            'password' => 'NewPass-phase-d-1',
            'password_confirmation' => 'NewPass-phase-d-1',
        ])->assertRedirect(route('app.login'));

        $active->refresh();
        $this->assertTrue(Hash::check('NewPass-phase-d-1', $active->password));
        $this->assertTrue($active->is_active);
        $this->assertNotSame('old-remember-token-phase-d', $active->remember_token);
        $this->assertNotEmpty($active->remember_token);

        $this->post('/login', [
            'email' => $active->email,
            'password' => 'NewPass-phase-d-1',
        ])->assertRedirect('/');
    }

    public function test_inactive_operator_cannot_complete_password_reset(): void
    {
        $inactive = User::factory()->create([
            'email' => 'inactive-reset-complete@example.test',
            'is_active' => false,
            'password' => 'OldPass-phase-d-1',
        ]);
        $inactive->assignRole(Roles::TEAM_MEMBER);

        $token = Password::broker()->createToken($inactive);

        $this->post('/logout');
        $this->post('/reset-password', [
            'token' => $token,
            'email' => $inactive->email,
            'password' => 'NewPass-phase-d-1',
            'password_confirmation' => 'NewPass-phase-d-1',
        ])->assertSessionHasErrors('email');

        $inactive->refresh();
        $this->assertTrue(Hash::check('OldPass-phase-d-1', $inactive->password));
        $this->assertFalse($inactive->is_active);
    }

    public function test_clearing_operator_smtp_does_not_copy_env_secret_into_database(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.env.test',
            'mail.mailers.smtp.password' => 'env-smtp-secret-must-stay-out',
        ]);

        $this->saveOperatorMail([
            'mail_enabled' => true,
            'mail_from_address' => 'ops@example.test',
            'mail_host' => 'smtp.example.test',
            'mail_port' => '587',
            'mail_password' => 'operator-smtp-secret-phase-d',
        ])->assertHasNoErrors();

        Livewire::test(SettingsPage::class)
            ->call('clearOperatorMail')
            ->assertHasNoErrors();

        $settings = AgencySetting::query()->first();
        $this->assertNotNull($settings);
        $this->assertFalse((bool) $settings->mail_enabled);
        $this->assertNull($settings->mail_password);
        $this->assertNotSame('operator-smtp-secret-phase-d', config('mail.mailers.smtp.password'));
        $this->assertNotSame(OperatorMailStatus::OPERATOR_CONFIGURED, OperatorMailStatus::state());
        $raw = DB::table('agency_settings')->where('id', $settings->id)->value('mail_password');
        $this->assertTrue($raw === null || $raw === '');
        $this->assertStringNotContainsString('env-smtp-secret-must-stay-out', (string) $raw);
    }

    public function test_invalid_smtp_settings_do_not_poison_runtime_or_persist(): void
    {
        $baselineMailer = config('mail.default');
        $baselineHost = config('mail.mailers.smtp.host');
        $baselinePassword = config('mail.mailers.smtp.password');

        try {
            app(OperatorMailConfigService::class)->update([
                'mail_enabled' => true,
                'mail_from_name' => 'Poison',
                'mail_from_address' => 'ops@example.test',
                'mail_host' => 'smtp.poison.test',
                'mail_port' => '587',
                'mail_username' => 'ops@example.test',
                'mail_encryption' => 'not-a-scheme',
                'mail_password' => 'operator-smtp-secret-phase-d',
            ]);
            $this->fail('Invalid encryption must be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        try {
            app(OperatorMailConfigService::class)->update([
                'mail_enabled' => true,
                'mail_from_name' => 'Poison',
                'mail_from_address' => 'ops@example.test',
                'mail_host' => 'smtp.poison.test',
                'mail_port' => '0',
                'mail_username' => 'ops@example.test',
                'mail_encryption' => 'tls',
                'mail_password' => 'operator-smtp-secret-phase-d',
            ]);
            $this->fail('Invalid port must be rejected.');
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertSame($baselineMailer, config('mail.default'));
        $this->assertSame($baselineHost, config('mail.mailers.smtp.host'));
        $this->assertSame($baselinePassword, config('mail.mailers.smtp.password'));
        $this->assertFalse((bool) AgencySetting::query()->first()?->mail_enabled);
        $this->assertNotSame('smtp.poison.test', AgencySetting::query()->first()?->mail_host);
    }

    public function test_smtp_test_failure_does_not_expose_credentials(): void
    {
        Event::fake([MessageLogged::class]);
        $secret = 'operator-smtp-secret-phase-d';

        $this->saveOperatorMail([
            'mail_enabled' => true,
            'mail_from_address' => 'ops@example.test',
            'mail_host' => 'smtp.example.test',
            'mail_port' => '587',
            'mail_password' => $secret,
        ])->assertHasNoErrors();

        Mail::shouldReceive('to')
            ->once()
            ->andThrow(new \RuntimeException('535 Authentication failed password='.$secret));

        $page = Livewire::test(SettingsPage::class)
            ->call('sendTestEmail')
            ->assertSee(__('operator.mail.test_failed'))
            ->assertDontSee($secret);

        $html = $this->get('/settings')->assertOk()->getContent();
        $this->assertStringNotContainsString($secret, $html);
        $this->assertStringNotContainsString($secret, $page->html());

        Event::assertDispatched(MessageLogged::class, function (MessageLogged $event) use ($secret): bool {
            $encoded = json_encode([$event->message, $event->context]);

            return $event->message === 'operator.mail.test_failed'
                && is_string($encoded)
                && ! str_contains($encoded, $secret);
        });
    }

    public function test_invalid_timezone_does_not_reach_carbon_or_change_storage_clock(): void
    {
        $storageTimezone = (string) config('app.timezone');
        $phpTimezone = date_default_timezone_get();

        app(AgencySettingService::class)->current();
        AgencySetting::query()->update(['timezone' => 'Not/AZone']);
        $this->admin->forceFill(['timezone' => 'Also/Invalid'])->save();

        $resolved = OperatorClock::timezone($this->admin->fresh());
        $this->assertTrue(AgencySettingCatalog::isTimezone($resolved));
        $this->assertSame('Europe/Istanbul', $resolved);

        $formatted = OperatorClock::formatDateTime(CarbonImmutable::parse('2026-08-20T16:00:00Z'), $this->admin->fresh());
        $this->assertIsString($formatted);

        $this->get('/settings')->assertOk();
        $this->artisan('list')->assertExitCode(0);
        $this->assertSame($storageTimezone, config('app.timezone'));
        $this->assertSame($phpTimezone, date_default_timezone_get());
    }

    public function test_phase_d_surfaces_have_working_actions_or_truthful_copy(): void
    {
        $this->get('/settings')
            ->assertOk()
            ->assertSee(__('operator.settings.save_general'))
            ->assertSee(__('operator.mail.test'))
            ->assertSee(__('operator.mail.clear'));

        Livewire::test(SettingsPage::class)
            ->set('section', 'team')
            ->assertSee(__('operator.team.add'))
            ->assertSee(__('operator.team.deactivate'));

        Livewire::test(SettingsPage::class)
            ->set('section', 'notifications')
            ->assertSee(__('operator.notifications.on'));

        $this->get('/profile')
            ->assertOk()
            ->assertSee(__('operator.actions.save'))
            ->assertSee(__('operator.auth.logout'))
            ->assertDontSee('Avatar will be removed on save.');

        $this->get('/integrations')->assertOk();
        $this->get(route('operator.integrations.ai', ['provider' => 'openai']))->assertOk();

        Livewire::test(ProfilePage::class)
            ->set('removeAvatar', true)
            ->assertSee(__('operator.profile.remove_avatar_pending'));

        $this->post('/logout');
        $this->get('/forgot-password')
            ->assertOk()
            ->assertSee(__('operator.auth.send_reset'))
            ->assertSee(__('operator.auth.back_to_login'));
        $this->get('/reset-password/phase-d-token')
            ->assertOk()
            ->assertSee(__('operator.auth.reset_submit'));
    }

    public function test_mail_runtime_overlay_tolerates_unavailable_database(): void
    {
        Schema::shouldReceive('hasTable')
            ->andThrow(new \RuntimeException('database unavailable'));

        app(OperatorMailConfigService::class)->applyToRuntime();

        $this->addToAssertionCount(1);
    }

    /**
     * @param  array<string, mixed>  $fields
     */
    private function saveOperatorMail(array $fields): Testable
    {
        $component = Livewire::test(SettingsPage::class);
        $component->update(
            [['method' => 'saveMail', 'params' => [], 'path' => '']],
            $fields,
        );

        return $component;
    }
}
