<?php

namespace App\Services\CollectionScheduler;

use App\Enums\Collection\CollectionLifecycleIntent;
use App\Enums\Collection\PlanDisposition;
use App\Enums\Collection\IncrementalWorkReason;
use App\Enums\CollectionScheduleStatus;
use App\Enums\DataPool\FreshnessState;
use App\Models\CollectionSchedule;
use App\Models\CoreAssetBinding;
use App\Models\DataPool\DatasetMaterialization;
use App\Models\DigitalAsset;
use App\Services\Collection\DataContractRegistryLoader;
use App\Services\DataPool\Freshness\DatasetWatermarkCalculator;
use App\Services\DataPool\Freshness\IncrementalCoveragePlanner;
use App\Support\CollectionScheduler\CollectionPlanningDecision;
use App\Support\CollectionScheduler\ImmutableCollectionLifecyclePlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Deterministic Collection Lifecycle Planner (Prompt 62).
 * Chooses INITIAL_BACKFILL / CATCH_UP / INCREMENTAL / LATE_DATA_REPAIR / NO_WORK / BLOCKED
 * from Resource × Dataset canonical state. Never calls providers. Never uses AI.
 */
final class CollectionLifecyclePlanner
{
    public function __construct(
        private readonly CollectionSchedulingPolicyRegistry $policies,
        private readonly DataContractRegistryLoader $contracts,
        private readonly IncrementalCoveragePlanner $incrementalPlanner,
        private readonly LatestSafeReportingWindowResolver $safeFrontier,
        private readonly DatasetWatermarkCalculator $watermarks = new DatasetWatermarkCalculator,
    ) {}

