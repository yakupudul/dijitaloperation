<?php

namespace App\Events;

use App\Models\DigitalAsset;
use App\Models\Run;
use App\Support\Findings\RuleEvaluationResult;
use DateTimeInterface;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Fired after FindingLifecycleService persists evaluation results (after commit).
 */
final class FindingEvaluationCompleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  list<string>  $evaluatedRuleIds
     * @param  list<string>  $matchedFingerprints
     * @param  array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int}  $stats
     */
    public function __construct(
        public readonly DigitalAsset $asset,
        public readonly string $sourceModule,
        public readonly Run $run,
        public readonly bool $evaluationSuccessful,
        public readonly array $evaluatedRuleIds,
        public readonly array $matchedFingerprints,
        public readonly DateTimeInterface $observedAt,
        public readonly array $stats,
    ) {}

    /**
     * @param  array{opened: int, updated: int, reopened: int, resolved: int, recommendations: int}  $stats
     */
    public static function fromResult(RuleEvaluationResult $result, array $stats): self
    {
        $matched = [];
        foreach ($result->matches as $match) {
            $matched[] = $match->fingerprint;
        }

        return new self(
            asset: $result->asset,
            sourceModule: $result->sourceModule,
            run: $result->run,
            evaluationSuccessful: $result->evaluationSuccessful,
            evaluatedRuleIds: $result->evaluatedRuleIds,
            matchedFingerprints: $matched,
            observedAt: $result->observedAt,
            stats: $stats,
        );
    }
}
