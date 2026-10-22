<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

trait RollsBackMigrationsUntil
{
    /**
     * Roll back one migration batch step at a time until none of the given tables exist.
     * Avoids brittle --step=N counts that break whenever newer migrations are added.
     *
     * @param  list<string>  $tables
     */
    protected function rollbackUntilTablesMissing(string ...$tables): void
    {
        // PostgreSQL keeps fact tables behind views that the old down() methods drop as tables; rollback runs on SQLite.
        $this->onlyOnSqlite('migration rollback runs on SQLite');

        // One rollback step per migration file, so the guard grows with the migration count.
        $guard = 500;

        while ($guard-- > 0) {
            $anyPresent = false;
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    $anyPresent = true;
                    break;
                }
            }

            if (! $anyPresent) {
                return;
            }

            $this->assertSame(0, Artisan::call('migrate:rollback', ['--step' => 1]));
        }

        $this->fail('Exceeded rollback guard while waiting for tables to disappear: '.implode(', ', $tables));
    }
}
