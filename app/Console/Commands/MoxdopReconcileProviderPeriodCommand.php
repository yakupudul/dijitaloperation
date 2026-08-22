<?php

namespace App\Console\Commands;

use App\Services\DataPool\Reconciliation\ClosedPeriodProviderReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('moxdop:reconcile-provider-period {provider : SEARCH_CONSOLE or GA4} {--asset= : Digital asset id} {--from= : Closed period start Y-m-d} {--to= : Closed period end Y-m-d} {--tolerance=0.01 : Relative tolerance} {--json : Emit JSON}')]
#[Description('Compare a closed calendar period of warehouse facts against provider totals. Never repairs facts. Never prints secrets.')]
class MoxdopReconcileProviderPeriodCommand extends Command
{
    public function handle(ClosedPeriodProviderReconciler $reconciler): int
    {
        $provider = strtoupper((string) $this->argument('provider'));
        $asset = (int) $this->option('asset');
        $from = (string) $this->option('from');
        $to = (string) $this->option('to');
        $tolerance = (float) $this->option('tolerance');

        if ($asset <= 0 || $from === '' || $to === '') {
            $this->error('Required: --asset --from --to for a closed calendar period.');
            $this->line('Example: php artisan moxdop:reconcile-provider-period SEARCH_CONSOLE --asset=12 --from=2026-07-01 --to=2026-07-31 --json');
            $this->line('Operator path after collect: /assets/search-console/{id} or /assets/analytics/{id}');
            $this->line('EXTERNAL UAT REQUIRED: live Search Console / GA4 UI totals cannot be faked in CI.');

            return self::FAILURE;
        }

        try {
            $report = $reconciler->reconcile($provider, $asset, $from, $to, $tolerance);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());
            $this->line('EXTERNAL UAT REQUIRED when staging credentials are needed to call the provider.');

            return self::FAILURE;
        }

        $payload = $report->toArray();

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("{$report->provider} {$report->from}..{$report->to}: {$report->status}");
            foreach ($report->metrics as $metric) {
                $this->line(sprintf(
                    '%s: warehouse=%s provider=%s status=%s',
                    $metric['metric'],
                    $metric['warehouse'] ?? 'n/a',
                    $metric['provider'] ?? 'n/a',
                    $metric['status'],
                ));
            }
            $this->line('Operator path: '.$report->operatorPath);
            $this->line('EXTERNAL UAT REQUIRED: confirm the same closed period in the provider UI (±1% additive metrics).');
        }

        return $report->status === 'pass' ? self::SUCCESS : self::FAILURE;
    }
}
