<?php

namespace App\Services\DataPool\Freshness\Support;

use App\Enums\Collection\PlanDisposition;
use App\Enums\DataPool\FreshnessState;

final class IncrementalDatasetDecision
{
    /**
     * @param  list<array{
     *   start: ?string,
     *   end: ?string,
     *   reasons: list<string>
     * }>  $requestedIntervals
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $datasetId,
        public readonly FreshnessState $freshnessState,
        public readonly PlanDisposition $planDisposition,
        public readonly bool $executable,
        public readonly ?array $dateRange,
        public readonly array $requestedIntervals,
        public readonly array $reasons,
        public readonly int $policyVersion,
        public readonly string $reasonSummary,
        public readonly array $details = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'dataset_id' => $this->datasetId,
            'freshness_state' => $this->freshnessState->value,
            'plan_disposition' => $this->planDisposition->value,
            'executable' => $this->executable,
            'date_range' => $this->dateRange,
            'requested_intervals' => $this->requestedIntervals,
            'reasons' => $this->reasons,
            'policy_version' => $this->policyVersion,
            'reason_summary' => $this->reasonSummary,
            'details' => $this->details,
        ];
    }

    public static function alreadyCurrent(
        string $datasetId,
        FreshnessState $state,
        int $policyVersion,
        string $reason,
        array $details = [],
    ): self {
        return new self(
            datasetId: $datasetId,
            freshnessState: $state,
            planDisposition: PlanDisposition::AlreadySatisfied,
            executable: false,
            dateRange: null,
            requestedIntervals: [],
            reasons: [],
            policyVersion: $policyVersion,
            reasonSummary: $reason,
            details: $details,
        );
    }

    public static function blocked(
        string $datasetId,
        FreshnessState $state,
        PlanDisposition $disposition,
        int $policyVersion,
        string $reason,
        array $details = [],
    ): self {
        return new self(
            datasetId: $datasetId,
            freshnessState: $state,
            planDisposition: $disposition,
            executable: false,
            dateRange: null,
            requestedIntervals: [],
            reasons: [],
            policyVersion: $policyVersion,
            reasonSummary: $reason,
            details: $details,
        );
    }
}
