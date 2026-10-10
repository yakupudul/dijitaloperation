<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
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

    public function test_every_scheduled_job_runs_on_istanbul_time_and_the_brand_file_follows_the_desk_audit(): void
    {
        $events = collect(app(Schedule::class)->events());
        $zones = $events->map(fn ($e): string => $e->timezone instanceof \DateTimeZone ? $e->timezone->getName() : (string) $e->timezone)->unique()->values()->all();
        $at = fn (string $command): string => (string) $events->first(fn ($e): bool => str_contains((string) $e->command, $command))?->expression;

        $this->assertSame(['Europe/Istanbul'], $zones);
        $this->assertSame('40 4 * * *', $at('moxdop:repair:audit'));
        $this->assertSame('40 5 * * *', $at('moxdop:brands:dossier'), 'after the desk audit and preparation');
        $this->assertSame('5 9 * * *', $at('moxdop:repair:digest'), 'a morning digest');
    }

    public function test_the_nightly_brand_data_audit_runs_the_data_audit(): void
    {
        $this->artisan('moxdop:brands:audit')->expectsOutputToContain('Marka verisi denetimi')->assertSuccessful();
    }
}
