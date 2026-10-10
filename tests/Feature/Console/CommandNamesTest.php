<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Two closure commands with one name silently replace each other (the later wins), so the scheduled one may never run
 * (2026-10-10: `moxdop:brands:audit` ran the Şef check instead of the brand data audit).
 */
final class CommandNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_console_command_name_is_defined_once(): void
    {
        preg_match_all("/Artisan::command\\('([^' ]+)/", (string) file_get_contents(base_path('routes/console.php')), $matches);
        $twice = array_keys(array_filter(array_count_values($matches[1]), fn (int $n): bool => $n > 1));

        $this->assertNotEmpty($matches[1]);
        $this->assertSame([], $twice);
    }

    public function test_the_nightly_brand_data_audit_runs_the_data_audit(): void
    {
        $this->artisan('moxdop:brands:audit')->expectsOutputToContain('Marka verisi denetimi')->assertSuccessful();
    }
}
