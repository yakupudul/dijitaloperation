<?php

namespace App\Support\IntelligenceEvaluation\Dto;

/**
 * Per-case retrieval metrics — never collapsed into one retrieval score.
 */
final class IntelligenceEvaluationRetrievalMetrics
{
    public function __construct(
        public readonly int $selectedCount,
        public readonly int $requiredSelectedCount,
        public readonly int $requiredTotalCount,
        public readonly int $relevantSelectedCount,
        public readonly int $relevantTotalCount,
        public readonly int $irrelevantOverfetchCount,
        public readonly int $privacyOverfetchCount,
        public readonly int $optionalSelectedCount,
        public readonly int $optionalTotalCount,
        public readonly int $contextSerializedBytes,
        public readonly bool $silentTruncationDetected,
    ) {}

    public function precision(): ?float
    {
        if ($this->selectedCount === 0) {
            return $this->relevantTotalCount === 0 ? 1.0 : null;
        }

        return $this->relevantSelectedCount / $this->selectedCount;
    }

    public function requiredRecall(): ?float
    {
        if ($this->requiredTotalCount === 0) {
            return 1.0;
        }

        return $this->requiredSelectedCount / $this->requiredTotalCount;
    }

    public function optionalRecall(): ?float
    {
        if ($this->optionalTotalCount === 0) {
            return null;
        }

        return $this->optionalSelectedCount / $this->optionalTotalCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'selected_count' => $this->selectedCount,
            'required_selected_count' => $this->requiredSelectedCount,
            'required_total_count' => $this->requiredTotalCount,
            'relevant_selected_count' => $this->relevantSelectedCount,
            'relevant_total_count' => $this->relevantTotalCount,
            'irrelevant_overfetch_count' => $this->irrelevantOverfetchCount,
            'privacy_overfetch_count' => $this->privacyOverfetchCount,
            'optional_selected_count' => $this->optionalSelectedCount,
            'optional_total_count' => $this->optionalTotalCount,
            'precision' => $this->precision(),
            'required_context_recall' => $this->requiredRecall(),
            'optional_recall' => $this->optionalRecall(),
            'context_serialized_bytes' => $this->contextSerializedBytes,
            'silent_truncation_detected' => $this->silentTruncationDetected,
            'composite_retrieval_score' => null,
        ];
    }
}
