<?php

namespace App\Services\Analysis\Support;

use App\Models\Run;

/**
 * Outcome of one collected-facts analysis pass. Never implies live provider UAT.
 *
 * @phpstan-type FindingStats array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int}
 */
final class CollectedFactsAnalysisResult
{
    /**
     * @param  FindingStats  $findings
     */
    public function __construct(
        public readonly DigitalAssetType $assetType,
        public readonly bool $evaluated,
        public readonly string $skipReason,
        public readonly ?Run $run,
        public readonly array $findings,
        public readonly bool $evaluationSuccessful,
        /** @var list<string> */
        public readonly array $evaluatedRuleIds,
        /** @var array<string, mixed> */
        public readonly array $provenance,
    ) {}

    /**
     * @param  FindingStats  $findings
     * @param  list<string>  $evaluatedRuleIds
     * @param  array<string, mixed>  $provenance
     */
    public static function evaluated(
        DigitalAssetType $assetType,
        Run $run,
        array $findings,
        bool $evaluationSuccessful,
        array $evaluatedRuleIds,
        array $provenance,
    ): self {
        return new self(
            assetType: $assetType,
            evaluated: true,
            skipReason: '',
            run: $run,
            findings: $findings,
            evaluationSuccessful: $evaluationSuccessful,
            evaluatedRuleIds: $evaluatedRuleIds,
            provenance: $provenance,
        );
    }

    /**
     * @param  array<string, mixed>  $provenance
     */
    public static function skipped(DigitalAssetType $assetType, string $reason, array $provenance = []): self
    {
        return new self(
            assetType: $assetType,
            evaluated: false,
            skipReason: $reason,
            run: null,
            findings: [
                'opened' => 0,
                'updated' => 0,
                'reopened' => 0,
                'resolved' => 0,
                'recommendations' => 0,
            ],
            evaluationSuccessful: false,
            evaluatedRuleIds: [],
            provenance: $provenance,
        );
    }
}
