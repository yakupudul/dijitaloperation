<?php

namespace App\Services\Integrations;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Findings\FindingLifecycleService;
use Illuminate\Support\Facades\Log;

/**
 * One-operator entry: discover active bindings on a Digital Asset and run
 * registered module collectors. No credentials / external IDs / OAuth here.
 * After collection, module Evidence → Finding rules run through Core lifecycle.
 */
final class CollectLiveBoundDataService
{
    public function __construct(
        private readonly BoundCollectorRegistry $registry,
        private readonly BoundEvidenceRuleRegistry $ruleRegistry,
        private readonly FindingLifecycleService $lifecycle,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     runs: list<Run>,
     *     skipped: list<array{capability: string, reason: string}>,
     *     findings: array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int}
     * }
     */
    public function collect(DigitalAsset $asset): array
    {
        $emptyFindings = [
            'opened' => 0,
            'updated' => 0,
            'reopened' => 0,
            'resolved' => 0,
            'recommendations' => 0,
        ];

        $bindings = CoreAssetBinding::query()
            ->with(['externalResource.integration', 'digitalAsset'])
            ->where('digital_asset_id', $asset->id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderBy('id')
            ->get();

        if ($bindings->isEmpty()) {
            return [
                'ok' => false,
                'message' => 'No active provider bindings. Bind External Resources under Provider resources (Settings → Integrations → Google first).',
                'runs' => [],
                'skipped' => [],
                'findings' => $emptyFindings,
            ];
        }

        $runs = [];
        $skipped = [];
        $failures = 0;

        foreach ($bindings as $binding) {
            $collector = $this->registry->forCapability((string) $binding->capability);
            if ($collector === null) {
                $skipped[] = [
                    'capability' => (string) $binding->capability,
                    'reason' => 'No collector registered for this capability yet.',
                ];

                continue;
            }

            try {
                $run = $collector->collect($binding);
                $runs[] = $run->loadMissing('evidence');
            } catch (\Throwable $e) {
                $failures++;
                Log::warning('Bound collector failed', [
                    'binding_id' => $binding->id,
                    'capability' => $binding->capability,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                $skipped[] = [
                    'capability' => (string) $binding->capability,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        if ($runs === [] && $skipped !== []) {
            return [
                'ok' => false,
                'message' => 'No collectors ran. '.$this->summarizeSkipped($skipped),
                'runs' => [],
                'skipped' => $skipped,
                'findings' => $emptyFindings,
            ];
        }

        $findings = $this->evaluateFindings($asset, $runs);

        $ok = $failures === 0;
        $message = count($runs).' collector run(s) finished.';
        if ($skipped !== []) {
            $message .= ' '.$this->summarizeSkipped($skipped);
        }
        $message .= ' '.$this->summarizeFindings($findings);

        return [
            'ok' => $ok,
            'message' => $message,
            'runs' => $runs,
            'skipped' => $skipped,
            'findings' => $findings,
        ];
    }

    /**
     * @param  list<Run>  $runs
     * @return array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int}
     */
    private function evaluateFindings(DigitalAsset $asset, array $runs): array
    {
        $totals = [
            'opened' => 0,
            'updated' => 0,
            'reopened' => 0,
            'resolved' => 0,
            'recommendations' => 0,
        ];

        if ($runs === []) {
            return $totals;
        }

        foreach ($this->ruleRegistry->all() as $evaluator) {
            try {
                $result = $evaluator->evaluate($asset, $runs);
                $stats = $this->lifecycle->apply($result);
                foreach ($totals as $key => $_) {
                    $totals[$key] += $stats[$key];
                }
            } catch (\Throwable $e) {
                Log::warning('Bound evidence rule evaluation failed', [
                    'digital_asset_id' => $asset->id,
                    'evaluator' => $evaluator::class,
                    'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $anchor = $runs[array_key_last($runs)] ?? null;
        if ($anchor instanceof Run) {
            $anchor->update([
                'metadata' => array_merge($anchor->metadata ?? [], [
                    'findings_lifecycle' => $totals,
                ]),
            ]);
        }

        return $totals;
    }

    /**
     * @param  array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int}  $findings
     */
    private function summarizeFindings(array $findings): string
    {
        return sprintf(
            'Findings: %d opened, %d updated, %d reopened, %d resolved.',
            $findings['opened'],
            $findings['updated'],
            $findings['reopened'],
            $findings['resolved'],
        );
    }

    /**
     * @param  list<array{capability: string, reason: string}>  $skipped
     */
    private function summarizeSkipped(array $skipped): string
    {
        return collect($skipped)
            ->map(fn (array $row): string => $row['capability'].': '.$row['reason'])
            ->implode(' | ');
    }
}
