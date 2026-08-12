<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Clears Demo Mode session state for the current process context.
 * Browser operators should use the in-app “Reset Demo Mode” control;
 * this Artisan command is for developers resetting local session stores in tests.
 */
final class DemoResetCommand extends Command
{
    protected $signature = 'dop:demo-reset';

    protected $description = 'Reset MoxDOP Demo Mode session state to the canonical Atlas Dental fixtures';

    public function handle(): int
    {
        $this->info('Demo Mode fixtures are session-scoped.');
        $this->line('Open /app/settings and click “Reset Demo Mode”, or clear the session cookie.');
        $this->line('Canonical fixtures: Atlas Health Group → Atlas Dental Ankara (no operator DB writes).');

        return self::SUCCESS;
    }
}