    /**
     * @param  array{
     *   authorization_ready_by_binding_id?: array<int, bool>,
     *   integrity_blocked_by_dataset_resource?: array<string, bool>,
     *   collection_enabled?: bool,
     *   schedule?: ?CollectionSchedule,
     *   manual?: bool,
     *   clock_now?: ?\DateTimeInterface
     * }  $context
     */
    public function planForDigitalAsset(DigitalAsset $asset, array $context = []): CollectionPlanningDecision
    {
        $this->policies->version();
        $this->contracts->load();

        if (array_key_exists('collection_enabled', $context) && $context['collection_enabled'] === false) {
            return $this->blocked(CollectionPlanningBlockReason::CollectionDisabled, 'COLLECTION_DISABLED');
        }

        $schedule = $context['schedule'] ?? null;
        if ($schedule instanceof CollectionSchedule && $schedule->status === CollectionScheduleStatus::Paused) {
            return $this->blocked(CollectionPlanningBlockReason::SchedulePaused, 'SCHEDULE_PAUSED');
        }
        if ($schedule instanceof CollectionSchedule && $schedule->status === CollectionScheduleStatus::Archived) {
            return $this->blocked(CollectionPlanningBlockReason::CollectionDisabled, 'SCHEDULE_ARCHIVED');
        }

        $bindings = $this->loadBindings((int) $asset->id);
        if ($bindings === []) {
            return $this->blocked(CollectionPlanningBlockReason::ResourceUnbound, 'RESOURCE_UNBOUND');
        }

        $materializations = $this->loadMaterializations($bindings);
        $authByBinding = $context['authorization_ready_by_binding_id'] ?? [];
        $integrityMap = $context['integrity_blocked_by_dataset_resource'] ?? [];
        $manual = (bool) ($context['manual'] ?? false);
        $clockNow = $context['clock_now'] ?? null;

        $candidates = [];
        $datasetDecisions = [];
        $allBlocked = true;
        $firstBlock = null;

        foreach ($bindings as $binding) {
            $capability = (string) $binding->capability;
            $provider = $this->policies->providerForCapability($capability);
            if ($provider === null) {
                continue;
            }

            $authReady = $authByBinding[(int) $binding->id] ?? true;
            if (! $authReady) {
                $firstBlock ??= [
                    'reason' => CollectionPlanningBlockReason::CredentialInvalid,
                    'message' => 'CREDENTIAL_INVALID',
                ];
                $datasetDecisions[] = [
                    'binding_id' => (int) $binding->id,
                    'provider' => $provider,
                    'action' => CollectionLifecycleAction::Blocked->value,
                    'reason' => 'CREDENTIAL_INVALID',
                ];

                continue;
            }

            foreach ($this->familiesForProvider($provider) as $family) {
                $datasetId = $this->policies->primaryDatasetForFamily((string) $family['id']);
                if ($datasetId === null || $datasetId === '') {
                    continue;
                }

                $policy = $this->policies->policy($datasetId);
                if ($policy === null) {
                    $firstBlock ??= [
                        'reason' => CollectionPlanningBlockReason::PolicyNotConfigured,
                        'message' => 'POLICY_NOT_CONFIGURED',
                    ];
                    $datasetDecisions[] = [
                        'binding_id' => (int) $binding->id,
                        'dataset_id' => $datasetId,
                        'action' => CollectionLifecycleAction::Blocked->value,
                        'reason' => 'POLICY_NOT_CONFIGURED',
                    ];

                    continue;
                }

                if (! $policy->eligible) {
                    $datasetDecisions[] = [
                        'binding_id' => (int) $binding->id,
                        'dataset_id' => $datasetId,
                        'action' => CollectionLifecycleAction::NoWork->value,
                        'reason' => $policy->ineligibilityReason ?? 'NOT_ELIGIBLE',
                    ];

                    continue;
                }

                $assetId = (int) $asset->id;
                $resourceId = $binding->external_resource_id !== null ? (int) $binding->external_resource_id : null;
                $mat = $this->findMaterialization($materializations, $datasetId, $assetId, $resourceId);
                $tz = $this->resourceTimezone($binding);
                $frontier = $this->safeFrontier->resolve($datasetId, $tz, $clockNow);
                $integrityKey = $datasetId.'|'.$assetId.'|'.($resourceId ?? 'null');

                $incremental = $this->incrementalPlanner->planDataset($datasetId, $mat, [
                    'authorization_ready' => true,
                    'integrity_blocked' => (bool) ($integrityMap[$integrityKey] ?? false),
                    'reporting_timezone' => $tz,
                ]);

                $row = [
                    'binding_id' => (int) $binding->id,
                    'dataset_id' => $datasetId,
                    'request_family_id' => (string) $family['id'],
                    'provider_or_source' => $provider,
                    'external_resource_id' => $resourceId,
                    'reporting_timezone' => $tz,
                    'policy_version' => $policy->policyVersion,
                    'policy_fingerprint' => $policy->policyFingerprint,
                    'safe_frontier' => $frontier->toArray(),
                    'incremental' => $incremental->toArray(),
                    'freshness_state' => $incremental->freshnessState->value,
                ];

                if ($incremental->reasonSummary === 'initial_backfill_required_before_incremental'
                    || ($mat === null && $incremental->planDisposition === PlanDisposition::NotEligible)) {
                    $allBlocked = false;
                    $candidates[] = $this->candidate(
                        CollectionLifecycleIntent::InitialBackfill,
                        $row,
                        $policy->policyVersion,
                        $policy->policyFingerprint,
                        windows: [],
                        watermark: $mat !== null ? $this->watermarks->calculate($mat, $policy->raw, $tz)->toArray() : [],
                        frontier: $frontier->toArray(),
                    );
                    $datasetDecisions[] = array_merge($row, [
                        'action' => CollectionLifecycleAction::InitialBackfill->value,
                        'reason' => 'initial_backfill_required',
                    ]);

                    continue;
                }

                if ($incremental->freshnessState === FreshnessState::IntegrityBlocked) {
                    $firstBlock ??= [
                        'reason' => CollectionPlanningBlockReason::IntegrityBlocked,
                        'message' => 'INTEGRITY_BLOCKED',
                    ];
                    $datasetDecisions[] = array_merge($row, [
                        'action' => CollectionLifecycleAction::Blocked->value,
                        'reason' => 'INTEGRITY_BLOCKED',
                    ]);

                    continue;
                }

                if ($incremental->freshnessState === FreshnessState::ProviderLimited) {
                    $firstBlock ??= [
                        'reason' => CollectionPlanningBlockReason::ProviderLimited,
                        'message' => 'PROVIDER_LIMITED',
                    ];
                    $datasetDecisions[] = array_merge($row, [
                        'action' => CollectionLifecycleAction::Blocked->value,
                        'reason' => 'PROVIDER_LIMITED',
                    ]);

                    continue;
                }

                if ($incremental->freshnessState === FreshnessState::ActionRequired) {
                    $firstBlock ??= [
                        'reason' => CollectionPlanningBlockReason::ActionRequired,
                        'message' => 'ACTION_REQUIRED',
                    ];
                    $datasetDecisions[] = array_merge($row, [
                        'action' => CollectionLifecycleAction::Blocked->value,
                        'reason' => 'ACTION_REQUIRED',
                    ]);

                    continue;
                }

                if (! $incremental->executable) {
                    $datasetDecisions[] = array_merge($row, [
                        'action' => CollectionLifecycleAction::NoWork->value,
                        'reason' => $incremental->reasonSummary,
                    ]);

                    continue;
                }

                $allBlocked = false;
                $intent = $this->intentFromReasons($incremental->reasons, $manual);
                $watermark = $this->watermarks->calculate($mat, $policy->raw, $tz)->toArray();
                $candidates[] = $this->candidate(
                    $intent,
                    $row,
                    $policy->policyVersion,
                    $policy->policyFingerprint,
                    windows: $incremental->requestedIntervals,
                    watermark: $watermark,
                    frontier: $frontier->toArray(),
                );
                $datasetDecisions[] = array_merge($row, [
                    'action' => $intent->value,
                    'reason' => $incremental->reasonSummary,
                ]);
            }
        }

        if ($candidates === []) {
            if ($allBlocked && $firstBlock !== null) {
                return $this->blocked($firstBlock['reason'], $firstBlock['message'], $datasetDecisions);
            }

            return new CollectionPlanningDecision(
                action: CollectionLifecycleAction::NoWork,
                reason: 'NO_NEW_SAFE_INTERVAL',
                policyVersion: $this->policies->version(),
                policyFingerprint: $this->policies->fingerprint(),
                windows: [],
                limitations: ['no_executable_dataset_work' => true],
                intent: null,
                blockReason: CollectionPlanningBlockReason::NoSafeInterval,
                datasetDecisions: $datasetDecisions,
                snapshots: [],
            );
        }

        usort($candidates, static function (array $a, array $b): int {
            $rank = $a['intent']->priorityRank() <=> $b['intent']->priorityRank();
            if ($rank !== 0) {
                return $rank;
            }

            return strcmp((string) $a['dataset_id'], (string) $b['dataset_id']);
        });

        $primaryIntent = $candidates[0]['intent'];
        $selected = array_values(array_filter(
            $candidates,
            static fn (array $c): bool => $c['intent'] === $primaryIntent,
        ));

        // Only one lifecycle intent per plan — do not mix Backfill with Repair in one execution.
        $windows = [];
        $bindingIds = [];
        $familyIds = [];
        $providers = [];
        $watermarks = [];
        $frontiers = [];
        $gaps = [];
        $repairs = [];
        $policyVersion = $this->policies->version();
        $policyFingerprint = $this->policies->fingerprint();

        foreach ($selected as $item) {
            $bindingIds[] = (int) $item['binding_id'];
            $familyIds[] = (string) $item['request_family_id'];
            $providers[] = (string) $item['provider_or_source'];
            foreach ($item['windows'] as $window) {
                $windows[] = $window;
            }
            $watermarks[$item['dataset_id']] = $item['watermark'];
            $frontiers[$item['dataset_id']] = $item['frontier'];
            if ($primaryIntent === CollectionLifecycleIntent::CatchUp) {
                $gaps[$item['dataset_id']] = $item['windows'];
            }
            if ($primaryIntent === CollectionLifecycleIntent::LateDataRepair) {
                $repairs[$item['dataset_id']] = $item['windows'];
            }
            $policyVersion = (int) $item['policy_version'];
            $policyFingerprint = (string) $item['policy_fingerprint'];
        }

        $action = match ($primaryIntent) {
            CollectionLifecycleIntent::InitialBackfill => CollectionLifecycleAction::InitialBackfill,
            CollectionLifecycleIntent::CatchUp => CollectionLifecycleAction::CatchUp,
            CollectionLifecycleIntent::Incremental => CollectionLifecycleAction::Incremental,
            CollectionLifecycleIntent::LateDataRepair => CollectionLifecycleAction::LateDataRepair,
            CollectionLifecycleIntent::Manual => CollectionLifecycleAction::Incremental,
        };

        return new CollectionPlanningDecision(
            action: $action,
            reason: $primaryIntent->value,
            policyVersion: $policyVersion,
            policyFingerprint: $policyFingerprint,
            windows: $windows,
            limitations: [
                'max_catch_up_bounded' => true,
                'dataforseo_routine_scheduled' => false,
            ],
            intent: $manual && $primaryIntent !== CollectionLifecycleIntent::InitialBackfill
                ? CollectionLifecycleIntent::Manual
                : $primaryIntent,
            blockReason: null,
            datasetDecisions: $datasetDecisions,
            snapshots: [
                'watermark' => $watermarks,
                'safe_frontier' => $frontiers,
                'gap_context' => $gaps,
                'repair_context' => $repairs,
            ],
            bindingIds: array_values(array_unique($bindingIds)),
            requestFamilyIds: array_values(array_unique($familyIds)),
            providerSources: array_values(array_unique($providers)),
        );
    }

