<?php

namespace App\Console\Commands\Production;

use App\Services\Operations\DeployPreflight;
use Illuminate\Console\Command;

/**
 * Deploy gate: deploy/staging/deploy.sh runs it after `composer install` and before `migrate`; a non-zero exit
 * stops the deploy. Read-only.
 */
final class PreflightCommand extends Command
{
    protected $signature = 'moxdop:preflight {--json : Emit machine-readable JSON}';

    protected $description = 'Read-only deploy gate: required settings, database/Redis reachability, config/route/view cache compilation, pending migrations';

    public function handle(DeployPreflight $preflight): int
    {
        $rows = $preflight->run();
        $failed = DeployPreflight::failed($rows);
        $warned = collect($rows)->contains(fn (array $row): bool => $row['result'] === DeployPreflight::WARN);
        $overall = $failed ? 'FAIL' : ($warned ? 'WARN' : 'PASS');

        if ($this->option('json')) {
            $this->line((string) json_encode(['overall' => $overall, 'checks' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($rows as $row) {
                $this->line(str_pad($row['result'], 5).' '.$row['check'].': '.$row['detail']);
            }
            $this->line('preflight: '.$overall);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
