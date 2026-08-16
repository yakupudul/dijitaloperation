<?php

namespace App\Support\CollectionScheduler;

use App\Enums\Collection\CollectionLifecycleIntent;

/**
 * Immutable collection lifecycle plan pinned at planning time (Prompt 62).
 * Execution must not silently expand windows when newer safe dates appear.
 */
final class ImmutableCollectionLifecyclePlan
{
    /**
     * @param  list<int>  $bindingIds
     * @param  list<string>  $requestFamilyIds
     * @param  list<string>  $providerSources
     * @param  list<array{start: ?string, end: ?string, reasons?: list<string>}>  $windows
     * @param  array<string, mixed>  $watermarkSnapshot
     * @param  array<string, mixed>  $safeFrontierSnapshot
     * @param  array<string, mixed>  $gapContext
     * @param  array<string, mixed>  $repairContext
     * @param  array<string, mixed>  $decision
     */
    public function __construct(
        public readonly string $planFingerprint,
        public readonly CollectionLifecycleIntent $intent,
        public readonly int $digitalAssetId,
        public readonly array $bindingIds,
        public readonly array $requestFamilyIds,
        public readonly array $providerSources,
        public readonly array $windows,
        public readonly ?string $timezone,
        public readonly string $policyIdentity,
        public readonly int $policyVersion,
        public readonly string $policyFingerprint,
        public readonly array $watermarkSnapshot,
        public readonly array $safeFrontierSnapshot,
        public readonly array $gapContext,
        public readonly array $repairContext,
        public readonly array $decision,
        public readonly string $createdAtUtc,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_fingerprint' => $this->planFingerprint,
            'intent' => $this->intent->value,
            'intent_label' => $this->intent->label(),
            'digital_asset_id' => $this->digitalAssetId,
            'binding_ids' => $this->bindingIds,
            'request_family_ids' => $this->requestFamilyIds,
            'provider_sources' => $this->providerSources,
            'windows' => $this->windows,
            'timezone' => $this->timezone,
            'policy_identity' => $this->policyIdentity,
            'policy_version' => $this->policyVersion,
            'policy_fingerprint' => $this->policyFingerprint,
            'watermark_snapshot' => $this->watermarkSnapshot,
            'safe_frontier_snapshot' => $this->safeFrontierSnapshot,
            'gap_context' => $this->gapContext,
            'repair_context' => $this->repairContext,
            'decision' => $this->decision,
            'created_at_utc' => $this->createdAtUtc,
        ];
    }
}
