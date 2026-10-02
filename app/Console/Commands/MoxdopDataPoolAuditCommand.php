<?php

namespace App\Console\Commands;

use App\Enums\DataPool\IntegrityAuditMode;
use App\Services\DataPool\Integrity\DataPoolIntegrityAuditor;
use App\Services\DataPool\Integrity\Support\IntegrityAuditRequest;
use App\Support\Console\ConsoleScope;
use App\Support\Console\ConsoleScopeException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Production-safe data-pool integrity audit (verification only; no repair).
 */
class MoxdopDataPoolAuditCommand extends Command
{
    protected $signature = 'moxdop:data-pool-audit
        {--provider=* : Provider filter (SEARCH_CONSOLE, GA4, GOOGLE_ADS, META_ADS)}
        {--dataset=* : Logical dataset id filter}
        {--resource=* : External resource id filter}
        {--asset=* : Digital asset id filter}
        {--date-from= : Optional coverage from (Y-m-d)}
        {--date-to= : Optional coverage to (Y-m-d)}
        {--provider-reconcile : Explicit opt-in provider reconciliation (disabled unless config allows)}
        {--strict : Exit non-zero when any check fails (CI); by default failed checks are findings, not a command failure}
        {--json : Emit JSON summary}';

    protected $description = 'Run a local (default) data-pool integrity audit. Never repairs facts. Never prints secrets.';

    public function handle(DataPoolIntegrityAuditor $auditor): int
    {
        $mode = $this->option('provider-reconcile')
            ? IntegrityAuditMode::ProviderReconciliation
            : IntegrityAuditMode::LocalIntegrity;

        $providers = array_values(array_filter(array_map('strval', (array) $this->option('provider'))));
        $datasets = array_values(array_filter(array_map('strval', (array) $this->option('dataset'))));
        $resources = array_values(array_filter(array_map('intval', (array) $this->option('resource'))));
        try {
            // Id or (partial) name / domain; "Panorama" must not silently widen the audit to every asset.
            $assets = array_values(array_unique(array_map(fn (string $value): int => ConsoleScope::asset($value)->id,
                array_values(array_filter(array_map('strval', (array) $this->option('asset')), fn (string $v): bool => trim($v) !== '')))));
        } catch (ConsoleScopeException $exception) {
            $this->error($exception->getMessage());

            return self::INVALID;
        }

        try {
            $run = $auditor->run(new IntegrityAuditRequest(
                mode: $mode,
                providers: $providers !== [] ? $providers : null,
                datasetIds: $datasets !== [] ? $datasets : null,
                digitalAssetIds: $assets !== [] ? $assets : null,
                externalResourceIds: $resources !== [] ? $resources : null,
                dateFrom: $this->option('date-from') ? (string) $this->option('date-from') : null,
                dateTo: $this->option('date-to') ? (string) $this->option('date-to') : null,
                initiatedBy: null,
            ));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            // Expected runtime conditions (provider rate limit, missing optional tables / data) are logged; the
            // scheduler is not failed for them unless --strict.
            report($e);
            $this->warn('Denetim tamamlanamadı: '.mb_substr($e->getMessage(), 0, 300));

            return $this->option('strict') ? self::FAILURE : self::SUCCESS;
        }

        $payload = [
            'uuid' => $run->uuid,
            'status' => $run->status->value,
            'mode' => $run->mode->value,
            'checks_total' => $run->checks_total,
            'checks_pass' => $run->checks_pass,
            'checks_pass_with_limitation' => $run->checks_pass_with_limitation,
            'checks_warning' => $run->checks_warning,
            'checks_fail' => $run->checks_fail,
            'checks_unverified' => $run->checks_unverified,
            'provider_readiness' => $run->provider_readiness,
            'summary' => $run->summary,
            'numeric_quality_score' => null,
            'automatic_repair' => false,
        ];

        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("Integrity audit {$run->uuid}: {$run->status->value}");
            $this->line("Mode: {$run->mode->value}");
            $this->line("Checks: {$run->checks_total} total · {$run->checks_pass} pass · {$run->checks_fail} fail · {$run->checks_unverified} unverified");
            foreach (($run->provider_readiness ?? []) as $provider => $row) {
                if ($provider === '_global' || ! is_array($row)) {
                    continue;
                }
                $this->line("{$provider}: {$row['status']}");
            }
        }

        // Failed checks are the audit's findings (stored on the run and shown in Veri Havuzu), not a broken command:
        // the nightly scheduled audit must not be reported as a failed scheduler task for them. --strict keeps the
        // non-zero exit for CI gates.
        if ((int) $run->checks_fail > 0) {
            Log::warning('data-pool-audit.checks-failed', ['uuid' => $run->uuid, 'providers' => $providers, 'checks_fail' => (int) $run->checks_fail]);
            if ($this->option('strict')) {
                return self::FAILURE;
            }
            $this->warn(sprintf('%d kontrol başarısız (bulgu olarak kaydedildi; komut hatası değil).', (int) $run->checks_fail));
        }

        return self::SUCCESS;
    }
}
