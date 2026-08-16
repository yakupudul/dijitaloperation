<?php

namespace App\Services\IntelligenceScheduling;

use App\Enums\Intelligence\IntelligenceTriggerSource;
use App\Enums\Intelligence\IntelligenceTriggerStatus;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\IntelligenceTrigger;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates durable, idempotent intelligence triggers (Prompt 63).
 */
final class IntelligenceTriggerService
{
    public function __construct(
        private readonly EvidenceAnalyticalFingerprintBuilder $fingerprints,
    ) {}

    /**
     * @param  list<Evidence>|null  $evidenceRows
     * @param  array<string, mixed>  $metadata
     */
    public function recordEvidenceAnalyticalChange(
        DigitalAsset $asset,
        IntelligenceTriggerSource $source,
        ?array $evidenceRows = null,
        ?User $actor = null,
        string $reason = 'EVIDENCE_ANALYTICAL_STATE_CHANGED',
        array $metadata = [],
    ): ?IntelligenceTrigger {
        $asset->loadMissing('brand');
        if ($asset->brand === null) {
            return null;
        }

        $rows = $evidenceRows ?? Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('is_canonical', true)
            ->orderBy('id')
            ->get()
            ->all();

        $set = $this->fingerprints->forEvidenceSet($rows);
        if ($set['definition_ids'] === [] && $source === IntelligenceTriggerSource::EvidenceAnalyticalStateChanged) {
            // Empty canonical set after canonicalize — still record for deterministic reevaluation of blocked states.
        }

        $triggerKey = implode(':', [
            'intel',
            $source->value,
            'asset',
            (string) $asset->id,
            $set['fingerprint'],
        ]);

        return DB::transaction(function () use ($asset, $source, $set, $triggerKey, $actor, $reason, $metadata): IntelligenceTrigger {
            $existing = IntelligenceTrigger::query()->where('trigger_key', $triggerKey)->first();
            if ($existing !== null) {
                return $existing;
            }

            return IntelligenceTrigger::query()->create([
                'customer_id' => (int) $asset->brand->customer_id,
                'brand_id' => (int) $asset->brand_id,
                'digital_asset_id' => (int) $asset->id,
                'source_kind' => $source,
                'source_identity' => 'digital_asset:'.$asset->id,
                'source_revision_fingerprint' => $set['fingerprint'],
                'trigger_key' => $triggerKey,
                'reason' => $reason,
                'status' => IntelligenceTriggerStatus::Pending,
                'changed_evidence_refs' => $set['refs'],
                'metadata' => array_merge($metadata, [
                    'definition_ids' => $set['definition_ids'],
                    'evidence_set_fingerprint' => $set['fingerprint'],
                ]),
                'created_by' => $actor?->id,
                'created_at' => now(),
            ]);
        });
    }

    public function recordFindingStateChanged(
        DigitalAsset $asset,
        string $findingRuleStableId,
        string $findingStateFingerprint,
        ?User $actor = null,
    ): IntelligenceTrigger {
        $asset->loadMissing('brand');
        $triggerKey = 'intel:FINDING_STATE_CHANGED:asset:'.$asset->id.':'.$findingRuleStableId.':'.$findingStateFingerprint;

        $existing = IntelligenceTrigger::query()->where('trigger_key', $triggerKey)->first();
        if ($existing !== null) {
            return $existing;
        }

        return IntelligenceTrigger::query()->create([
            'customer_id' => (int) $asset->brand->customer_id,
            'brand_id' => (int) $asset->brand_id,
            'digital_asset_id' => (int) $asset->id,
            'source_kind' => IntelligenceTriggerSource::FindingStateChanged,
            'source_identity' => 'finding_rule:'.$findingRuleStableId,
            'source_revision_fingerprint' => $findingStateFingerprint,
            'trigger_key' => $triggerKey,
            'reason' => 'FINDING_STATE_CHANGED',
            'status' => IntelligenceTriggerStatus::Pending,
            'changed_evidence_refs' => [],
            'metadata' => [
                'finding_rule_stable_id' => $findingRuleStableId,
            ],
            'created_by' => $actor?->id,
            'created_at' => now(),
        ]);
    }
}
