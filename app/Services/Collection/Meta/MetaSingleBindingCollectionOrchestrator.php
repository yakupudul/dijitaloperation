<?php

namespace App\Services\Collection\Meta;

use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Collection\CollectionTriggerType;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Collection\CollectionPlanner;
use App\Services\Collection\StartCollectionService;
use App\Services\Collection\Support\StartCollectionRequest;
use App\Services\DataPool\Freshness\StartIncrementalCollectionService;
use App\Support\Integrations\Meta\MetaConnectorRegistry;
use App\Support\Integrations\Meta\MetaResourceType;
use App\Support\Integrations\ProviderRegistry;
use Illuminate\Support\Str;

/**
 * Starts collection for exactly one human-confirmed Meta Ad Account binding.
 *
 * First use plans the canonical initial backfill for that binding only. Once
 * initial coverage is satisfied, the same entrypoint falls through to the
 * shared freshness engine for an exact-binding incremental refresh.
 */
final class MetaSingleBindingCollectionOrchestrator
{
    public function __construct(
        private readonly MetaCollectionPreflightService $preflight,
        private readonly CollectionPlanner $planner,
        private readonly StartCollectionService $starter,
        private readonly StartIncrementalCollectionService $incremental,
    ) {}

    /**
     * @return array{outcome: string, message: string, collection_run: CollectionRun|null, mode: string}
     */
    public function start(CoreIntegration $integration, CoreAssetBinding $binding, User $actor): array
    {
        $binding = $binding->fresh(['digitalAsset', 'externalResource']) ?? $binding;
        $resource = $binding->externalResource;
        $asset = $binding->digitalAsset;

        if ($binding->status !== CoreAssetBinding::STATUS_ACTIVE
            || $binding->capability !== MetaConnectorRegistry::META_ADS
            || ! $asset instanceof DigitalAsset
            || $resource === null
            || (int) $resource->integration_id !== (int) $integration->id
            || $resource->provider !== ProviderRegistry::META
            || $resource->resource_type !== MetaResourceType::META_AD_ACCOUNT) {
            return [
                'outcome' => 'action_required',
                'message' => 'Bu Meta reklam hesabı veri güncellemesi için uygun değil.',
                'collection_run' => null,
                'mode' => 'none',
            ];
        }

        // Reuse Meta authorization / permission checks, but keep execution scope
        // pinned to this binding below.
        $readiness = $this->preflight->preflight($integration);
        $bindingRow = collect($readiness->bindings)
            ->first(fn (array $row): bool => (int) ($row['binding_id'] ?? 0) === (int) $binding->id);

        if (! is_array($bindingRow) || ($bindingRow['eligible'] ?? false) !== true) {
            return [
                'outcome' => 'action_required',
                'message' => (string) ($bindingRow['readiness_label'] ?? 'Meta reklam hesabı için yetki veya erişim kontrolü gerekiyor.'),
                'collection_run' => null,
                'mode' => 'none',
            ];
        }

        $initialRequest = new StartCollectionRequest(
            digitalAsset: $asset,
            triggerType: CollectionTriggerType::InitialBackfill,
            requestedBy: $actor,
            bindingIds: [(int) $binding->id],
            providerSources: ['META_ADS'],
            forceRefresh: false,
            context: [
                'meta_integration_id' => (int) $integration->id,
                'collection_intent' => 'meta_initial_backfill',
                'collection_intent_label' => 'Initial Meta Ads Account Collection',
                'allow_multi_asset_bindings' => false,
            ],
        );

        $plan = $this->planner->plan($initialRequest);
        $queued = array_values(array_filter(
            $plan['datasets'] ?? [],
            static fn (array $dataset): bool => ($dataset['planned_status'] ?? null) === CollectionRunStatus::Queued->value,
        ));

        if ($queued !== []) {
            $fingerprint = $this->initialFingerprint((int) $binding->id, $plan, $queued);
            $active = $this->activeInitialRun($fingerprint);
            if ($active instanceof CollectionRun) {
                return [
                    'outcome' => 'active_equivalent',
                    'message' => 'Bu reklam hesabının ilk veri toplaması zaten çalışıyor.',
                    'collection_run' => $active,
                    'mode' => 'initial',
                ];
            }

            $request = new StartCollectionRequest(
                digitalAsset: $asset,
                triggerType: CollectionTriggerType::InitialBackfill,
                requestedBy: $actor,
                bindingIds: [(int) $binding->id],
                providerSources: ['META_ADS'],
                dateRange: $this->envelopeDateRange($queued),
                idempotencyKey: $this->resolveInitialIdempotencyKey($fingerprint),
                forceRefresh: false,
                context: [
                    'meta_integration_id' => (int) $integration->id,
                    'collection_intent' => 'meta_initial_backfill',
                    'collection_intent_label' => 'Initial Meta Ads Account Collection',
                    'allow_multi_asset_bindings' => false,
                    'plan_fingerprint' => $fingerprint,
                    'binding_scope' => [(int) $binding->id],
                ],
            );

            $run = $this->starter->start($request);

            return [
                'outcome' => 'started',
                'message' => 'Reklam hesabı bağlandı. İlk veriler arka planda alınmaya başladı.',
                'collection_run' => $run,
                'mode' => 'initial',
            ];
        }

        $incremental = $this->incremental->startForBindingIds(
            [(int) $binding->id],
            $actor,
            ['META_ADS'],
            [
                'authorization_ready_by_binding_id' => [(int) $binding->id => true],
                'collection_intent' => 'incremental_refresh',
                'collection_intent_label' => 'Meta Ads Account Refresh',
                'idempotency_suffix' => 'binding:'.(int) $binding->id,
            ],
        );

        return [
            'outcome' => $incremental->outcome,
            'message' => match ($incremental->outcome) {
                'started' => 'Bu reklam hesabının yeni verileri alınmaya başladı.',
                'active_equivalent' => 'Bu reklam hesabı zaten güncelleniyor.',
                'data_current' => 'Bu reklam hesabının verileri zaten güncel.',
                default => $incremental->message,
            },
            'collection_run' => $incremental->collectionRun,
            'mode' => 'incremental',
        ];
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<array<string, mixed>>  $queued
     */
    private function initialFingerprint(int $bindingId, array $plan, array $queued): string
    {
        return 'meta-binding-ib:'.hash('sha256', json_encode([
            'binding_id' => $bindingId,
            'registry_version' => $plan['contract_registry_version'] ?? null,
            'datasets' => array_map(static fn (array $dataset): array => [
                'dataset' => $dataset['dataset_contract_id'] ?? null,
                'family' => $dataset['request_family_id'] ?? null,
                'range' => $dataset['date_range'] ?? null,
            ], $queued),
        ], JSON_THROW_ON_ERROR));
    }

    private function activeInitialRun(string $fingerprint): ?CollectionRun
    {
        return CollectionRun::query()
            ->where('metadata->plan_fingerprint', $fingerprint)
            ->whereNotIn('status', [
                CollectionRunStatus::Completed->value,
                CollectionRunStatus::Failed->value,
                CollectionRunStatus::Partial->value,
                CollectionRunStatus::Cancelled->value,
                CollectionRunStatus::Skipped->value,
                CollectionRunStatus::NotEligible->value,
            ])
            ->orderByDesc('id')
            ->first();
    }

    private function resolveInitialIdempotencyKey(string $fingerprint): string
    {
        $prior = CollectionRun::query()->where('idempotency_key', $fingerprint)->first();
        if ($prior === null || ! $prior->status->isTerminal()) {
            return $fingerprint;
        }

        return $fingerprint.':'.Str::uuid();
    }

    /**
     * @param  list<array<string, mixed>>  $datasets
     * @return array{start: string, end: string}|null
     */
    private function envelopeDateRange(array $datasets): ?array
    {
        $starts = [];
        $ends = [];

        foreach ($datasets as $dataset) {
            $range = $dataset['date_range'] ?? null;
            if (! is_array($range) || empty($range['start']) || empty($range['end'])) {
                continue;
            }
            $starts[] = (string) $range['start'];
            $ends[] = (string) $range['end'];
        }

        if ($starts === [] || $ends === []) {
            return null;
        }

        return ['start' => min($starts), 'end' => max($ends)];
    }
}
