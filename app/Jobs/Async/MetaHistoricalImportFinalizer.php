<?php

namespace App\Jobs\Async;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncFailureClassifier;
use MoxDop\MetaAds\Models\MetaAdsDailyAction;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use MoxDop\MetaAds\Models\MetaAdsEntity;

/**
 * Finalizes a parent Meta history import Run after per-account jobs finish.
 */
final class MetaHistoricalImportFinalizer
{
    public function __construct(private readonly AsyncOperationService $async) {}

    public function finalize(Run $run): void
    {
        if (in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        $meta = $run->metadata ?? [];
        $accountResults = is_array($meta['account_results'] ?? null) ? $meta['account_results'] : [];
        $accountsTotal = (int) ($meta['accounts_total'] ?? count($accountResults));

        if ($accountResults === [] || count($accountResults) < $accountsTotal) {
            return;
        }

        $statuses = collect($accountResults)->map(fn (array $r): string => (string) ($r['status'] ?? 'failed'));
        $anyFailed = $statuses->contains('failed');
        $anyPartial = $statuses->contains('partial');
        $successCount = $statuses->filter(fn (string $s): bool => in_array($s, ['complete', 'completed', 'partial'], true))->count();
        $hardSuccess = $statuses->filter(fn (string $s): bool => in_array($s, ['complete', 'completed'], true))->count();

        [$final, $label] = match (true) {
            $hardSuccess === 0 && $successCount === 0 => ['failed', 'Meta history import failed'],
            $anyFailed || $anyPartial || $hardSuccess < $accountsTotal => ['partial', 'Meta history import finished with gaps'],
            default => ['completed', 'Meta history import complete'],
        };

        $integration = CoreIntegration::query()->find($run->core_integration_id);
        $resourceIds = collect($accountResults)
            ->keys()
            ->map(function (string $externalId) use ($run): ?int {
                return CoreExternalResource::query()
                    ->where('integration_id', $run->core_integration_id)
                    ->where('external_id', $externalId)
                    ->value('id');
            })
            ->filter()
            ->values()
            ->all();

        $summary = $this->buildSummary($resourceIds);

        $this->async->markFinished($run, $final, $label, [
            'result_summary' => sprintf(
                '%d of %d Ad Account%s imported (%s daily facts, %s entities). History from %s through %s.',
                $successCount,
                $accountsTotal,
                $accountsTotal === 1 ? '' : 's',
                number_format((float) $summary['daily_facts']),
                number_format((float) $summary['entities']),
                $summary['earliest'] ?? '—',
                $summary['latest'] ?? '—',
            ),
            'account_results' => $accountResults,
            'history_summary' => $summary,
            'failure_category' => $final === 'failed' ? AsyncFailureClassifier::VALIDATION : null,
            'failure_summary' => $final === 'failed' ? 'No Ad Account could be imported. See per-account results.' : null,
            'retryable' => $final !== 'completed',
            'current_account' => null,
            'current_phase' => $final,
        ]);
    }

    /**
     * @param  list<int>  $resourceIds
     * @return array{earliest: ?string, latest: ?string, entities: int, daily_facts: int, daily_actions: int, creatives: int}
     */
    private function buildSummary(array $resourceIds): array
    {
        if ($resourceIds === []) {
            return [
                'earliest' => null,
                'latest' => null,
                'entities' => 0,
                'daily_facts' => 0,
                'daily_actions' => 0,
                'creatives' => 0,
            ];
        }

        return [
            'earliest' => MetaAdsDailyFact::query()->whereIn('core_external_resource_id', $resourceIds)->min('date'),
            'latest' => MetaAdsDailyFact::query()->whereIn('core_external_resource_id', $resourceIds)->max('date'),
            'entities' => MetaAdsEntity::query()->whereIn('core_external_resource_id', $resourceIds)->count(),
            'daily_facts' => MetaAdsDailyFact::query()->whereIn('core_external_resource_id', $resourceIds)->count(),
            'daily_actions' => MetaAdsDailyAction::query()->whereIn('core_external_resource_id', $resourceIds)->count(),
            'creatives' => MetaAdsEntity::query()
                ->whereIn('core_external_resource_id', $resourceIds)
                ->where('entity_type', 'creative')
                ->count(),
        ];
    }
}