    public function toImmutablePlan(
        DigitalAsset $asset,
        CollectionPlanningDecision $decision,
        ?string $timezone = null,
    ): ?ImmutableCollectionLifecyclePlan {
        if (! $decision->isExecutable() || $decision->intent === null) {
            return null;
        }

        $payload = [
            'intent' => $decision->intent->value,
            'digital_asset_id' => (int) $asset->id,
            'binding_ids' => $decision->bindingIds,
            'request_family_ids' => $decision->requestFamilyIds,
            'provider_sources' => $decision->providerSources,
            'windows' => $decision->windows,
            'policy_version' => $decision->policyVersion,
            'policy_fingerprint' => $decision->policyFingerprint,
            'snapshots' => $decision->snapshots,
        ];

        return new ImmutableCollectionLifecyclePlan(
            planFingerprint: 'life:'.hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            intent: $decision->intent,
            digitalAssetId: (int) $asset->id,
            bindingIds: $decision->bindingIds,
            requestFamilyIds: $decision->requestFamilyIds,
            providerSources: $decision->providerSources,
            windows: $decision->windows,
            timezone: $timezone,
            policyIdentity: $this->policies->identity(),
            policyVersion: $decision->policyVersion,
            policyFingerprint: $decision->policyFingerprint,
            watermarkSnapshot: $decision->snapshots['watermark'] ?? [],
            safeFrontierSnapshot: $decision->snapshots['safe_frontier'] ?? [],
            gapContext: $decision->snapshots['gap_context'] ?? [],
            repairContext: $decision->snapshots['repair_context'] ?? [],
            decision: $decision->toArray(),
            createdAtUtc: CarbonImmutable::now('UTC')->toIso8601String(),
        );
    }

