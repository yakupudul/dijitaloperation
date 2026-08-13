<?php

namespace App\Services\DataPool\Freshness;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\DataPool\Freshness\Support\DueCollectionItem;
use App\Services\DataPool\Freshness\Support\IncrementalStartResult;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Provider-neutral callable incremental refresh entrypoint.
 * Safe for operators and future Prompt 62 scheduler — no cron ownership here.
 */
final class StartIncrementalCollectionService
{
    public function __construct(
        private readonly DueCollectionQueryService $dueQuery,
        private readonly StartCollectionService $starter,
    ) {}

    /**
     * @param  list<int>  $bindingIds
     * @param  list<string>|null  $providerSources
     * @param  array<string, mixed>  $context
     */
    public function startForDigitalAsset(
        DigitalAsset $asset,
        ?User $requestedBy = null,
        array $bindingIds = [],
        ?array $providerSources = null,
        array $context = [],
    ): IncrementalStartResult {
        $filters = [
            'digital_asset_id' => $asset->id,
            'provider_sources' => $providerSources,
            'include_action_required' => true,
            'authorization_ready_by_binding_id' => $context['authorization_ready_by_binding_id'] ?? [],
            'integrity_blocked_by_dataset_resource' => $context['integrity_blocked_by_dataset_resource'] ?? [],
        ];

        $due = $this->dueQuery->query($filters);
        $executable = array_values(array_filter($due, static fn ($item): bool => ! $item->actionRequired));
        if ($executable === []) {
            return new IncrementalStartResult(
                outcome: 'data_current',
                message: 'DATA CURRENT — no incremental Dataset work is due for the requested scope.',
                decisions: array_map(static fn ($i) => $i->toArray(), $due),
            );
        }

        $resolvedBindingIds = $bindingIds !== []
            ? $bindingIds
            : array_values(array_unique(array_map(static fn ($i) => $i->coreAssetBindingId, $executable)));

        $fingerprint = $this->fingerprint($asset->id, $resolvedBindingIds, $providerSources, $executable);
        $active = $this->findActiveEquivalent($fingerprint);
        if ($active !== null) {
            return new IncrementalStartResult(
                outcome: 'active_equivalent',
                message: 'An equivalent incremental collection is already running.',
                collectionRun: $active,
                reusedExisting: true,
                decisions: array_map(static fn ($i) => $i->toArray(), $due),
            );
        }

        $familyIds = array_values(array_unique(array_map(
            static fn ($i) => $i->requestFamilyId,
            $executable,
        )));

        $request = new StartCollectionRequest(
            digitalAsset: $asset,
            triggerType: CollectionTriggerType::Incremental,
            requestedBy: $requestedBy,
            bindingIds: $resolvedBindingIds,
            requestFamilyIds: $familyIds,
            providerSources: $providerSources,
            dateRange: null,
            idempotencyKey: $this->resolveIdempotencyKey($fingerprint),
            forceRefresh: false,
            context: array_merge($context, [
                'collection_intent' => 'incremental_refresh',
                'collection_intent_label' => 'Incremental Refresh',
                'plan_fingerprint' => $fingerprint,
                'freshness_policy_registry_id' => 'MOXDOP_DATA_FRESHNESS_POLICY',
                'freshness_policy_version' => (int) config('moxdop-data-freshness.supported_freshness_policy_versions.0', 1),
                'incremental_due_items' => array_map(static fn ($i) => $i->toArray(), $executable),
                'allow_multi_asset_bindings' => (bool) ($context['allow_multi_asset_bindings'] ?? false),
            ]),
        );

        $run = $this->starter->start($request);

        $meta = $run->metadata ?? [];
        $meta['plan_fingerprint'] = $fingerprint;
        $meta['collection_intent'] = 'incremental_refresh';
        $meta['collection_intent_label'] = 'Incremental Refresh';
        $meta['freshness_policy_version'] = $request->context['freshness_policy_version'] ?? null;
        $run->forceFill(['metadata' => $meta])->save();

        Log::info('collection.incremental.started', [
            'collection_run_id' => $run->id,
            'digital_asset_id' => $asset->id,
            'datasets_due' => count($executable),
        ]);

        return new IncrementalStartResult(
            outcome: 'started',
            message: 'Incremental collection started in the background.',
            collectionRun: $run->fresh() ?? $run,
            decisions: array_map(static fn ($i) => $i->toArray(), $due),
        );
    }

    /**
     * System/scheduler-callable entry without requiring a browser session.
     *
     * @param  list<string>|null  $providerSources
     * @param  array<string, mixed>  $context
     */
    public function startForBindingIds(
        array $bindingIds,
        ?User $requestedBy = null,
        ?array $providerSources = null,
        array $context = [],
    ): IncrementalStartResult {
        if ($bindingIds === []) {
            return new IncrementalStartResult(
                outcome: 'data_current',
                message: 'DATA CURRENT — empty binding scope.',
            );
        }

        $binding = CoreAssetBinding::query()->with('digitalAsset')->whereIn('id', $bindingIds)->first();
        if ($binding === null || $binding->digitalAsset === null) {
            return new IncrementalStartResult(
                outcome: 'action_required',
                message: 'No active binding/digital asset found for incremental scope.',
            );
        }

        return $this->startForDigitalAsset(
            $binding->digitalAsset,
            $requestedBy,
            $bindingIds,
            $providerSources,
            array_merge($context, ['allow_multi_asset_bindings' => true]),
        );
    }

    /**
     * @param  list<int>  $bindingIds
     * @param  list<string>|null  $providerSources
     * @param  list<DueCollectionItem>  $executable
     */
    private function fingerprint(int $assetId, array $bindingIds, ?array $providerSources, array $executable): string
    {
        $payload = [
            'intent' => 'incremental_refresh',
            'digital_asset_id' => $assetId,
            'binding_ids' => array_values(array_unique($bindingIds)),
            'provider_sources' => $providerSources,
            'due' => array_map(static fn ($i) => [
                'dataset_id' => $i->datasetId,
                'binding_id' => $i->coreAssetBindingId,
                'date_range' => $i->dateRange,
                'reasons' => $i->reasons,
            ], $executable),
        ];

        return 'incr:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function findActiveEquivalent(string $fingerprint): ?CollectionRun
    {
        $terminal = [
            CollectionRunStatus::Completed->value,
            CollectionRunStatus::Failed->value,
            CollectionRunStatus::Partial->value,
            CollectionRunStatus::Cancelled->value,
            CollectionRunStatus::Skipped->value,
            CollectionRunStatus::NotEligible->value,
        ];

        return CollectionRun::query()
            ->where('trigger_type', CollectionTriggerType::Incremental)
            ->where('metadata->plan_fingerprint', $fingerprint)
            ->whereNotIn('status', $terminal)
            ->orderByDesc('id')
            ->first();
    }

    private function resolveIdempotencyKey(string $fingerprint): string
    {
        $prior = CollectionRun::query()->where('idempotency_key', $fingerprint)->first();
        if ($prior === null) {
            return $fingerprint;
        }

        if (! $prior->status->isTerminal()) {
            return $fingerprint;
        }

        return $fingerprint.':'.Str::uuid();
    }
}
