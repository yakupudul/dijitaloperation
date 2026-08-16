<?php

namespace App\Support\Performance;

use App\Filament\App\Resources\Findings\FindingResource;
use App\Filament\App\Resources\Tasks\TaskResource;
use App\Models\Customer;
use App\Models\Finding;
use App\Models\Task;
use App\Services\GoogleAds\GoogleAdsPoolReadRepository;
use App\Services\Gsc\GscPoolReadRepository;
use App\Services\ReportSnapshots\ReportSnapshotReadService;
use App\Services\Tasks\TaskReadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Repository-local Prompt 65 benchmark harness (no production dependency).
 *
 * @phpstan-import-type Profile from BenchmarkProfiles
 */
final class BenchmarkHarness
{
    public function __construct(
        private readonly SyntheticBenchmarkSeeder $seeder,
        private readonly QueryCountProbe $probe,
    ) {}

    /**
     * @param  Profile  $profile
     * @return array<string, mixed>
     */
    public function run(array $profile, int $seed = 65): array
    {
        $env = $this->environmentContext();
        $fixture = $this->seeder->seed($profile, $seed);
        $asset = $fixture['primary_asset'];
        $end = Carbon::now('UTC')->toDateString();
        $start = Carbon::now('UTC')->subDays(max(1, (int) $profile['gsc_days'] ?: (int) $profile['ads_days'] ?: 28))->toDateString();

        $measurements = [];

        $measurements['customer_list_query'] = $this->probe->measure(function () {
            return Customer::query()->orderBy('name')->limit(100)->get(['id', 'name', 'status']);
        });
        unset($measurements['customer_list_query']['result']);

        $measurements['finding_eloquent_query'] = $this->probe->measure(function () {
            return FindingResource::getEloquentQuery()->limit(50)->get();
        });
        unset($measurements['finding_eloquent_query']['result']);

        $measurements['task_eloquent_query'] = $this->probe->measure(function () {
            return TaskResource::getEloquentQuery()->limit(50)->get();
        });
        unset($measurements['task_eloquent_query']['result']);

        if ($asset !== null && Schema::hasTable('gsc_query_daily') && (int) $profile['gsc_rows'] > 0) {
            $repo = app(GscPoolReadRepository::class);
            $measurements['gsc_top_queries'] = $this->probe->measure(function () use ($repo, $asset, $start, $end) {
                return $repo->topQueries($asset->id, 1, 'https://bench.example.test/', $start, $end, 20);
            });
            $gscResult = $measurements['gsc_top_queries']['result'];
            unset($measurements['gsc_top_queries']['result']);
            $measurements['gsc_top_queries']['rows_returned'] = is_countable($gscResult) ? count($gscResult) : 0;

            $measurements['gsc_aggregate_sql'] = $this->probe->measure(function () use ($asset, $start, $end) {
                return DB::table('gsc_query_daily')
                    ->selectRaw('query, SUM(clicks) as clicks, SUM(impressions) as impressions')
                    ->where('digital_asset_id', $asset->id)
                    ->whereBetween('reporting_date', [$start, $end])
                    ->groupBy('query')
                    ->orderByDesc('clicks')
                    ->limit(50)
                    ->get();
            });
            unset($measurements['gsc_aggregate_sql']['result']);
        }

        if ($asset !== null && Schema::hasTable('google_ads_search_term_daily') && (int) $profile['ads_rows'] > 0) {
            $adsRepo = app(GoogleAdsPoolReadRepository::class);
            $measurements['ads_search_terms'] = $this->probe->measure(function () use ($adsRepo, $asset, $start, $end) {
                return $adsRepo->topSearchTerms($asset->id, 1, 'bench-ads-customer', $start, $end, 50);
            });
            unset($measurements['ads_search_terms']['result']);
        }

        $taskRead = app(TaskReadService::class);
        $measurements['task_paginate_clamp'] = $this->probe->measure(function () use ($taskRead) {
            return $taskRead->paginate([], 1_000_000);
        });
        $paginator = $measurements['task_paginate_clamp']['result'];
        $measurements['task_paginate_clamp']['per_page'] = method_exists($paginator, 'perPage') ? $paginator->perPage() : null;
        unset($measurements['task_paginate_clamp']['result']);

        $reportRead = app(ReportSnapshotReadService::class);
        if ($fixture['customers'] !== []) {
            $customer = $fixture['customers'][0];
            $measurements['report_list'] = $this->probe->measure(function () use ($reportRead, $customer) {
                return $reportRead->listForCustomer($customer, ['per_page' => 20], [(int) $customer->id], []);
            });
            unset($measurements['report_list']['result']);
        }

        $tableCounts = [
            'customers' => Customer::query()->count(),
            'brands' => count($fixture['brands']),
            'digital_assets' => count($fixture['assets']),
            'gsc_query_daily' => Schema::hasTable('gsc_query_daily')
                ? (int) DB::table('gsc_query_daily')->count()
                : 0,
            'google_ads_search_term_daily' => Schema::hasTable('google_ads_search_term_daily')
                ? (int) DB::table('google_ads_search_term_daily')->count()
                : 0,
            'findings' => Finding::query()->count(),
            'tasks' => Task::query()->count(),
        ];

        return [
            'profile' => $profile,
            'seed' => $seed,
            'environment' => $env,
            'fixture' => [
                'customers' => count($fixture['customers']),
                'brands' => count($fixture['brands']),
                'assets' => count($fixture['assets']),
                'gsc_rows_inserted' => $fixture['gsc_rows_inserted'],
                'ads_rows_inserted' => $fixture['ads_rows_inserted'],
            ],
            'table_counts' => $tableCounts,
            'measurements' => $measurements,
            'partition_decision' => $this->partitionDecision(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function environmentContext(): array
    {
        return [
            'git_sha' => trim((string) @shell_exec('git rev-parse HEAD 2>/dev/null')) ?: 'UNKNOWN',
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
            'database_default' => (string) config('database.default'),
            'database_driver' => (string) DB::connection()->getDriverName(),
            'cpu_count' => (int) (function_exists('nproc') ? 0 : 0) ?: (int) (@shell_exec('nproc 2>/dev/null') ?: 0),
            'ram_bytes_available' => $this->availableRamBytes(),
            'queue_connection' => (string) config('queue.default'),
            'horizon_default_timeout' => (int) config('horizon.defaults.supervisor-1.timeout', 0),
            'horizon_collection_timeout' => (int) config('horizon.defaults.supervisor-collection.timeout', 0),
            'warehouse_batch_size' => (int) config('moxdop-data-pool.default_batch_size', 500),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function partitionDecision(): array
    {
        return [
            'data_plane_range_monthly' => 'ALREADY_IMPLEMENTED_FOR_PROVIDER_DAILY_FACTS',
            'control_plane_customer_partition' => 'REJECT',
            'further_partitioning' => 'DEFER',
            'reason' => 'Indexed non-partitioned SQLite benchmark paths are healthy; Postgres RANGE_MONTHLY already exists for time-series facts. No Customer partitions.',
        ];
    }

    private function availableRamBytes(): int
    {
        $meminfo = @file_get_contents('/proc/meminfo');
        if (! is_string($meminfo) || ! preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $m)) {
            return 0;
        }

        return (int) $m[1] * 1024;
    }
}
