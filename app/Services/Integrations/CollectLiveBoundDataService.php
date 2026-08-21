<?php

namespace App\Services\Integrations;

use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Run;
use App\Models\User;
use App\Services\CollectionScheduler\CollectionSchedulingPolicyRegistry;
use App\Services\CollectionScheduler\ExecuteCollectionLifecycleService;
use App\Services\Findings\BoundEvidenceRuleRegistry;
use App\Services\Findings\FindingLifecycleService;
use Illuminate\Support\Facades\Log;

/**
 * One-operator entry: discover active bindings on a Digital Asset and collect.
 * GA4 / GSC / Google Ads / Meta Ads use the shared Collection Engine
 * (CollectionRun → DatasetRun → warehouse). Remaining capabilities (GBP, etc.)
 * still use BoundCollectorRegistry Evidence collectors.
 */
final class CollectLiveBoundDataService
{
    public function __construct(
        private readonly BoundCollectorRegistry $registry,
        private readonly BoundEvidenceRuleRegistry $ruleRegistry,
        private readonly FindingLifecycleService $lifecycle,
        private readonly ExecuteCollectionLifecycleService $collectionLifecycle,
    ) {}

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     runs: list<Run>,
     *     skipped: list<array{capability: string, reason: string}>,
     *     findings: array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int},
     *     collection_run_id: ?int
     * }
     */
    public function collect(DigitalAsset $asset, ?User $actor = null, ?Run $operatorRun = null): array
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
                'message' => 'No active provider bindings. Discover provider resources under Settings → Integrations, then bind a compatible resource from this Digital Asset\'s Connections tab.',
                'runs' => [],
                'skipped' => [],
                'findings' => $emptyFindings,
                'collection_run_id' => null,
            ];
        }

        $engineBindings = $bindings->filter(
            fn (CoreAssetBinding $binding): bool => array_key_exists(
                (string) $binding->capability,
                CollectionSchedulingPolicyRegistry::CAPABILITY_PROVIDER,
            ),
        );
        $legacyBindings = $bindings->reject(
            fn (CoreAssetBinding $binding): bool => array_key_exists(
                (string) $binding->capability,
                CollectionSchedulingPolicyRegistry::CAPABILITY_PROVIDER,
            ),
        );

        $runs = [];
        $skipped = [];
        $failures = 0;
        $messages = [];
        $collectionRunId = null;
        $engineOk = true;

        if ($engineBindings->isNotEmpty()) {
            $context = $operatorRun instanceof Run ? ['operator_run_id' => $operatorRun->id] : [];
            $lifecycle = $this->collectionLifecycle->runNow($asset, $actor, $context);
            $collectionRun = $lifecycle->collectionRun;
            $collectionRunId = $collectionRun?->id;

            if ($collectionRun !== null && $operatorRun instanceof Run && data_get($collectionRun->metadata, 'operator_run_id') === null) {
                $collectionRun->forceFill([
                    'metadata' => array_merge($collectionRun->metadata ?? [], [
                        'operator_run_id' => $operatorRun->id,
                    ]),
                ])->save();
            }

            $engineOk = in_array($lifecycle->outcome, ['started', 'active_equivalent', 'no_work'], true);
            $messages[] = $lifecycle->message;
            if (! $engineOk) {
                $failures++;
                $skipped[] = [
                    'capability' => $engineBindings->pluck('capability')->unique()->implode(','),
                    'reason' => $lifecycle->message,
                ];
            }
        }

        foreach ($legacyBindings as $binding) {
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

        if ($engineBindings->isEmpty() && $runs === [] && $skipped !== []) {
            return [
                'ok' => false,
                'message' => 'No collectors ran. '.$this->summarizeSkipped($skipped),
                'runs' => [],
                'skipped' => $skipped,
                'findings' => $emptyFindings,
                'collection_run_id' => null,
            ];
        }

        $findings = $this->evaluateFindings($asset, $runs);

        $ok = $engineOk && $failures === 0;
        $message = trim(implode(' ', $messages));
        if ($runs !== []) {
            $message = trim($message.' '.count($runs).' collector run(s) finished.');
        }
        if ($skipped !== []) {
            $message = trim($message.' '.$this->summarizeSkipped($skipped));
        }
        if ($runs !== []) {
            $message = trim($message.' '.$this->summarizeFindings($findings));
        }

        return [
            'ok' => $ok,
            'message' => $message !== '' ? $message : 'No collection work started.',
            'runs' => $runs,
            'skipped' => $skipped,
            'findings' => $findings,
            'collection_run_id' => $collectionRunId,
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
