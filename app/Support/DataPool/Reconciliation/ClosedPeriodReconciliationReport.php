<?php

namespace App\Support\DataPool\Reconciliation;

/**
 * Closed-period warehouse vs provider total comparison. Never repairs facts.
 *
 * @phpstan-type MetricRow array{
 *   metric: string,
 *   additive: bool,
 *   warehouse: float|int|null,
 *   provider: float|int|null,
 *   relative_delta: float|null,
 *   within_tolerance: bool|null,
 *   status: 'match'|'mismatch'|'unavailable'|'definition_difference',
 *   note: string
 * }
 */
final class ClosedPeriodReconciliationReport
{
    /**
     * @param  list<MetricRow>  $metrics
     * @param  list<string>  $definitionNotes
     * @param  array<string, mixed>  $scope
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $status,
        public readonly string $from,
        public readonly string $to,
        public readonly float $tolerance,
        public readonly array $scope,
        public readonly array $metrics,
        public readonly array $definitionNotes,
        public readonly bool $externalUatRequired,
        public readonly string $operatorPath,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'provider' => $this->provider,
            'status' => $this->status,
            'from' => $this->from,
            'to' => $this->to,
            'tolerance' => $this->tolerance,
            'scope' => $this->scope,
            'metrics' => $this->metrics,
            'definition_notes' => $this->definitionNotes,
            'external_uat_required' => $this->externalUatRequired,
            'operator_path' => $this->operatorPath,
        ];
    }
}
