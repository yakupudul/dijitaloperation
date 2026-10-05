<?php

namespace Tests\Feature\Operations;

use App\Livewire\Operator\Settings\AiOperationsPage;
use App\Models\AgencySetting;
use App\Models\User;
use App\Services\Operator\AgencySettingService;
use App\Services\Operator\OperatorMailConfigService;
use App\Support\Operator\OperatorClock;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Ajans ayarları: the settings row is read once per request / job (scoped service), a Horizon worker reads it again
 * for every job, and writes that bypass the service are read fresh afterwards.
 */
final class AgencySettingScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AgencySetting::query()->create(['agency_name' => 'Moximu', 'portal_name' => 'MoxDOP', 'locale' => 'tr', 'timezone' => 'Europe/Istanbul',
            'display_currency' => 'TRY', 'week_starts_on' => 'monday', 'analytical_date_range' => 'last_28']);
        // A new request / job scope, as at the start of a request or before each job a worker picks up.
        $this->app->forgetScopedInstances();
    }

    public function test_the_settings_row_is_read_once_per_request(): void
    {
        $count = $this->countSettingsQueries(function (): void {
            $settings = app(AgencySettingService::class);
            $settings->defaultLocale();
            $settings->defaultTimezone();
            $settings->defaultAnalyticalDateRange();
            $settings->weekStartsOn();
            $settings->branding();
            OperatorClock::timezone();
            OperatorClock::timezone();
        });

        $this->assertSame(2, $count, 'one table check and one row read for the whole request');
    }

    public function test_a_worker_reads_the_settings_again_for_every_job(): void
    {
        $first = app(AgencySettingService::class);
        $this->assertSame('Europe/Istanbul', OperatorClock::timezone());
        DB::table('agency_settings')->update(['timezone' => 'America/New_York', 'locale' => 'en']);
        $this->assertSame('Europe/Istanbul', OperatorClock::timezone(), 'kept for the rest of this job');

        $this->app->forgetScopedInstances();

        $this->assertNotSame($first, app(AgencySettingService::class));
        $this->assertSame('America/New_York', OperatorClock::timezone());
        $this->assertSame('en', app(AgencySettingService::class)->defaultLocale());
    }

    public function test_saved_general_settings_are_kept_without_another_read(): void
    {
        $settings = app(AgencySettingService::class);
        $settings->updateGeneral($this->general(['timezone' => 'America/New_York', 'agency_name' => 'Northwind']));

        $count = $this->countSettingsQueries(function () use ($settings): void {
            $this->assertSame('America/New_York', $settings->defaultTimezone());
            $this->assertSame('Northwind', $settings->branding()['agency_name']);
        });

        $this->assertSame(0, $count);
        $this->assertSame('America/New_York', DB::table('agency_settings')->value('timezone'));
    }

    public function test_a_failed_general_update_leaves_the_stored_values_in_place(): void
    {
        Storage::fake(AgencySettingService::BRANDING_DISK);
        $settings = app(AgencySettingService::class);
        $settings->current();

        try {
            $settings->updateGeneral($this->general(['agency_name' => 'Kaydedilmedi']), UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'));
            $this->fail('An unsupported logo type should be refused.');
        } catch (InvalidArgumentException) {
            // The refusal happens after the form values were filled in.
        }

        $this->assertSame('Moximu', $settings->current()->agency_name);
        $this->assertSame('Moximu', $settings->branding()['agency_name']);
        $this->assertSame('Moximu', DB::table('agency_settings')->value('agency_name'));
    }

    public function test_defaults_used_while_the_table_is_missing_are_not_kept(): void
    {
        Schema::rename('agency_settings', 'agency_settings_moved');
        $settings = app(AgencySettingService::class);
        $this->assertFalse($settings->current()->exists);
        $this->assertSame('MoxDOP', $settings->current()->agency_name);

        Schema::rename('agency_settings_moved', 'agency_settings');

        $this->assertTrue($settings->current()->exists);
        $this->assertSame('Moximu', $settings->current()->agency_name);
    }

    public function test_the_mail_service_reads_the_settings_of_the_current_job(): void
    {
        $mail = app(OperatorMailConfigService::class);
        $this->assertFalse($mail->operatorSmtpIsComplete());
        $this->storeCompleteSmtp();

        $this->app->forgetScopedInstances();

        $this->assertSame($mail, app(OperatorMailConfigService::class), 'the mail service stays one per process');
        $this->assertTrue($mail->operatorSmtpIsComplete());
    }

    public function test_reload_for_queued_send_reads_the_stored_smtp_again(): void
    {
        $mail = app(OperatorMailConfigService::class);
        $mail->applyToRuntime();
        $this->assertNotSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->storeCompleteSmtp();

        $mail->reloadForQueuedSend();

        $this->assertSame('smtp.example.test', config('mail.mailers.smtp.host'));
        $this->assertSame('ops@example.test', config('mail.from.address'));
    }

    public function test_the_ai_budget_saved_on_the_settings_page_is_read_fresh(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        app(AgencySettingService::class)->current();

        Livewire::test(AiOperationsPage::class)->set('budget', '120')->set('dailyAutoBudget', '7')->call('saveBudget')->assertHasNoErrors();

        $this->assertSame(120.0, (float) app(AgencySettingService::class)->current()->ai_monthly_budget_usd);
        $this->assertSame(7.0, (float) app(AgencySettingService::class)->current()->ai_daily_auto_budget_usd);
    }

    /** @param  callable(): void  $work */
    private function countSettingsQueries(callable $work): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $work();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($queries, fn (array $query): bool => str_contains((string) $query['query'], 'agency_settings')));
    }

    private function storeCompleteSmtp(): void
    {
        // A separate model instance: the write a settings screen in another request would make.
        AgencySetting::query()->sole()->forceFill(['mail_enabled' => true, 'mail_host' => 'smtp.example.test', 'mail_port' => 587,
            'mail_from_address' => 'ops@example.test', 'mail_encryption' => 'tls', 'mail_password' => 'secret-smtp'])->save();
    }

    /**
     * @param  array<string, string>  $overrides
     * @return array{agency_name: string, portal_name: string, locale: string, timezone: string, display_currency: string, week_starts_on: string, analytical_date_range: string}
     */
    private function general(array $overrides = []): array
    {
        return [...['agency_name' => 'Moximu', 'portal_name' => 'MoxDOP', 'locale' => 'tr', 'timezone' => 'Europe/Istanbul',
            'display_currency' => 'TRY', 'week_starts_on' => 'monday', 'analytical_date_range' => 'last_28'], ...$overrides];
    }
}
