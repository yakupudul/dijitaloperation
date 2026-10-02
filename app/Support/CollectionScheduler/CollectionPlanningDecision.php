<?php

namespace App\Support\CollectionScheduler;

use App\Enums\Collection\CollectionLifecycleAction;
use App\Enums\Collection\CollectionLifecycleIntent;
use App\Enums\Collection\CollectionPlanningBlockReason;

/**
 * Deterministic lifecycle planning decision (Prompt 62).
 * Same canonical state + policy + clock → same decision. No AI. No numeric score.
 */
final class CollectionPlanningDecision
{
    /**
     * @param  list<array{start: ?string, end: ?string, reasons?: list<string>}>  $windows
     * @param  list<array<string, mixed>>  $datasetDecisions
     * @param  array<string, mixed>  $limitations
     * @param  array<string, mixed>  $snapshots
     */
    public function __construct(
        public readonly CollectionLifecycleAction $action,
        public readonly string $reason,
        public readonly int $policyVersion,
        public readonly string $policyFingerprint,
        public readonly array $windows,
        public readonly array $limitations = [],
        public readonly ?CollectionLifecycleIntent $intent = null,
        public readonly ?CollectionPlanningBlockReason $blockReason = null,
        public readonly array $datasetDecisions = [],
        public readonly array $snapshots = [],
        public readonly array $bindingIds = [],
        public readonly array $requestFamilyIds = [],
        public readonly array $providerSources = [],
    ) {}

    public function isExecutable(): bool
    {
        return in_array($this->action, [
            CollectionLifecycleAction::InitialBackfill,
            CollectionLifecycleAction::CatchUp,
            CollectionLifecycleAction::Incremental,
            CollectionLifecycleAction::LateDataRepair,
        ], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action->value,
            'reason' => $this->reason,
            'policy_version' => $this->policyVersion,
            'policy_fingerprint' => $this->policyFingerprint,
            'windows' => $this->windows,
            'limitations' => $this->limitations,
            'intent' => $this->intent?->value,
            'block_reason' => $this->blockReason?->value,
            'dataset_decisions' => $this->datasetDecisions,
            'snapshots' => $this->snapshots,
            'binding_ids' => $this->bindingIds,
            'request_family_ids' => $this->requestFamilyIds,
            'provider_sources' => $this->providerSources,
        ];
    }
}
