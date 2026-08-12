<?php

namespace App\Jobs\Async;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Run;
use App\Services\Async\AsyncOperationService;
use App\Support\Async\AsyncFailureClassifier;
use MoxDop\MetaAds\History\MetaHistoricalImportProgress;
use MoxDop\MetaAds\Models\MetaAdsAccountImportState;
use MoxDop\MetaAds\Models\MetaAdsDailyAction;
use MoxDop\MetaAds\Models\MetaAdsDailyFact;
use MoxDop\MetaAds\Models\MetaAdsEntity;

/**
 * Finalizes a parent Meta history import Run after per-account jobs finish.
 *
 * The overall outcome and the "N / M accounts ready" claim are derived from the
 * authoritative {@see MetaAdsAccountImportState} rows, not
 * from potentially-drifted Run metadata — so ready count / discovered count always
 * match the DB.
 */
final class MetaHistoricalImportFinalizer
{
    public function __construct(
        private readonly AsyncOperationService $async,
        private readonly MetaHistoricalImportProgress $progress,
    ) {}

    public function finalize(Run $run): void
    {
        if (in_array($run->status, ['completed', 'partial', 'failed'], true)) {
            return;
        }

        $integration = CoreIntegration::query()->find($run->core_integration_id);
        if ($integration === null) {
            return;
        }

        // Only finalize once every discovered account has reached a terminal state.
        if (! $this->progress->allAccountsTerminal($integration)) {
            return;
        }

        $meta = $run->metadata ?? [];
        $accountResults = is_array($meta['account_results'] ?? null) ? $meta['account_results'] : [];

        $outcome = $this->progress->deriveRunOutcome($integration);
        $final = $outcome['status'];
        $label = $outcome['label'];
        $discovered = $outcome['discovered'];
        $summaryRows = $this->progress->overallSummary($integration);

        $resourceIds = CoreExternalResource::query()
            ->where('integration_id', $integration->id)
            ->where('resource_type', MetaHistoricalImportProgress::RESOURCE_TYPE)
            ->where('status', CoreExternalResource::STATUS_AVAILABLE)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $summary = $this->buildSummary($resourceIds);
        $importedCount = $summaryRows['ready'] + $summaryRows['partial'];

        $this->async->markFinished($run, $final, $label, [
            'result_summary' => sprintf(
                '%d of %d Ad Account%s imported (%s daily facts, %s entities). History from %s through %s.',
                $importedCount,
                $discovered,
                $discovered === 1 ? '' : 's',
                number_format((float) $summary['daily_facts']),
                number_format((float) $summary['entities']),
                $summary['earliest'] ?? '—',
                $summary['latest'] ?? '—',
            ),
            'accounts_total' => $discovered,
            'accounts_done' => $this->progress->terminalCount($integration),
            'accounts_ready' => $summaryRows['ready'],
            'accounts_ready_label' => $summaryRows['accounts_ready_label'],
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