    /**
     * @param  list<string>  $reasons
     */
    public function intentFromReasons(array $reasons, bool $manual = false): CollectionLifecycleIntent
    {
        if ($manual) {
            // Manual "run due work" still labels the underlying work; Manual intent reserved for explicit operator override.
        }

        if (in_array(IncrementalWorkReason::GapRecovery->value, $reasons, true)
            || in_array(IncrementalWorkReason::CatchUp->value, $reasons, true)) {
            return CollectionLifecycleIntent::CatchUp;
        }

        if (in_array(IncrementalWorkReason::NewCoverage->value, $reasons, true)
            || in_array(IncrementalWorkReason::SnapshotRefresh->value, $reasons, true)
            || in_array(IncrementalWorkReason::ContractUpgrade->value, $reasons, true)) {
            return CollectionLifecycleIntent::Incremental;
        }

        if (in_array(IncrementalWorkReason::LateDataReprocess->value, $reasons, true)) {
            return CollectionLifecycleIntent::LateDataRepair;
        }

        return CollectionLifecycleIntent::Incremental;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  list<array{start: ?string, end: ?string, reasons?: list<string>}>  $windows
     * @param  array<string, mixed>  $watermark
     * @param  array<string, mixed>  $frontier
     * @return array<string, mixed>
     */
    private function candidate(
        CollectionLifecycleIntent $intent,
        array $row,
        int $policyVersion,
        string $policyFingerprint,
        array $windows,
        array $watermark,
        array $frontier,
    ): array {
        return [
            'intent' => $intent,
            'binding_id' => $row['binding_id'],
            'dataset_id' => $row['dataset_id'],
            'request_family_id' => $row['request_family_id'],
            'provider_or_source' => $row['provider_or_source'],
            'policy_version' => $policyVersion,
            'policy_fingerprint' => $policyFingerprint,
            'windows' => $windows,
            'watermark' => $watermark,
            'frontier' => $frontier,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $datasetDecisions
     */
    private function blocked(
        CollectionPlanningBlockReason $reason,
        string $message,
        array $datasetDecisions = [],
    ): CollectionPlanningDecision {
        return new CollectionPlanningDecision(
            action: CollectionLifecycleAction::Blocked,
            reason: $message,
            policyVersion: $this->policies->version(),
            policyFingerprint: $this->policies->fingerprint(),
            windows: [],
            limitations: ['block_reason' => $reason->value],
            intent: null,
            blockReason: $reason,
            datasetDecisions: $datasetDecisions,
        );
    }

    /**
     * @return list<CoreAssetBinding>
     */
    private function loadBindings(int $digitalAssetId): array
    {
        return CoreAssetBinding::query()
            ->with(['externalResource'])
            ->where('digital_asset_id', $digitalAssetId)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn('capability', array_keys(CollectionSchedulingPolicyRegistry::CAPABILITY_PROVIDER))
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  list<CoreAssetBinding>  $bindings
     * @return Collection<int, DatasetMaterialization>
     */
    private function loadMaterializations(array $bindings): Collection
    {
        $assetIds = [];
        $resourceIds = [];
        foreach ($bindings as $binding) {
            $assetIds[] = (int) $binding->digital_asset_id;
            if ($binding->external_resource_id !== null) {
                $resourceIds[] = (int) $binding->external_resource_id;
            }
        }

        if ($assetIds === []) {
            return collect();
        }

        return DatasetMaterialization::query()
            ->whereIn('digital_asset_id', array_values(array_unique($assetIds)))
            ->when($resourceIds !== [], fn ($q) => $q->whereIn('external_resource_id', array_values(array_unique($resourceIds))))
            ->get();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function familiesForProvider(string $provider): array
    {
        $out = [];
        foreach ($this->contracts->requestFamilies() as $family) {
            if ((string) ($family['provider_or_source'] ?? '') !== $provider) {
                continue;
            }
            $status = (string) ($family['status'] ?? '');
            if (in_array($status, ['DEFERRED', 'UNSUPPORTED', 'UNAVAILABLE', 'DEMO_ONLY'], true)) {
                continue;
            }
            if (in_array((string) ($family['id'] ?? ''), ['GSC_RF_APPEARANCE_DAILY', 'GSC_RF_URL_INSPECTION'], true)) {
                continue;
            }
            $out[] = $family;
        }

        return $out;
    }

    /**
     * @param  Collection<int, DatasetMaterialization>  $materializations
     */
    private function findMaterialization(
        Collection $materializations,
        string $datasetId,
        int $digitalAssetId,
        ?int $externalResourceId,
    ): ?DatasetMaterialization {
        return $materializations->first(function (DatasetMaterialization $row) use ($datasetId, $digitalAssetId, $externalResourceId): bool {
            if ($row->dataset_id !== $datasetId || (int) $row->digital_asset_id !== $digitalAssetId) {
                return false;
            }
            if ($externalResourceId === null) {
                return $row->external_resource_id === null;
            }

            return (int) $row->external_resource_id === $externalResourceId;
        });
    }

    private function resourceTimezone(CoreAssetBinding $binding): ?string
    {
        $meta = is_array($binding->externalResource?->metadata) ? $binding->externalResource->metadata : [];
        foreach (['timezone', 'timezone_name', 'timeZone', 'time_zone'] as $key) {
            if (is_string($meta[$key] ?? null) && $meta[$key] !== '') {
                return (string) $meta[$key];
            }
        }

        return null;
    }
}
