<?php

namespace App\Console\Commands\Performance;

use App\Support\Performance\BenchmarkHarness;
use App\Support\Performance\BenchmarkProfiles;
use Illuminate\Console\Command;

/**
 * Prompt 65 — local/CI-optional benchmark runner. Not part of default phpunit.
 */
class RunBenchmarkCommand extends Command
{
    protected $signature = 'moxdop:performance:benchmark
        {profile : AGENCY_20|AGENCY_100|HIGH_VOLUME_GSC|HIGH_VOLUME_GOOGLE_ADS|MIXED_BACKGROUND_LOAD}
        {--customers= : Override customer count}
        {--brands-per-customer= : Override brands per customer}
        {--assets-per-brand= : Override assets per brand}
        {--gsc-rows= : Override GSC row count}
        {--ads-rows= : Override Google Ads row count}
        {--gsc-days= : Override GSC day span}
        {--ads-days= : Override Ads day span}
        {--seed=65 : Deterministic fixture seed}
        {--json : Emit JSON only}';

    protected $description = 'Run a Prompt 65 synthetic performance benchmark profile (no real providers/AI)';

    public function handle(BenchmarkHarness $harness): int
    {
        $id = (string) $this->argument('profile');
        if (! in_array($id, BenchmarkProfiles::ids(), true)) {
            $this->error('Unknown profile. Allowed: '.implode(', ', BenchmarkProfiles::ids()));

            return self::FAILURE;
        }

        $overrides = array_filter([
            'customers' => $this->option('customers'),
            'brands_per_customer' => $this->option('brands-per-customer'),
            'assets_per_brand' => $this->option('assets-per-brand'),
            'gsc_rows' => $this->option('gsc-rows'),
            'ads_rows' => $this->option('ads-rows'),
            'gsc_days' => $this->option('gsc-days'),
            'ads_days' => $this->option('ads-days'),
        ], static fn ($v) => $v !== null && $v !== '');

        $profile = BenchmarkProfiles::resolve($id, $overrides);
        $result = $harness->run($profile, (int) $this->option('seed'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return self::SUCCESS;
        }

        $this->info('Profile: '.$profile['id']);
        $this->line('Purpose: '.$profile['purpose']);
        $this->line('Git SHA: '.($result['environment']['git_sha'] ?? 'UNKNOWN'));
        $this->line('DB: '.$result['environment']['database_driver']);
        $this->table(
            ['Metric', 'Queries', 'ms', 'Notes'],
            collect($result['measurements'] ?? [])->map(function ($m, $key) {
                return [
                    $key,
                    $m['queries'] ?? '—',
                    $m['duration_ms'] ?? '—',
                    isset($m['per_page']) ? 'per_page='.$m['per_page'] : (isset($m['rows_returned']) ? 'rows='.$m['rows_returned'] : ''),
                ];
            })->values()->all(),
        );
        $this->line('Partition: '.json_encode($result['partition_decision']));

        return self::SUCCESS;
    }
}
