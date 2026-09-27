<?php

namespace Tests\Feature\Operations;

use App\Services\Operator\AgencySettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The installation language is Turkish unless APP_LOCALE says otherwise (phpunit.xml pins en for the suite). */
final class TurkishDefaultLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_configuration_defaults_to_turkish(): void
    {
        $this->assertStringContainsString("'locale' => env('APP_LOCALE', 'tr')", (string) file_get_contents(config_path('app.php')));
        $this->assertMatchesRegularExpression('/^APP_LOCALE=tr$/m', (string) file_get_contents(base_path('.env.example')));
    }

    public function test_fresh_installation_serves_the_login_page_in_the_app_locale(): void
    {
        config(['app.locale' => 'tr']);

        $this->get(route('app.login'))->assertOk()->assertSee('Operatör hesabınızla giriş yapın.');
        $this->assertSame('tr', app(AgencySettingService::class)->current()->locale);
        $this->assertSame('tr', app(AgencySettingService::class)->defaultLocale());
    }

    public function test_english_installation_stays_english(): void
    {
        config(['app.locale' => 'en']);

        $this->get(route('app.login'))->assertOk()->assertSee('Use your operator account.');
        $this->assertSame('en', app(AgencySettingService::class)->current()->locale);
    }

    public function test_unsupported_app_locale_falls_back_to_english(): void
    {
        config(['app.locale' => 'de']);

        $this->assertSame('en', app(AgencySettingService::class)->defaultLocale());
    }
}
